<?php
// =====================================================================
// TC LIMITED - Product Catalogue & Category API
// =====================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Categories
if ($action === 'categories') {
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT c.*, COUNT(p.id) as product_count 
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id AND p.is_active = TRUE
            GROUP BY c.id
            ORDER BY c.name ASC
        ");
        Auth::jsonSuccess($stmt->fetchAll());
    } elseif ($method === 'POST') {
        Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $name = trim($data['name'] ?? '');
        $code = strtoupper(trim($data['code'] ?? ''));
        $tax = (float)($data['default_tax_rate'] ?? 0.0);
        $shelfLife = (int)($data['shelf_life_days_default'] ?? 30);

        if (!$name || !$code) {
            Auth::jsonError('Category Name and Code are required.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO categories (name, code, default_tax_rate, shelf_life_days_default)
            VALUES (?, ?, ?, ?) RETURNING id
        ");
        $stmt->execute([$name, $code, $tax, $shelfLife]);
        Auth::jsonSuccess(['id' => $stmt->fetchColumn()], 'Category created successfully');
    }
}

// 2. Barcode scanner quick lookup
if ($action === 'lookup') {
    $code = trim($_GET['code'] ?? '');
    $branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);

    if (!$code) {
        Auth::jsonError('Barcode or SKU is required.');
    }

    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name, c.default_tax_rate,
               COALESCE(SUM(CASE WHEN i.location_type = 'SHELF' THEN i.quantity ELSE 0 END), 0) as shelf_stock,
               COALESCE(SUM(CASE WHEN i.location_type = 'BACKROOM' THEN i.quantity ELSE 0 END), 0) as backroom_stock,
               COALESCE(SUM(i.quantity), 0) as total_stock
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
        WHERE (p.barcode = ? OR p.sku = ?) AND p.is_active = TRUE
        GROUP BY p.id, c.name, c.default_tax_rate
    ");
    $stmt->execute([$branchId, $code, $code]);
    $product = $stmt->fetch();

    if (!$product) {
        Auth::jsonError("Product with barcode/SKU '{$code}' not found.", 404);
    }

    // Check for active promotions / markdowns
    $stmtPromo = $pdo->prepare("
        SELECT * FROM promotions 
        WHERE product_id = ? AND is_active = TRUE 
          AND CURRENT_DATE BETWEEN start_date AND end_date
        ORDER BY discount_percent DESC LIMIT 1
    ");
    $stmtPromo->execute([$product['id']]);
    $promo = $stmtPromo->fetch();
    $product['active_promotion'] = $promo ?: null;

    // Calculate effective selling price
    $effectivePrice = (float)$product['selling_price'];
    if ($promo) {
        if ($promo['discount_percent'] > 0) {
            $effectivePrice -= round($effectivePrice * ($promo['discount_percent'] / 100), 2);
        } elseif ($promo['discount_amount'] > 0) {
            $effectivePrice = max(0, $effectivePrice - $promo['discount_amount']);
        }
    }
    $product['effective_price'] = $effectivePrice;

    Auth::jsonSuccess($product);
}

// 3. Products List / Search
if ($method === 'GET') {
    $branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
    $categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $search = trim($_GET['search'] ?? '');
    $lowStockOnly = !empty($_GET['low_stock']);
    $perishableOnly = !empty($_GET['perishable_only']);

    $sql = "
        SELECT p.*, c.name as category_name, c.code as category_code,
               COALESCE(SUM(CASE WHEN i.location_type = 'SHELF' THEN i.quantity ELSE 0 END), 0) as shelf_stock,
               COALESCE(SUM(CASE WHEN i.location_type = 'BACKROOM' THEN i.quantity ELSE 0 END), 0) as backroom_stock,
               COALESCE(SUM(i.quantity), 0) as total_stock
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
        WHERE p.is_active = TRUE
    ";
    $params = [$branchId];

    if ($categoryId) {
        $sql .= " AND p.category_id = ?";
        $params[] = $categoryId;
    }

    if ($search) {
        $sql .= " AND (p.name ILIKE ? OR p.sku ILIKE ? OR p.barcode ILIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($perishableOnly) {
        $sql .= " AND p.is_perishable = TRUE";
    }

    $sql .= " GROUP BY p.id, c.name, c.code";

    if ($lowStockOnly) {
        $sql .= " HAVING COALESCE(SUM(i.quantity), 0) <= p.reorder_point";
    }

    $sql .= " ORDER BY p.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    Auth::jsonSuccess($products);
}

// 4. Create Product
if ($method === 'POST') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER, Auth::ROLE_STOREKEEPER);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $categoryId = (int)($data['category_id'] ?? 0);
    $sku = strtoupper(trim($data['sku'] ?? ''));
    $barcode = trim($data['barcode'] ?? '');
    $name = trim($data['name'] ?? '');
    $desc = trim($data['description'] ?? '');
    $uom = trim($data['uom'] ?? 'unit');
    $isPerish = !empty($data['is_perishable']) ? 'true' : 'false';
    $cost = (float)($data['cost_price'] ?? 0.0);
    $price = (float)($data['selling_price'] ?? 0.0);
    $reorder = (int)($data['reorder_point'] ?? 10);
    $minStock = (int)($data['min_stock_level'] ?? 5);
    $maxStock = (int)($data['max_stock_level'] ?? 100);
    $shelfLife = (int)($data['shelf_life_days'] ?? 7);
    $alertDays = (int)($data['alert_before_expiry_days'] ?? 3);

    if (!$categoryId || !$sku || !$barcode || !$name) {
        Auth::jsonError('Category, SKU, Barcode, and Product Name are mandatory.');
    }

    // Check unique SKU and Barcode
    $chk = $pdo->prepare("SELECT id FROM products WHERE sku = ? OR barcode = ?");
    $chk->execute([$sku, $barcode]);
    if ($chk->fetch()) {
        Auth::jsonError('Product SKU or Barcode already exists in the system.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO products 
        (category_id, sku, barcode, name, description, uom, is_perishable, cost_price, selling_price, reorder_point, min_stock_level, max_stock_level, shelf_life_days, alert_before_expiry_days)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([
        $categoryId, $sku, $barcode, $name, $desc, $uom, $isPerish,
        $cost, $price, $reorder, $minStock, $maxStock, $shelfLife, $alertDays
    ]);
    $newId = $stmt->fetchColumn();

    Auth::jsonSuccess(['id' => $newId], 'Product created successfully');
}

// 5. Update Product
if ($method === 'PUT') {
    Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);

    if (!$id) {
        Auth::jsonError('Product ID is required.');
    }

    $stmt = $pdo->prepare("
        UPDATE products SET
            name = COALESCE(?, name),
            description = COALESCE(?, description),
            cost_price = COALESCE(?, cost_price),
            selling_price = COALESCE(?, selling_price),
            reorder_point = COALESCE(?, reorder_point),
            min_stock_level = COALESCE(?, min_stock_level),
            max_stock_level = COALESCE(?, max_stock_level),
            alert_before_expiry_days = COALESCE(?, alert_before_expiry_days)
        WHERE id = ?
    ");
    $stmt->execute([
        $data['name'] ?? null,
        $data['description'] ?? null,
        isset($data['cost_price']) ? (float)$data['cost_price'] : null,
        isset($data['selling_price']) ? (float)$data['selling_price'] : null,
        isset($data['reorder_point']) ? (int)$data['reorder_point'] : null,
        isset($data['min_stock_level']) ? (int)$data['min_stock_level'] : null,
        isset($data['max_stock_level']) ? (int)$data['max_stock_level'] : null,
        isset($data['alert_before_expiry_days']) ? (int)$data['alert_before_expiry_days'] : null,
        $id
    ]);

    Auth::jsonSuccess(null, 'Product updated successfully');
}
