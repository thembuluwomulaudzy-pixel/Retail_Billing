<?php
// =====================================================================
// TC LIMITED - Stock Control: Cycle Counts, Stocktakes & Variances API
// =====================================================================

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/firebase.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Start New Stocktake Session (Full or Category Cycle Count)
if ($action === 'start') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $branchId = (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $countType = $data['count_type'] ?? 'FULL_STOCKTAKE';
    $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
    $notes = trim($data['notes'] ?? '');
    $userId = Auth::user()['id'] ?? 1;

    $countNo = 'ST-' . date('Ymd') . '-' . substr((string)time(), -4);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stocktakes 
            (count_number, branch_id, count_type, category_id, status, started_by, notes)
            VALUES (?, ?, ?, ?, 'IN_PROGRESS', ?, ?)
            RETURNING id
        ");
        $stmt->execute([$countNo, $branchId, $countType, $categoryId, $userId, $notes]);
        $stocktakeId = $stmt->fetchColumn();

        // Populate stocktake_items snapshot from current inventory
        $invSql = "
            SELECT i.product_id, i.batch_id, i.location_type, i.quantity as system_quantity, pb.unit_cost
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            JOIN product_batches pb ON i.batch_id = pb.id
            WHERE i.branch_id = ?
        ";
        $params = [$branchId];
        if ($countType === 'CYCLE_COUNT_CATEGORY' && $categoryId) {
            $invSql .= " AND p.category_id = ?";
            $params[] = $categoryId;
        } elseif ($countType === 'CYCLE_COUNT_PERISHABLES') {
            $invSql .= " AND p.is_perishable = TRUE";
        }

        $invStmt = $pdo->prepare($invSql);
        $invStmt->execute($params);
        $items = $invStmt->fetchAll();

        $insItem = $pdo->prepare("
            INSERT INTO stocktake_items 
            (stocktake_id, product_id, batch_id, location_type, system_quantity, counted_quantity, variance_quantity, unit_cost, variance_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $it) {
            // Default counted quantity matches system initially until scanner records physical count
            $sysQty = (float)$it['system_quantity'];
            $cost = (float)$it['unit_cost'];
            $insItem->execute([
                $stocktakeId, $it['product_id'], $it['batch_id'], $it['location_type'],
                $sysQty, $sysQty, 0.0, $cost, 0.0
            ]);
        }

        $pdo->commit();
        Auth::jsonSuccess([
            'stocktake_id' => $stocktakeId,
            'count_number' => $countNo,
            'items_count' => count($items)
        ], "Stocktake session {$countNo} started with " . count($items) . " item lines.");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Failed to start stocktake: ' . $e->getMessage());
    }
}

// 2. Submit / Update Counted Quantity (via Barcode Scan or Manual Entry)
if ($action === 'record_count') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $stocktakeId = (int)($data['stocktake_id'] ?? 0);
    $itemId = (int)($data['stocktake_item_id'] ?? 0);
    $countedQty = (float)($data['counted_quantity'] ?? 0);
    $reason = trim($data['reason_code'] ?? 'CORRECT');

    if (!$stocktakeId || !$itemId) {
        Auth::jsonError('Stocktake ID and Item ID are required.');
    }

    $stmt = $pdo->prepare("
        SELECT * FROM stocktake_items 
        WHERE id = ? AND stocktake_id = ?
    ");
    $stmt->execute([$itemId, $stocktakeId]);
    $item = $stmt->fetch();
    if (!$item) {
        Auth::jsonError('Stocktake item line not found.');
    }

    $sysQty = (float)$item['system_quantity'];
    $varQty = round($countedQty - $sysQty, 3);
    $unitCost = (float)$item['unit_cost'];
    $varCost = round($varQty * $unitCost, 2);

    $upd = $pdo->prepare("
        UPDATE stocktake_items 
        SET counted_quantity = ?, variance_quantity = ?, variance_cost = ?, reason_code = ?
        WHERE id = ?
    ");
    $upd->execute([$countedQty, $varQty, $varCost, $reason, $itemId]);

    Auth::jsonSuccess([
        'item_id' => $itemId,
        'system_quantity' => $sysQty,
        'counted_quantity' => $countedQty,
        'variance_quantity' => $varQty,
        'variance_cost' => $varCost
    ], 'Count recorded');
}

// 3. Reconcile Variance & Adjust Inventory (Manager / Admin Approval)
if ($action === 'reconcile') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $stocktakeId = (int)($data['stocktake_id'] ?? 0);
    $userId = Auth::user()['id'] ?? 1;

    if (!$stocktakeId) {
        Auth::jsonError('Stocktake ID is required.');
    }

    $pdo->beginTransaction();
    try {
        $stStmt = $pdo->prepare("SELECT * FROM stocktakes WHERE id = ? FOR UPDATE");
        $stStmt->execute([$stocktakeId]);
        $st = $stStmt->fetch();

        if (!$st) {
            $pdo->rollBack();
            Auth::jsonError('Stocktake record not found.');
        }
        if ($st['status'] === 'RECONCILED') {
            $pdo->rollBack();
            Auth::jsonError('This stocktake has already been reconciled.');
        }

        // Get variance items
        $itemsStmt = $pdo->prepare("
            SELECT * FROM stocktake_items 
            WHERE stocktake_id = ? AND variance_quantity != 0 AND adjustment_applied = FALSE
        ");
        $itemsStmt->execute([$stocktakeId]);
        $varianceItems = $itemsStmt->fetchAll();

        $totalVarianceCost = 0.0;
        foreach ($varianceItems as $vi) {
            $pId = (int)$vi['product_id'];
            $bId = (int)$vi['batch_id'];
            $loc = $vi['location_type'];
            $varQty = (float)$vi['variance_quantity'];
            $countedQty = (float)$vi['counted_quantity'];
            $cost = (float)$vi['unit_cost'];
            $totalVarianceCost += (float)$vi['variance_cost'];

            // Update physical inventory to counted quantity
            $updInv = $pdo->prepare("
                UPDATE inventory 
                SET quantity = ?, last_counted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = ?
            ");
            $updInv->execute([$countedQty, $st['branch_id'], $pId, $bId, $loc]);

            // Audit movement
            Database::logStockMovement(
                $pdo, $st['branch_id'], $pId, $bId, $loc, 'STOCKTAKE_ADJUST',
                $st['count_number'], (float)$vi['system_quantity'], $varQty, $countedQty,
                $cost, $userId, "Stocktake Variance Reconciled ({$vi['reason_code']})"
            );

            // Mark line as applied
            $updLine = $pdo->prepare("UPDATE stocktake_items SET adjustment_applied = TRUE WHERE id = ?");
            $updLine->execute([$vi['id']]);
        }

        // Update stocktake header
        $updST = $pdo->prepare("
            UPDATE stocktakes 
            SET status = 'RECONCILED', reconciled_by = ?, reconciled_at = CURRENT_TIMESTAMP, total_variance_cost = ?
            WHERE id = ?
        ");
        $updST->execute([$userId, $totalVarianceCost, $stocktakeId]);

        $pdo->commit();

        FirebaseService::broadcast('STOCK_UPDATE', [
            'action' => 'STOCKTAKE_RECONCILED',
            'stocktake_number' => $st['count_number'],
            'total_variance_cost' => $totalVarianceCost
        ]);

        Auth::jsonSuccess([
            'stocktake_id' => $stocktakeId,
            'adjusted_lines' => count($varianceItems),
            'total_variance_cost' => $totalVarianceCost
        ], "Stocktake {$st['count_number']} reconciled! Inventory adjusted for " . count($varianceItems) . " items.");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Reconciliation failed: ' . $e->getMessage());
    }
}

// 4. Get Stocktake Details & Variance Report
if (!empty($_GET['stocktake_id'])) {
    $stocktakeId = (int)$_GET['stocktake_id'];
    $stmtHeader = $pdo->prepare("
        SELECT st.*, b.name as branch_name, u1.full_name as started_by_name, u2.full_name as reconciled_by_name
        FROM stocktakes st
        JOIN branches b ON st.branch_id = b.id
        LEFT JOIN users u1 ON st.started_by = u1.id
        LEFT JOIN users u2 ON st.reconciled_by = u2.id
        WHERE st.id = ?
    ");
    $stmtHeader->execute([$stocktakeId]);
    $header = $stmtHeader->fetch();

    $stmtItems = $pdo->prepare("
        SELECT 
            sti.*, p.name as product_name, p.sku, p.barcode, p.uom,
            pb.batch_number, pb.expiry_date
        FROM stocktake_items sti
        JOIN products p ON sti.product_id = p.id
        JOIN product_batches pb ON sti.batch_id = pb.id
        WHERE sti.stocktake_id = ?
        ORDER BY ABS(sti.variance_quantity) DESC, p.name ASC
    ");
    $stmtItems->execute([$stocktakeId]);
    $items = $stmtItems->fetchAll();

    Auth::jsonSuccess([
        'stocktake' => $header,
        'items' => $items
    ]);
}

// 5. Query Recent Stocktake Sessions
$branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
$stmt = $pdo->prepare("
    SELECT 
        st.*, u.full_name as started_by_name,
        COUNT(sti.id) as item_count,
        SUM(CASE WHEN sti.variance_quantity != 0 THEN 1 ELSE 0 END) as variance_count
    FROM stocktakes st
    LEFT JOIN users u ON st.started_by = u.id
    LEFT JOIN stocktake_items sti ON st.id = sti.stocktake_id
    WHERE st.branch_id = ?
    GROUP BY st.id, u.full_name
    ORDER BY st.started_at DESC
");
$stmt->execute([$branchId]);
Auth::jsonSuccess($stmt->fetchAll());
