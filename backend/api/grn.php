<?php
// =====================================================================
// TC LIMITED - Goods Received Notes (GRN) & Receiving API
// =====================================================================

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/firebase.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Process New Goods Received Note (Match PO & Allocate Shelf/Backroom)
if ($method === 'POST') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $poId = !empty($data['purchase_order_id']) ? (int)$data['purchase_order_id'] : null;
    $branchId = (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $supplierId = (int)($data['supplier_id'] ?? 0);
    $invoiceNo = trim($data['invoice_number'] ?? '');
    $notes = trim($data['notes'] ?? '');
    $items = $data['items'] ?? [];
    $userId = Auth::user()['id'] ?? 1;

    if (!$supplierId || empty($items)) {
        Auth::jsonError('Supplier and at least one receiving item are required.');
    }

    $pdo->beginTransaction();
    try {
        $grnNumber = 'GRN-' . date('Ymd') . '-' . substr((string)time(), -4);

        $stmtGRN = $pdo->prepare("
            INSERT INTO goods_received_notes 
            (grn_number, purchase_order_id, branch_id, supplier_id, invoice_number, received_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmtGRN->execute([$grnNumber, $poId, $branchId, $supplierId, $invoiceNo, $userId, $notes]);
        $grnId = $stmtGRN->fetchColumn();

        $stmtBatch = $pdo->prepare("
            INSERT INTO product_batches 
            (product_id, batch_number, manufacture_date, expiry_date, supplier_id, unit_cost, status)
            VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE')
            ON CONFLICT (product_id, batch_number)
            DO UPDATE SET expiry_date = EXCLUDED.expiry_date, unit_cost = EXCLUDED.unit_cost
            RETURNING id
        ");

        $stmtGRNItem = $pdo->prepare("
            INSERT INTO goods_received_items 
            (grn_id, product_id, batch_id, quantity_received, allocated_shelf_qty, allocated_backroom_qty, unit_cost, expiry_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmtInvUpsert = $pdo->prepare("
            INSERT INTO inventory (branch_id, location_type, product_id, batch_id, quantity, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (branch_id, location_type, product_id, batch_id)
            DO UPDATE SET quantity = inventory.quantity + EXCLUDED.quantity, updated_at = CURRENT_TIMESTAMP
        ");

        $allPoItemsFulfilled = true;

        foreach ($items as $it) {
            $pId = (int)$it['product_id'];
            $qty = (float)$it['quantity_received'];
            $shelfQty = (float)($it['allocated_shelf_qty'] ?? $qty);
            $backroomQty = (float)($it['allocated_backroom_qty'] ?? 0);
            $cost = (float)$it['unit_cost'];
            $expDate = $it['expiry_date'] ?? date('Y-m-d', strtotime('+30 days'));
            $mfgDate = $it['manufacture_date'] ?? date('Y-m-d');
            $batchNo = trim($it['batch_number'] ?? ('LOT-' . date('Ymd') . "-P{$pId}"));

            if ($qty <= 0) continue;

            // 1. Create or get Batch
            $stmtBatch->execute([$pId, $batchNo, $mfgDate, $expDate, $supplierId, $cost]);
            $batchId = $stmtBatch->fetchColumn();

            // 2. Insert GRN item
            $stmtGRNItem->execute([$grnId, $pId, $batchId, $qty, $shelfQty, $backroomQty, $cost, $expDate]);

            // 3. Update SHELF inventory & audit
            if ($shelfQty > 0) {
                // Get before qty
                $sCheck = $pdo->prepare("SELECT quantity FROM inventory WHERE branch_id = ? AND location_type = 'SHELF' AND product_id = ? AND batch_id = ?");
                $sCheck->execute([$branchId, $pId, $batchId]);
                $sBefore = (float)($sCheck->fetchColumn() ?: 0);

                $stmtInvUpsert->execute([$branchId, 'SHELF', $pId, $batchId, $shelfQty]);
                Database::logStockMovement($pdo, $branchId, $pId, $batchId, 'SHELF', 'GRN_RECEIVE', $grnNumber, $sBefore, $shelfQty, $sBefore + $shelfQty, $cost, $userId, "GRN Receipt {$grnNumber} - Shelf allocation");
            }

            // 4. Update BACKROOM inventory & audit
            if ($backroomQty > 0) {
                $bCheck = $pdo->prepare("SELECT quantity FROM inventory WHERE branch_id = ? AND location_type = 'BACKROOM' AND product_id = ? AND batch_id = ?");
                $bCheck->execute([$branchId, $pId, $batchId]);
                $bBefore = (float)($bCheck->fetchColumn() ?: 0);

                $stmtInvUpsert->execute([$branchId, 'BACKROOM', $pId, $batchId, $backroomQty]);
                Database::logStockMovement($pdo, $branchId, $pId, $batchId, 'BACKROOM', 'GRN_RECEIVE', $grnNumber, $bBefore, $backroomQty, $bBefore + $backroomQty, $cost, $userId, "GRN Receipt {$grnNumber} - Backroom storage");
            }

            // 5. Update PO items received if linked
            if ($poId) {
                $stmtPOItemUpdate = $pdo->prepare("
                    UPDATE purchase_order_items 
                    SET quantity_received = quantity_received + ?
                    WHERE purchase_order_id = ? AND product_id = ?
                ");
                $stmtPOItemUpdate->execute([$qty, $poId, $pId]);
            }
        }

        // 6. Update PO status if linked
        if ($poId) {
            $chkPO = $pdo->prepare("
                SELECT 
                    SUM(quantity_ordered) as total_ordered,
                    SUM(quantity_received) as total_received
                FROM purchase_order_items WHERE purchase_order_id = ?
            ");
            $chkPO->execute([$poId]);
            $poStats = $chkPO->fetch();
            $newPOStatus = ((float)$poStats['total_received'] >= (float)$poStats['total_ordered']) ? 'COMPLETED' : 'PARTIALLY_RECEIVED';
            
            $stmtPOStatus = $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
            $stmtPOStatus->execute([$newPOStatus, $poId]);
        }

        $pdo->commit();

        // Broadcast to Firebase
        FirebaseService::broadcast('STOCK_UPDATE', [
            'action' => 'GRN_RECEIVED',
            'grn_number' => $grnNumber,
            'branch_id' => $branchId,
            'items_received' => count($items)
        ]);

        Auth::jsonSuccess([
            'grn_id' => $grnId,
            'grn_number' => $grnNumber
        ], "Goods Received Note {$grnNumber} processed and inventory allocated!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Failed to process GRN: ' . $e->getMessage());
    }
}

// 2. Query GRN List
$branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
$stmt = $pdo->prepare("
    SELECT 
        grn.*, s.name as supplier_name, po.po_number,
        u.full_name as received_by_name,
        COUNT(gri.id) as item_count,
        SUM(gri.quantity_received) as total_units_received
    FROM goods_received_notes grn
    JOIN suppliers s ON grn.supplier_id = s.id
    LEFT JOIN purchase_orders po ON grn.purchase_order_id = po.id
    LEFT JOIN users u ON grn.received_by = u.id
    LEFT JOIN goods_received_items gri ON grn.id = gri.grn_id
    WHERE grn.branch_id = ?
    GROUP BY grn.id, s.name, po.po_number, u.full_name
    ORDER BY grn.received_date DESC
");
$stmt->execute([$branchId]);
Auth::jsonSuccess($stmt->fetchAll());
