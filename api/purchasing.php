<?php
// =====================================================================
// TC LIMITED - Purchasing, Replenishment & Supplier Management API
// =====================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/firebase.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Suppliers List & CRUD
if ($action === 'suppliers') {
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT s.*, 
                   COUNT(DISTINCT sp.product_id) as catalog_product_count,
                   COUNT(DISTINCT po.id) as total_pos
            FROM suppliers s
            LEFT JOIN supplier_products sp ON s.id = sp.supplier_id
            LEFT JOIN purchase_orders po ON s.id = po.supplier_id
            WHERE s.is_active = TRUE
            GROUP BY s.id
            ORDER BY s.name ASC
        ");
        Auth::jsonSuccess($stmt->fetchAll());
    } elseif ($method === 'POST') {
        Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $code = strtoupper(trim($data['code'] ?? ''));
        $name = trim($data['name'] ?? '');
        $contact = trim($data['contact_person'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $leadTime = (int)($data['lead_time_days'] ?? 3);
        $terms = trim($data['payment_terms'] ?? 'Net 30');
        $rating = (float)($data['rating'] ?? 4.5);

        if (!$code || !$name) {
            Auth::jsonError('Supplier code and name are required.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO suppliers (code, name, contact_person, email, phone, lead_time_days, payment_terms, rating)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
        ");
        $stmt->execute([$code, $name, $contact, $email, $phone, $leadTime, $terms, $rating]);
        Auth::jsonSuccess(['id' => $stmt->fetchColumn()], 'Supplier added successfully');
    }
}

// 2. Reorder Alert List (Items below reorder point)
if ($action === 'reorder_alerts') {
    $branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);

    $stmt = $pdo->prepare("
        SELECT 
            p.id as product_id, p.sku, p.barcode, p.name as product_name, p.uom,
            p.cost_price, p.reorder_point, p.min_stock_level, p.max_stock_level,
            c.name as category_name,
            COALESCE(SUM(i.quantity), 0) as current_stock,
            (p.max_stock_level - COALESCE(SUM(i.quantity), 0)) as suggested_order_qty,
            s.id as supplier_id, s.name as supplier_name, s.lead_time_days,
            COALESCE(sp.contract_price, p.cost_price) as unit_order_cost
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
        LEFT JOIN supplier_products sp ON p.id = sp.product_id AND sp.is_preferred = TRUE
        LEFT JOIN suppliers s ON sp.supplier_id = s.id
        WHERE p.is_active = TRUE
        GROUP BY p.id, c.name, s.id, s.name, s.lead_time_days, sp.contract_price
        HAVING COALESCE(SUM(i.quantity), 0) <= p.reorder_point
        ORDER BY (COALESCE(SUM(i.quantity), 0) - p.reorder_point) ASC
    ");
    $stmt->execute([$branchId]);
    Auth::jsonSuccess($stmt->fetchAll());
}

// 3. Automatic Purchase Order Generator
if ($action === 'auto_generate') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $branchId = (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $userId = Auth::user()['id'] ?? 1;

    $pdo->beginTransaction();
    try {
        // Query all items at or below reorder point with their preferred supplier
        $stmt = $pdo->prepare("
            SELECT 
                p.id as product_id, p.sku, p.name, p.max_stock_level, p.reorder_point,
                COALESCE(SUM(i.quantity), 0) as current_stock,
                COALESCE(sp.supplier_id, 1) as supplier_id,
                COALESCE(sp.contract_price, p.cost_price) as unit_cost,
                COALESCE(s.lead_time_days, 3) as lead_time_days
            FROM products p
            LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
            LEFT JOIN supplier_products sp ON p.id = sp.product_id AND sp.is_preferred = TRUE
            LEFT JOIN suppliers s ON sp.supplier_id = s.id
            WHERE p.is_active = TRUE
            GROUP BY p.id, sp.supplier_id, sp.contract_price, s.lead_time_days
            HAVING COALESCE(SUM(i.quantity), 0) <= p.reorder_point
        ");
        $stmt->execute([$branchId]);
        $lowStockItems = $stmt->fetchAll();

        if (empty($lowStockItems)) {
            $pdo->rollBack();
            Auth::jsonSuccess([], 'No products are currently at or below reorder points.');
        }

        // Group items by supplier
        $grouped = [];
        foreach ($lowStockItems as $item) {
            $supId = (int)$item['supplier_id'];
            $orderQty = max(10, (int)$item['max_stock_level'] - (int)$item['current_stock']);
            $cost = (float)$item['unit_cost'];
            $grouped[$supId][] = [
                'product_id' => (int)$item['product_id'],
                'name' => $item['name'],
                'quantity' => $orderQty,
                'unit_cost' => $cost,
                'total_cost' => round($orderQty * $cost, 2),
                'lead_time' => (int)$item['lead_time_days']
            ];
        }

        $generatedPOs = [];
        $poNumberSeq = time();

        foreach ($grouped as $supId => $items) {
            $totalPOAmount = 0.0;
            foreach ($items as $it) {
                $totalPOAmount += $it['total_cost'];
            }
            $maxLead = max(array_column($items, 'lead_time'));
            $expectedDelivery = date('Y-m-d', strtotime("+{$maxLead} days"));
            $poNumber = 'PO-AUTO-' . date('Ymd') . '-' . substr((string)$poNumberSeq++, -4);

            $stmtPO = $pdo->prepare("
                INSERT INTO purchase_orders 
                (po_number, branch_id, supplier_id, order_date, expected_delivery_date, status, is_auto_generated, total_amount, notes, created_by)
                VALUES (?, ?, ?, CURRENT_DATE, ?, 'SENT', TRUE, ?, 'Auto-generated based on Min/Max Stock Reorder Points', ?)
                RETURNING id
            ");
            $stmtPO->execute([$poNumber, $branchId, $supId, $expectedDelivery, $totalPOAmount, $userId]);
            $poId = $stmtPO->fetchColumn();

            $stmtItem = $pdo->prepare("
                INSERT INTO purchase_order_items 
                (purchase_order_id, product_id, quantity_ordered, unit_cost, total_cost)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($items as $it) {
                $stmtItem->execute([$poId, $it['product_id'], $it['quantity'], $it['unit_cost'], $it['total_cost']]);
            }

            $generatedPOs[] = [
                'po_id' => $poId,
                'po_number' => $poNumber,
                'supplier_id' => $supId,
                'items_count' => count($items),
                'total_amount' => $totalPOAmount,
                'expected_delivery' => $expectedDelivery
            ];
        }

        $pdo->commit();

        // Broadcast to Firebase
        FirebaseService::broadcast('AUTO_PO', [
            'action' => 'POS_GENERATED',
            'count' => count($generatedPOs),
            'pos' => $generatedPOs
        ]);

        Auth::jsonSuccess($generatedPOs, "Successfully auto-generated " . count($generatedPOs) . " Purchase Order(s)!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Auto PO generation failed: ' . $e->getMessage());
    }
}

// 4. Create Manual Purchase Order
if ($action === 'create_po') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $branchId = (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $supplierId = (int)($data['supplier_id'] ?? 0);
    $items = $data['items'] ?? [];
    $deliveryDate = $data['expected_delivery_date'] ?? date('Y-m-d', strtotime('+3 days'));
    $notes = trim($data['notes'] ?? '');
    $userId = Auth::user()['id'] ?? 1;

    if (!$supplierId || empty($items)) {
        Auth::jsonError('Supplier and at least one item are required.');
    }

    $pdo->beginTransaction();
    try {
        $poNumber = 'PO-' . date('Ymd') . '-' . substr((string)time(), -4);
        $totalAmount = 0.0;

        foreach ($items as $it) {
            $qty = (float)($it['quantity'] ?? 0);
            $cost = (float)($it['unit_cost'] ?? 0);
            $totalAmount += round($qty * $cost, 2);
        }

        $stmtPO = $pdo->prepare("
            INSERT INTO purchase_orders 
            (po_number, branch_id, supplier_id, order_date, expected_delivery_date, status, is_auto_generated, total_amount, notes, created_by)
            VALUES (?, ?, ?, CURRENT_DATE, ?, 'SENT', FALSE, ?, ?, ?)
            RETURNING id
        ");
        $stmtPO->execute([$poNumber, $branchId, $supplierId, $deliveryDate, $totalAmount, $notes, $userId]);
        $poId = $stmtPO->fetchColumn();

        $stmtItem = $pdo->prepare("
            INSERT INTO purchase_order_items 
            (purchase_order_id, product_id, quantity_ordered, unit_cost, total_cost)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($items as $it) {
            $pId = (int)$it['product_id'];
            $qty = (float)$it['quantity'];
            $cost = (float)$it['unit_cost'];
            $stmtItem->execute([$poId, $pId, $qty, $cost, round($qty * $cost, 2)]);
        }

        $pdo->commit();
        Auth::jsonSuccess(['po_id' => $poId, 'po_number' => $poNumber], "Purchase Order {$poNumber} created successfully!");
    } catch (Exception $e) {
        $pdo->rollBack();
        Auth::jsonError('Failed to create Purchase Order: ' . $e->getMessage());
    }
}

// 5. Query Purchase Orders
$branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
$stmt = $pdo->prepare("
    SELECT 
        po.*, s.name as supplier_name, s.phone as supplier_phone,
        u.full_name as created_by_name,
        COUNT(poi.id) as item_count
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
    WHERE po.branch_id = ?
    GROUP BY po.id, s.name, s.phone, u.full_name
    ORDER BY po.created_at DESC
");
$stmt->execute([$branchId]);
$pos = $stmt->fetchAll();

// If single PO detail requested:
if (!empty($_GET['po_id'])) {
    $poId = (int)$_GET['po_id'];
    $stmtItems = $pdo->prepare("
        SELECT poi.*, p.name as product_name, p.sku, p.barcode, p.uom
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
    ");
    $stmtItems->execute([$poId]);
    Auth::jsonSuccess([
        'po' => array_values(array_filter($pos, fn($x) => (int)$x['id'] === $poId))[0] ?? null,
        'items' => $stmtItems->fetchAll()
    ]);
}

Auth::jsonSuccess($pos);
