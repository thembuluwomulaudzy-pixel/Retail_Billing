<?php
// =====================================================================
// TC LIMITED - Real-Time Stock Tracking & Shelf Replenishment API
// =====================================================================

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/firebase.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Multi-branch comparison matrix
if ($action === 'matrix') {
    $stmt = $pdo->query("
        SELECT 
            p.id as product_id, p.sku, p.barcode, p.name, p.uom, p.reorder_point,
            c.name as category_name,
            COALESCE(SUM(CASE WHEN i.branch_id = 1 AND i.location_type = 'SHELF' THEN i.quantity ELSE 0 END), 0) as br1_shelf,
            COALESCE(SUM(CASE WHEN i.branch_id = 1 AND i.location_type = 'BACKROOM' THEN i.quantity ELSE 0 END), 0) as br1_backroom,
            COALESCE(SUM(CASE WHEN i.branch_id = 1 THEN i.quantity ELSE 0 END), 0) as br1_total,
            COALESCE(SUM(CASE WHEN i.branch_id = 2 AND i.location_type = 'SHELF' THEN i.quantity ELSE 0 END), 0) as br2_shelf,
            COALESCE(SUM(CASE WHEN i.branch_id = 2 AND i.location_type = 'BACKROOM' THEN i.quantity ELSE 0 END), 0) as br2_backroom,
            COALESCE(SUM(CASE WHEN i.branch_id = 2 THEN i.quantity ELSE 0 END), 0) as br2_total,
            COALESCE(SUM(CASE WHEN i.branch_id = 3 THEN i.quantity ELSE 0 END), 0) as br3_total,
            COALESCE(SUM(i.quantity), 0) as company_total
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE p.is_active = TRUE
        GROUP BY p.id, p.sku, p.barcode, p.name, p.uom, p.reorder_point, c.name
        ORDER BY p.name ASC
    ");
    Auth::jsonSuccess($stmt->fetchAll());
}

// 2. Batch Breakdown for a specific product
if ($action === 'batches') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);

    if (!$productId) {
        Auth::jsonError('Product ID is required.');
    }

    $stmt = $pdo->prepare("
        SELECT 
            pb.id as batch_id, pb.batch_number, pb.manufacture_date, pb.expiry_date,
            pb.unit_cost, pb.status as batch_status,
            s.name as supplier_name,
            COALESCE(SUM(CASE WHEN i.location_type = 'SHELF' THEN i.quantity ELSE 0 END), 0) as shelf_quantity,
            COALESCE(SUM(CASE WHEN i.location_type = 'BACKROOM' THEN i.quantity ELSE 0 END), 0) as backroom_quantity,
            COALESCE(SUM(i.quantity), 0) as total_quantity,
            (pb.expiry_date - CURRENT_DATE) as days_to_expiry
        FROM product_batches pb
        LEFT JOIN suppliers s ON pb.supplier_id = s.id
        LEFT JOIN inventory i ON pb.id = i.batch_id AND i.branch_id = ?
        WHERE pb.product_id = ?
        GROUP BY pb.id, pb.batch_number, pb.manufacture_date, pb.expiry_date, pb.unit_cost, pb.status, s.name
        ORDER BY pb.expiry_date ASC
    ");
    $stmt->execute([$branchId, $productId]);
    Auth::jsonSuccess($stmt->fetchAll());
}

// 3. Shelf Replenishment (Move stock from Backroom to Shelf)
if ($action === 'replenish') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $branchId = (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $productId = (int)($data['product_id'] ?? 0);
    $batchId = (int)($data['batch_id'] ?? 0);
    $qty = (float)($data['quantity'] ?? 0);
    $userId = Auth::user()['id'] ?? 1;

    if (!$productId || !$batchId || $qty <= 0) {
        Auth::jsonError('Invalid replenishment details. Product, Batch, and positive Quantity are required.');
    }

    $pdo->beginTransaction();
    try {
        // Check backroom inventory
        $stmtChk = $pdo->prepare("
            SELECT quantity FROM inventory 
            WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'BACKROOM'
            FOR UPDATE
        ");
        $stmtChk->execute([$branchId, $productId, $batchId]);
        $currentBackroom = (float)($stmtChk->fetchColumn() ?: 0);

        if ($currentBackroom < $qty) {
            $pdo->rollBack();
            Auth::jsonError("Insufficient backroom stock. Available: {$currentBackroom}, Requested: {$qty}");
        }

        // Deduct from BACKROOM
        $stmtDec = $pdo->prepare("
            UPDATE inventory 
            SET quantity = quantity - ?, updated_at = CURRENT_TIMESTAMP
            WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'BACKROOM'
        ");
        $stmtDec->execute([$qty, $branchId, $productId, $batchId]);

        // Add to SHELF (UPSERT)
        $stmtInc = $pdo->prepare("
            INSERT INTO inventory (branch_id, location_type, product_id, batch_id, quantity, updated_at)
            VALUES (?, 'SHELF', ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (branch_id, location_type, product_id, batch_id)
            DO UPDATE SET quantity = inventory.quantity + EXCLUDED.quantity, updated_at = CURRENT_TIMESTAMP
        ");
        $stmtInc->execute([$branchId, $productId, $batchId, $qty]);

        // Get unit cost for audit
        $costStmt = $pdo->prepare("SELECT unit_cost FROM product_batches WHERE id = ?");
        $costStmt->execute([$batchId]);
        $unitCost = (float)$costStmt->fetchColumn();

        // Audit Trail
        Database::logStockMovement(
            $pdo, $branchId, $productId, $batchId, 'BACKROOM', 'SHELF_REPLENISH',
            'REPLENISH', $currentBackroom, -$qty, $currentBackroom - $qty, $unitCost, $userId,
            "Replenished {$qty} units from Backroom to Sales Shelf"
        );

        $pdo->commit();

        // Broadcast to Firebase
        FirebaseService::broadcast('STOCK_UPDATE', [
            'action' => 'SHELF_REPLENISH',
            'product_id' => $productId,
            'batch_id' => $batchId,
            'branch_id' => $branchId,
            'quantity' => $qty
        ]);

        Auth::jsonSuccess(null, "Successfully replenished {$qty} units to the sales shelf!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Replenishment failed: ' . $e->getMessage());
    }
}

// 4. Default: Real-time stock summary for active branch
$branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;

$sql = "
    SELECT 
        p.id as product_id, p.sku, p.barcode, p.name, p.uom, p.cost_price, p.selling_price,
        p.reorder_point, p.min_stock_level, p.max_stock_level, p.is_perishable,
        c.name as category_name,
        COALESCE(SUM(CASE WHEN i.location_type = 'SHELF' THEN i.quantity ELSE 0 END), 0) as shelf_quantity,
        COALESCE(SUM(CASE WHEN i.location_type = 'BACKROOM' THEN i.quantity ELSE 0 END), 0) as backroom_quantity,
        COALESCE(SUM(i.quantity), 0) as total_quantity,
        COUNT(DISTINCT pb.id) as batch_count,
        MIN(pb.expiry_date) as earliest_expiry
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
    LEFT JOIN product_batches pb ON i.batch_id = pb.id AND i.quantity > 0
    WHERE p.is_active = TRUE
";
$params = [$branchId];

if ($categoryId) {
    $sql .= " AND p.category_id = ?";
    $params[] = $categoryId;
}

$sql .= " GROUP BY p.id, c.name ORDER BY p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

Auth::jsonSuccess($rows);
