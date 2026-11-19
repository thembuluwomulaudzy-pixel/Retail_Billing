<?php
// =====================================================================
// TC LIMITED - Expiry & Perishables Management (FEFO & Markdowns)
// =====================================================================

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/firebase.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. FEFO Picking Algorithm helper
if ($action === 'fefo_pick') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $neededQty = (float)($_GET['quantity'] ?? 1);

    if (!$productId) {
        Auth::jsonError('Product ID is required.');
    }

    // Query batches ordered strictly by EXPIRY DATE ASC (First-Expired, First-Out)
    $stmt = $pdo->prepare("
        SELECT 
            pb.id as batch_id, pb.batch_number, pb.expiry_date, pb.unit_cost,
            i.location_type, i.quantity as available_qty,
            (pb.expiry_date - CURRENT_DATE) as days_to_expiry
        FROM inventory i
        JOIN product_batches pb ON i.batch_id = pb.id
        WHERE i.branch_id = ? AND i.product_id = ? AND i.quantity > 0
        ORDER BY pb.expiry_date ASC, (CASE WHEN i.location_type = 'SHELF' THEN 0 ELSE 1 END) ASC
    ");
    $stmt->execute([$branchId, $productId]);
    $batches = $stmt->fetchAll();

    $allocations = [];
    $remainingNeeded = $neededQty;

    foreach ($batches as $b) {
        if ($remainingNeeded <= 0) break;
        $take = min((float)$b['available_qty'], $remainingNeeded);
        $allocations[] = [
            'batch_id' => (int)$b['batch_id'],
            'batch_number' => $b['batch_number'],
            'expiry_date' => $b['expiry_date'],
            'days_to_expiry' => (int)$b['days_to_expiry'],
            'location_type' => $b['location_type'],
            'unit_cost' => (float)$b['unit_cost'],
            'allocated_qty' => $take
        ];
        $remainingNeeded -= $take;
    }

    Auth::jsonSuccess([
        'product_id' => $productId,
        'requested_qty' => $neededQty,
        'satisfied_qty' => $neededQty - $remainingNeeded,
        'is_fully_satisfied' => ($remainingNeeded <= 0),
        'allocations' => $allocations
    ], 'FEFO picking allocation calculated');
}

// 2. Near-Expiry & Expired Alerts with Suggested Markdowns
if ($action === 'alerts' || $action === '') {
    $branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $daysThreshold = (int)($_GET['days'] ?? 7);

    $stmt = $pdo->prepare("
        SELECT 
            p.id as product_id, p.sku, p.barcode, p.name as product_name, p.selling_price, p.cost_price,
            c.name as category_name,
            pb.id as batch_id, pb.batch_number, pb.expiry_date,
            (pb.expiry_date - CURRENT_DATE) as days_remaining,
            COALESCE(SUM(CASE WHEN i.location_type = 'SHELF' THEN i.quantity ELSE 0 END), 0) as shelf_quantity,
            COALESCE(SUM(CASE WHEN i.location_type = 'BACKROOM' THEN i.quantity ELSE 0 END), 0) as backroom_quantity,
            COALESCE(SUM(i.quantity), 0) as total_quantity,
            pr.id as active_promo_id, pr.discount_percent as current_discount
        FROM product_batches pb
        JOIN products p ON pb.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        JOIN inventory i ON pb.id = i.batch_id AND i.branch_id = ?
        LEFT JOIN promotions pr ON pb.id = pr.batch_id AND pr.is_active = TRUE AND CURRENT_DATE BETWEEN pr.start_date AND pr.end_date
        WHERE i.quantity > 0 AND (pb.expiry_date - CURRENT_DATE) <= ?
        GROUP BY p.id, p.sku, p.barcode, p.name, p.selling_price, p.cost_price, c.name, pb.id, pb.batch_number, pb.expiry_date, pr.id, pr.discount_percent
        ORDER BY days_remaining ASC, total_quantity DESC
    ");
    $stmt->execute([$branchId, $daysThreshold]);
    $items = $stmt->fetchAll();

    // Calculate smart suggested markdown percentage & estimated recovery
    foreach ($items as &$item) {
        $days = (int)$item['days_remaining'];
        $price = (float)$item['selling_price'];
        $cost = (float)$item['cost_price'];

        if ($days < 0) {
            $item['urgency'] = 'EXPIRED';
            $item['suggested_markdown_pct'] = 0; // Must be spoiled/logged to wastage
            $item['action_recommendation'] = 'Immediate Wastage Quarantine & Disposal';
            $item['suggested_promo_price'] = 0;
        } elseif ($days <= 2) {
            $item['urgency'] = 'CRITICAL';
            $item['suggested_markdown_pct'] = 50.0; // Flash clearance 50%
            $item['action_recommendation'] = 'Flash 50% Off Shelf Clearance';
            $item['suggested_promo_price'] = round($price * 0.50, 2);
        } elseif ($days <= 4) {
            $item['urgency'] = 'HIGH';
            $item['suggested_markdown_pct'] = 30.0; // 30% markdown
            $item['action_recommendation'] = '30% Markdown Promotion';
            $item['suggested_promo_price'] = round($price * 0.70, 2);
        } else {
            $item['urgency'] = 'MEDIUM';
            $item['suggested_markdown_pct'] = 15.0; // 15% markdown
            $item['action_recommendation'] = '15% Off Early Bird Saver';
            $item['suggested_promo_price'] = round($price * 0.85, 2);
        }
    }

    Auth::jsonSuccess($items);
}

// 3. Apply Suggested Markdown Promotion
if ($action === 'apply_markdown') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $productId = (int)($data['product_id'] ?? 0);
    $batchId = (int)($data['batch_id'] ?? 0);
    $discountPct = (float)($data['discount_percent'] ?? 25.0);
    $title = trim($data['title'] ?? 'Near-Expiry Quick Markdown');
    $days = (int)($data['duration_days'] ?? 3);

    if (!$productId || !$batchId || $discountPct <= 0) {
        Auth::jsonError('Product, Batch and valid discount percentage are required.');
    }

    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$days} days"));

    $stmt = $pdo->prepare("
        INSERT INTO promotions 
        (product_id, batch_id, title, discount_percent, start_date, end_date, is_active, reason)
        VALUES (?, ?, ?, ?, ?, ?, TRUE, 'NEAR_EXPIRY')
        RETURNING id
    ");
    $stmt->execute([$productId, $batchId, $title, $discountPct, $startDate, $endDate]);
    $promoId = $stmt->fetchColumn();

    // Broadcast to Firebase so POS terminals update immediately!
    FirebaseService::broadcast('EXPIRY_ALERT', [
        'action' => 'MARKDOWN_APPLIED',
        'promotion_id' => $promoId,
        'product_id' => $productId,
        'batch_id' => $batchId,
        'discount_percent' => $discountPct,
        'title' => $title
    ]);

    Auth::jsonSuccess(['promo_id' => $promoId], "Promotional markdown of {$discountPct}% applied until {$endDate}!");
}

// 4. Wastage & Spoilage Logging
if ($action === 'log_wastage') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $branchId = (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $productId = (int)($data['product_id'] ?? 0);
    $batchId = (int)($data['batch_id'] ?? 0);
    $locationType = ($data['location_type'] ?? 'SHELF') === 'BACKROOM' ? 'BACKROOM' : 'SHELF';
    $qty = (float)($data['quantity'] ?? 0);
    $reasonCode = strtoupper(trim($data['reason_code'] ?? 'EXPIRED'));
    $notes = trim($data['notes'] ?? '');
    $userId = Auth::user()['id'] ?? 1;

    $validReasons = ['EXPIRED', 'SPOILED_ROTTEN', 'DAMAGED_DROPPED', 'TEMP_FAILURE', 'PEST_DAMAGE', 'THEFT_PILFERAGE'];
    if (!in_array($reasonCode, $validReasons, true)) {
        Auth::jsonError('Invalid reason code. Must be one of: ' . implode(', ', $validReasons));
    }

    if (!$productId || !$batchId || $qty <= 0) {
        Auth::jsonError('Product, Batch, and positive quantity are required.');
    }

    $pdo->beginTransaction();
    try {
        // Check current stock
        $stmtChk = $pdo->prepare("
            SELECT quantity FROM inventory 
            WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = ?
            FOR UPDATE
        ");
        $stmtChk->execute([$branchId, $productId, $batchId, $locationType]);
        $currentStock = (float)($stmtChk->fetchColumn() ?: 0);

        if ($currentStock < $qty) {
            $pdo->rollBack();
            Auth::jsonError("Cannot waste {$qty}. Current stock in {$locationType} is only {$currentStock}.");
        }

        // Deduct inventory
        $stmtDec = $pdo->prepare("
            UPDATE inventory 
            SET quantity = quantity - ?, updated_at = CURRENT_TIMESTAMP
            WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = ?
        ");
        $stmtDec->execute([$qty, $branchId, $productId, $batchId, $locationType]);

        // Get unit cost
        $costStmt = $pdo->prepare("SELECT unit_cost FROM product_batches WHERE id = ?");
        $costStmt->execute([$batchId]);
        $unitCost = (float)$costStmt->fetchColumn();
        $totalLoss = round($unitCost * $qty, 2);

        // Record in wastage_logs
        $stmtWaste = $pdo->prepare("
            INSERT INTO wastage_logs 
            (branch_id, product_id, batch_id, location_type, quantity, cost_loss, reason_code, logged_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmtWaste->execute([$branchId, $productId, $batchId, $locationType, $qty, $totalLoss, $reasonCode, $userId, $notes]);
        $wastageId = $stmtWaste->fetchColumn();

        // Audit Trail
        Database::logStockMovement(
            $pdo, $branchId, $productId, $batchId, $locationType, 'WASTAGE_LOG',
            "WASTE-{$wastageId}", $currentStock, -$qty, $currentStock - $qty, $unitCost, $userId,
            "Wastage: {$reasonCode}. Note: {$notes}"
        );

        $pdo->commit();

        // Broadcast to Firebase
        FirebaseService::broadcast('WASTAGE_LOG', [
            'wastage_id' => $wastageId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'batch_id' => $batchId,
            'quantity' => $qty,
            'reason_code' => $reasonCode,
            'cost_loss' => $totalLoss
        ]);

        Auth::jsonSuccess([
            'wastage_id' => $wastageId,
            'cost_loss' => $totalLoss,
            'new_stock' => $currentStock - $qty
        ], "Logged {$qty} units as wastage ({$reasonCode}). Financial loss: \${$totalLoss}");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Failed to log wastage: ' . $e->getMessage());
    }
}

// 5. Query Wastage History & Reasons
if ($action === 'wastage_history') {
    $branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $stmt = $pdo->prepare("
        SELECT 
            w.*, p.name as product_name, p.sku, p.uom,
            pb.batch_number, pb.expiry_date,
            u.full_name as logged_by_name
        FROM wastage_logs w
        JOIN products p ON w.product_id = p.id
        JOIN product_batches pb ON w.batch_id = pb.id
        LEFT JOIN users u ON w.logged_by = u.id
        WHERE w.branch_id = ?
        ORDER BY w.logged_at DESC
        LIMIT 50
    ");
    $stmt->execute([$branchId]);
    Auth::jsonSuccess($stmt->fetchAll());
}
