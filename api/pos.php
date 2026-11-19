<?php
// =====================================================================
// TC LIMITED - Point of Sale (POS) Integration & Sales Checkout API
// =====================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/firebase.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Process POS Checkout (FEFO Batch Decrement)
if ($action === 'checkout' || ($method === 'POST' && empty($action))) {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_CASHIER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $branchId = (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $cashierId = Auth::user()['id'] ?? 4;
    $items = $data['items'] ?? [];
    $paymentMethod = $data['payment_method'] ?? 'CASH';
    $amountTendered = (float)($data['amount_tendered'] ?? 0);

    if (empty($items)) {
        Auth::jsonError('Cannot process checkout with empty cart.');
    }

    $pdo->beginTransaction();
    try {
        $receiptNumber = 'REC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalTax = 0.0;
        $processedLines = [];

        // Pre-validate and process each product using FEFO
        foreach ($items as $cartItem) {
            $productId = (int)$cartItem['product_id'];
            $requestedQty = (float)$cartItem['quantity'];

            if ($requestedQty <= 0) continue;

            // Fetch product info & tax rate
            $pStmt = $pdo->prepare("
                SELECT p.*, c.default_tax_rate 
                FROM products p 
                JOIN categories c ON p.category_id = c.id 
                WHERE p.id = ? AND p.is_active = TRUE
            ");
            $pStmt->execute([$productId]);
            $product = $pStmt->fetch();
            if (!$product) {
                $pdo->rollBack();
                Auth::jsonError("Product #{$productId} not found or inactive.");
            }

            // Check active promotion
            $promoStmt = $pdo->prepare("
                SELECT * FROM promotions 
                WHERE product_id = ? AND is_active = TRUE 
                  AND CURRENT_DATE BETWEEN start_date AND end_date
                ORDER BY discount_percent DESC LIMIT 1
            ");
            $promoStmt->execute([$productId]);
            $promo = $promoStmt->fetch();

            $basePrice = (float)$product['selling_price'];
            $unitDiscount = 0.0;
            if ($promo) {
                if ($promo['discount_percent'] > 0) {
                    $unitDiscount = round($basePrice * ($promo['discount_percent'] / 100), 2);
                } elseif ($promo['discount_amount'] > 0) {
                    $unitDiscount = min($basePrice, (float)$promo['discount_amount']);
                }
            }
            $finalUnitPrice = $basePrice - $unitDiscount;

            // FEFO Stock Allocation: prioritize SHELF first, ordered by earliest EXPIRY DATE
            $batchStmt = $pdo->prepare("
                SELECT 
                    i.id as inv_id, i.branch_id, i.location_type, i.batch_id, i.quantity,
                    pb.batch_number, pb.expiry_date, pb.unit_cost
                FROM inventory i
                JOIN product_batches pb ON i.batch_id = pb.id
                WHERE i.branch_id = ? AND i.product_id = ? AND i.quantity > 0
                ORDER BY 
                    (CASE WHEN i.location_type = 'SHELF' THEN 0 ELSE 1 END) ASC,
                    pb.expiry_date ASC
                FOR UPDATE
            ");
            $batchStmt->execute([$branchId, $productId]);
            $availableBatches = $batchStmt->fetchAll();

            $totalAvailable = array_sum(array_column($availableBatches, 'quantity'));
            if ($totalAvailable < $requestedQty) {
                $pdo->rollBack();
                Auth::jsonError("Insufficient stock for '{$product['name']}'. In Stock: {$totalAvailable}, Required: {$requestedQty}");
            }

            // Decrement stock across batches
            $qtyToFulfill = $requestedQty;
            foreach ($availableBatches as $batchRow) {
                if ($qtyToFulfill <= 0) break;
                $take = min((float)$batchRow['quantity'], $qtyToFulfill);
                $batchId = (int)$batchRow['batch_id'];
                $locType = $batchRow['location_type'];
                $unitCost = (float)$batchRow['unit_cost'];

                // Update inventory
                $updStmt = $pdo->prepare("
                    UPDATE inventory 
                    SET quantity = quantity - ?, updated_at = CURRENT_TIMESTAMP
                    WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = ?
                ");
                $updStmt->execute([$take, $branchId, $productId, $batchId, $locType]);

                // Audit Log Movement
                Database::logStockMovement(
                    $pdo, $branchId, $productId, $batchId, $locType, 'POS_SALE',
                    $receiptNumber, (float)$batchRow['quantity'], -$take, (float)$batchRow['quantity'] - $take,
                    $unitCost, $cashierId, "POS Sale {$receiptNumber} (FEFO decrement)"
                );

                $lineSubtotal = round($take * $basePrice, 2);
                $lineDiscount = round($take * $unitDiscount, 2);
                $lineNet = $lineSubtotal - $lineDiscount;
                $lineTax = round($lineNet * ((float)$product['default_tax_rate'] / 100), 2);

                $subtotal += $lineSubtotal;
                $totalDiscount += $lineDiscount;
                $totalTax += $lineTax;

                $processedLines[] = [
                    'product_id' => $productId,
                    'batch_id' => $batchId,
                    'location_type' => $locType,
                    'quantity' => $take,
                    'unit_price' => $basePrice,
                    'unit_cost' => $unitCost,
                    'discount_amount' => $lineDiscount,
                    'line_total' => $lineNet
                ];

                $qtyToFulfill -= $take;
            }
        }

        $totalAmount = round($subtotal - $totalDiscount + $totalTax, 2);
        if ($paymentMethod === 'CASH' && $amountTendered < $totalAmount) {
            $amountTendered = $totalAmount; // Fallback exact cash
        }
        $changeDue = max(0, round($amountTendered - $totalAmount, 2));

        // Create Sale Record
        $saleStmt = $pdo->prepare("
            INSERT INTO sales 
            (receipt_number, branch_id, cashier_id, subtotal, discount_amount, tax_amount, total_amount, payment_method, amount_tendered, change_due, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED')
            RETURNING id
        ");
        $saleStmt->execute([
            $receiptNumber, $branchId, $cashierId, $subtotal, $totalDiscount, $totalTax,
            $totalAmount, $paymentMethod, $amountTendered, $changeDue
        ]);
        $saleId = $saleStmt->fetchColumn();

        // Insert Sale Items
        $itemStmt = $pdo->prepare("
            INSERT INTO sale_items 
            (sale_id, product_id, batch_id, location_type, quantity, unit_price, unit_cost, discount_amount, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($processedLines as $line) {
            $itemStmt->execute([
                $saleId, $line['product_id'], $line['batch_id'], $line['location_type'],
                $line['quantity'], $line['unit_price'], $line['unit_cost'],
                $line['discount_amount'], $line['line_total']
            ]);
        }

        $pdo->commit();

        // Broadcast to Firebase
        FirebaseService::broadcast('NEW_SALE', [
            'sale_id' => $saleId,
            'receipt_number' => $receiptNumber,
            'total_amount' => $totalAmount,
            'branch_id' => $branchId,
            'items_count' => count($processedLines)
        ]);

        Auth::jsonSuccess([
            'sale_id' => $saleId,
            'receipt_number' => $receiptNumber,
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'tax_amount' => $totalTax,
            'total_amount' => $totalAmount,
            'amount_tendered' => $amountTendered,
            'change_due' => $changeDue,
            'payment_method' => $paymentMethod,
            'lines' => $processedLines
        ], "Sale {$receiptNumber} completed successfully!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Sale transaction failed: ' . $e->getMessage());
    }
}

// 2. Void Sale
if ($action === 'void') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $saleId = (int)($data['sale_id'] ?? 0);
    $userId = Auth::user()['id'] ?? 1;

    if (!$saleId) {
        Auth::jsonError('Sale ID is required.');
    }

    $pdo->beginTransaction();
    try {
        $saleStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
        $saleStmt->execute([$saleId]);
        $sale = $saleStmt->fetch();

        if (!$sale) {
            $pdo->rollBack();
            Auth::jsonError('Sale not found.');
        }
        if ($sale['status'] === 'VOIDED') {
            $pdo->rollBack();
            Auth::jsonError('Sale is already voided.');
        }

        // Fetch items and restore stock
        $itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $itemsStmt->execute([$saleId]);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $it) {
            $pId = (int)$it['product_id'];
            $bId = (int)$it['batch_id'];
            $locType = $it['location_type'];
            $qty = (float)$it['quantity'];
            $cost = (float)$it['unit_cost'];

            // Get before stock
            $curStmt = $pdo->prepare("SELECT quantity FROM inventory WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = ?");
            $curStmt->execute([$sale['branch_id'], $pId, $bId, $locType]);
            $curQty = (float)($curStmt->fetchColumn() ?: 0);

            // Re-increment inventory
            $updStmt = $pdo->prepare("
                UPDATE inventory 
                SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP
                WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = ?
            ");
            $updStmt->execute([$qty, $sale['branch_id'], $pId, $bId, $locType]);

            // Audit movement
            Database::logStockMovement(
                $pdo, $sale['branch_id'], $pId, $bId, $locType, 'POS_RETURN',
                $sale['receipt_number'], $curQty, $qty, $curQty + $qty, $cost, $userId,
                "Void Sale {$sale['receipt_number']} - Stock Restored"
            );
        }

        // Update sale status
        $updSale = $pdo->prepare("UPDATE sales SET status = 'VOIDED' WHERE id = ?");
        $updSale->execute([$saleId]);

        $pdo->commit();

        FirebaseService::broadcast('STOCK_UPDATE', [
            'action' => 'SALE_VOIDED',
            'sale_id' => $saleId,
            'receipt_number' => $sale['receipt_number']
        ]);

        Auth::jsonSuccess(null, "Sale {$sale['receipt_number']} voided and stock restored.");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Void failed: ' . $e->getMessage());
    }
}

// 3. Customer Return & Adjustment
if ($action === 'return') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_CASHIER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $saleId = (int)($data['sale_id'] ?? 0);
    $saleItemId = (int)($data['sale_item_id'] ?? 0);
    $returnQty = (float)($data['quantity'] ?? 1);
    $reasonCode = strtoupper(trim($data['reason_code'] ?? 'CUSTOMER_MISTAKE'));
    $returnToStock = !empty($data['return_to_stock']); // If true, put back on shelf; if false, discard as wastage
    $userId = Auth::user()['id'] ?? 1;

    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare("
            SELECT si.*, s.branch_id, s.receipt_number 
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE si.id = ? AND si.sale_id = ?
        ");
        $itemStmt->execute([$saleItemId, $saleId]);
        $item = $itemStmt->fetch();

        if (!$item) {
            $pdo->rollBack();
            Auth::jsonError('Sale item record not found.');
        }

        $refundAmount = round($returnQty * ((float)$item['unit_price'] - ((float)$item['discount_amount'] / (float)$item['quantity'])), 2);

        // Record return
        $retStmt = $pdo->prepare("
            INSERT INTO sale_returns 
            (sale_id, sale_item_id, product_id, batch_id, branch_id, processed_by, quantity, refund_amount, reason_code, return_to_stock)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $retStmt->execute([
            $saleId, $saleItemId, $item['product_id'], $item['batch_id'],
            $item['branch_id'], $userId, $returnQty, $refundAmount, $reasonCode,
            $returnToStock ? 'true' : 'false'
        ]);
        $returnId = $retStmt->fetchColumn();

        if ($returnToStock) {
            // Restore to shelf inventory
            $upd = $pdo->prepare("
                UPDATE inventory 
                SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP
                WHERE branch_id = ? AND product_id = ? AND batch_id = ? AND location_type = 'SHELF'
            ");
            $upd->execute([$returnQty, $item['branch_id'], $item['product_id'], $item['batch_id']]);

            Database::logStockMovement(
                $pdo, $item['branch_id'], $item['product_id'], $item['batch_id'], 'SHELF', 'POS_RETURN',
                "RET-{$returnId}", 0, $returnQty, $returnQty, (float)$item['unit_cost'], $userId,
                "Customer Return {$reasonCode} - returned to shelf"
            );
        } else {
            // Route to wastage
            $loss = round($returnQty * (float)$item['unit_cost'], 2);
            $wStmt = $pdo->prepare("
                INSERT INTO wastage_logs 
                (branch_id, product_id, batch_id, location_type, quantity, cost_loss, reason_code, logged_by, notes)
                VALUES (?, ?, ?, 'SHELF', ?, ?, ?, ?, ?)
            ");
            $wStmt->execute([
                $item['branch_id'], $item['product_id'], $item['batch_id'],
                $returnQty, $loss, $reasonCode, $userId, "From Return RET-{$returnId} (Defective/Damaged/Expired)"
            ]);
        }

        $pdo->commit();
        Auth::jsonSuccess([
            'return_id' => $returnId,
            'refund_amount' => $refundAmount
        ], "Return processed! Refund amount: \${$refundAmount}");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Return processing failed: ' . $e->getMessage());
    }
}

// 4. Query Recent Sales
$branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
$stmt = $pdo->prepare("
    SELECT 
        s.*, u.full_name as cashier_name,
        COUNT(si.id) as item_count
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    LEFT JOIN sale_items si ON s.id = si.sale_id
    WHERE s.branch_id = ?
    GROUP BY s.id, u.full_name
    ORDER BY s.sale_time DESC
    LIMIT 50
");
$stmt->execute([$branchId]);
Auth::jsonSuccess($stmt->fetchAll());
