<?php
// =====================================================================
// TC LIMITED - Inter-Branch Transfers API
// =====================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/firebase.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Create Transfer Request
if ($action === 'create' || ($method === 'POST' && empty($action))) {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $fromBranch = (int)($data['from_branch_id'] ?? 1);
    $toBranch = (int)($data['to_branch_id'] ?? 2);
    $notes = trim($data['notes'] ?? '');
    $items = $data['items'] ?? [];
    $userId = Auth::user()['id'] ?? 1;

    if ($fromBranch === $toBranch) {
        Auth::jsonError('Source and Destination branch cannot be the same.');
    }
    if (empty($items)) {
        Auth::jsonError('Please select at least one item to transfer.');
    }

    $pdo->beginTransaction();
    try {
        $trfNo = 'TRF-' . date('Ymd') . '-' . substr((string)time(), -4);

        $stmt = $pdo->prepare("
            INSERT INTO branch_transfers 
            (transfer_number, from_branch_id, to_branch_id, status, requested_by, notes)
            VALUES (?, ?, ?, 'PENDING', ?, ?)
            RETURNING id
        ");
        $stmt->execute([$trfNo, $fromBranch, $toBranch, $userId, $notes]);
        $trfId = $stmt->fetchColumn();

        $stmtItem = $pdo->prepare("
            INSERT INTO branch_transfer_items 
            (transfer_id, product_id, batch_id, quantity_requested)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($items as $it) {
            $stmtItem->execute([$trfId, (int)$it['product_id'], (int)$it['batch_id'], (float)$it['quantity']]);
        }

        $pdo->commit();
        Auth::jsonSuccess(['transfer_id' => $trfId, 'transfer_number' => $trfNo], "Transfer request {$trfNo} submitted!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Failed to create transfer: ' . $e->getMessage());
    }
}

// 2. Dispatch Transfer (Decrements source branch inventory)
if ($action === 'dispatch') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $trfId = (int)($data['transfer_id'] ?? 0);
    $userId = Auth::user()['id'] ?? 1;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM branch_transfers WHERE id = ? FOR UPDATE");
        $stmt->execute([$trfId]);
        $trf = $stmt->fetch();
        if (!$trf || in_array($trf['status'], ['DISPATCHED', 'RECEIVED', 'CANCELLED'], true)) {
            $pdo->rollBack();
            Auth::jsonError('Transfer cannot be dispatched in current state.');
        }

        $itemsStmt = $pdo->prepare("SELECT * FROM branch_transfer_items WHERE transfer_id = ?");
        $itemsStmt->execute([$trfId]);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $it) {
            $pId = (int)$it['product_id'];
            $bId = (int)$it['batch_id'];
            $qty = (float)$it['quantity_requested'];

            // Check stock in BACKROOM of from_branch
            $chk = $pdo->prepare("SELECT quantity FROM inventory WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'BACKROOM' FOR UPDATE");
            $chk->execute([$trf['from_branch_id'], $pId, $bId]);
            $curQty = (float)($chk->fetchColumn() ?: 0);

            if ($curQty < $qty) {
                // If not enough in backroom, check shelf
                $chkShelf = $pdo->prepare("SELECT quantity FROM inventory WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'SHELF' FOR UPDATE");
                $chkShelf->execute([$trf['from_branch_id'], $pId, $bId]);
                $shelfQty = (float)($chkShelf->fetchColumn() ?: 0);

                if (($curQty + $shelfQty) < $qty) {
                    $pdo->rollBack();
                    Auth::jsonError("Insufficient source inventory for Product #{$pId} Batch #{$bId}.");
                }
            }

            // Deduct from BACKROOM first, then SHELF if needed
            $takeBackroom = min($curQty, $qty);
            $takeShelf = $qty - $takeBackroom;

            if ($takeBackroom > 0) {
                $upd = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'BACKROOM'");
                $upd->execute([$takeBackroom, $trf['from_branch_id'], $pId, $bId]);
            }
            if ($takeShelf > 0) {
                $upd = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'SHELF'");
                $upd->execute([$takeShelf, $trf['from_branch_id'], $pId, $bId]);
            }

            // Get unit cost
            $costStmt = $pdo->prepare("SELECT unit_cost FROM product_batches WHERE id = ?");
            $costStmt->execute([$bId]);
            $unitCost = (float)$costStmt->fetchColumn();

            // Audit
            Database::logStockMovement(
                $pdo, $trf['from_branch_id'], $pId, $bId, 'BACKROOM', 'TRANSFER_OUT',
                $trf['transfer_number'], $curQty, -$qty, $curQty - $qty, $unitCost, $userId,
                "Transfer dispatched to Branch #{$trf['to_branch_id']}"
            );

            // Update item dispatched qty
            $updItem = $pdo->prepare("UPDATE branch_transfer_items SET quantity_dispatched = ? WHERE id = ?");
            $updItem->execute([$qty, $it['id']]);
        }

        // Update transfer status
        $updTrf = $pdo->prepare("
            UPDATE branch_transfers 
            SET status = 'DISPATCHED', approved_by = ?, dispatched_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $updTrf->execute([$userId, $trfId]);

        $pdo->commit();
        Auth::jsonSuccess(null, "Transfer {$trf['transfer_number']} dispatched!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Dispatch failed: ' . $e->getMessage());
    }
}

// 3. Receive Transfer (Increments destination branch inventory)
if ($action === 'receive') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $trfId = (int)($data['transfer_id'] ?? 0);
    $userId = Auth::user()['id'] ?? 1;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM branch_transfers WHERE id = ? FOR UPDATE");
        $stmt->execute([$trfId]);
        $trf = $stmt->fetch();
        if (!$trf || $trf['status'] !== 'DISPATCHED') {
            $pdo->rollBack();
            Auth::jsonError('Transfer must be in DISPATCHED status to receive.');
        }

        $itemsStmt = $pdo->prepare("SELECT * FROM branch_transfer_items WHERE transfer_id = ?");
        $itemsStmt->execute([$trfId]);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $it) {
            $pId = (int)$it['product_id'];
            $bId = (int)$it['batch_id'];
            $qty = (float)$it['quantity_dispatched'];

            // Upsert into BACKROOM of to_branch
            $curStmt = $pdo->prepare("SELECT quantity FROM inventory WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'BACKROOM'");
            $curStmt->execute([$trf['to_branch_id'], $pId, $bId]);
            $curQty = (float)($curStmt->fetchColumn() ?: 0);

            $ins = $pdo->prepare("
                INSERT INTO inventory (branch_id, location_type, product_id, batch_id, quantity, updated_at)
                VALUES (?, 'BACKROOM', ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT (branch_id, location_type, product_id, batch_id)
                DO UPDATE SET quantity = inventory.quantity + EXCLUDED.quantity, updated_at = CURRENT_TIMESTAMP
            ");
            $ins->execute([$trf['to_branch_id'], $pId, $bId, $qty]);

            $costStmt = $pdo->prepare("SELECT unit_cost FROM product_batches WHERE id = ?");
            $costStmt->execute([$bId]);
            $unitCost = (float)$costStmt->fetchColumn();

            Database::logStockMovement(
                $pdo, $trf['to_branch_id'], $pId, $bId, 'BACKROOM', 'TRANSFER_IN',
                $trf['transfer_number'], $curQty, $qty, $curQty + $qty, $unitCost, $userId,
                "Transfer received from Branch #{$trf['from_branch_id']}"
            );

            $updItem = $pdo->prepare("UPDATE branch_transfer_items SET quantity_received = ? WHERE id = ?");
            $updItem->execute([$qty, $it['id']]);
        }

        $updTrf = $pdo->prepare("
            UPDATE branch_transfers 
            SET status = 'RECEIVED', received_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $updTrf->execute([$trfId]);

        $pdo->commit();
        Auth::jsonSuccess(null, "Transfer {$trf['transfer_number']} received into backroom storage!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Receive failed: ' . $e->getMessage());
    }
}

// 4. Query Transfers
$stmt = $pdo->query("
    SELECT 
        bt.*, b1.name as from_branch_name, b2.name as to_branch_name,
        u.full_name as requested_by_name,
        COUNT(bti.id) as item_count
    FROM branch_transfers bt
    JOIN branches b1 ON bt.from_branch_id = b1.id
    JOIN branches b2 ON bt.to_branch_id = b2.id
    LEFT JOIN users u ON bt.requested_by = u.id
    LEFT JOIN branch_transfer_items bti ON bt.id = bti.transfer_id
    GROUP BY bt.id, b1.name, b2.name, u.full_name
    ORDER BY bt.created_at DESC
");
Auth::jsonSuccess($stmt->fetchAll());
