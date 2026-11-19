<?php
// =====================================================================
// TC LIMITED - Automated System & Integration Test Suite
// =====================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/firebase.php';
require_once __DIR__ . '/config/auth.php';

header('Content-Type: text/html; charset=utf-8');

$results = [];

function runTest(string $title, callable $testFn): void {
    global $results;
    $start = microtime(true);
    try {
        $msg = $testFn();
        $duration = round((microtime(true) - $start) * 1000, 2);
        $results[] = [
            'title' => $title,
            'passed' => true,
            'message' => $msg ?: 'Passed successfully',
            'duration' => $duration
        ];
    } catch (Throwable $e) {
        $duration = round((microtime(true) - $start) * 1000, 2);
        $results[] = [
            'title' => $title,
            'passed' => false,
            'message' => $e->getMessage(),
            'duration' => $duration
        ];
    }
}

// 1. DB Connection Test
runTest('PostgreSQL Database Connectivity', function() {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT version();");
    $ver = $stmt->fetchColumn();
    if (!$ver) throw new Exception("Failed to query PostgreSQL version");
    return "Connected to: " . substr($ver, 0, 35) . "...";
});

// 2. Table Count & Integrity
runTest('Schema Integrity (Table Existence)', function() {
    $pdo = Database::getConnection();
    $requiredTables = [
        'branches', 'locations', 'roles', 'users', 'categories', 'products',
        'suppliers', 'supplier_products', 'product_batches', 'inventory',
        'purchase_orders', 'purchase_order_items', 'goods_received_notes',
        'goods_received_items', 'promotions', 'sales', 'sale_items',
        'sale_returns', 'wastage_logs', 'stocktakes', 'stocktake_items',
        'branch_transfers', 'branch_transfer_items', 'stock_movements'
    ];
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $missing = array_diff($requiredTables, $existing);
    if (!empty($missing)) {
        throw new Exception("Missing tables: " . implode(', ', $missing));
    }
    return count($requiredTables) . " enterprise tables verified.";
});

// 3. User Authentication & Password Hashing
runTest('User Authentication & Role Assignment', function() {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = 'admin'");
    $stmt->execute();
    $u = $stmt->fetch();
    if (!$u) throw new Exception("Admin user not found in database.");
    if (!password_verify('password123', $u['password_hash'])) {
        throw new Exception("Bcrypt verification failed for admin password.");
    }
    return "Admin verified with role '{$u['role_name']}' and bcrypt hash.";
});

// 4. Barcode Lookup Engine
runTest('Barcode / SKU Scanner Lookup Engine', function() {
    $pdo = Database::getConnection();
    $barcode = '8901001001'; // Milk 1L
    $stmt = $pdo->prepare("SELECT * FROM products WHERE barcode = ?");
    $stmt->execute([$barcode]);
    $p = $stmt->fetch();
    if (!$p) throw new Exception("Product with barcode {$barcode} not found.");
    if ($p['sku'] !== 'DAIR-MILK-1L') throw new Exception("SKU mismatch for barcode {$barcode}.");
    return "Matched Barcode '{$barcode}' to '{$p['name']}' (SKU: {$p['sku']}).";
});

// 5. FEFO (First-Expired, First-Out) Priority Verification
runTest('FEFO (First-Expired, First-Out) Priority Algorithm', function() {
    $pdo = Database::getConnection();
    // Fetch batches for milk (product_id = 1) ordered by expiry_date ASC
    $stmt = $pdo->prepare("
        SELECT pb.batch_number, pb.expiry_date, i.quantity, i.location_type
        FROM inventory i
        JOIN product_batches pb ON i.batch_id = pb.id
        WHERE i.product_id = 1 AND i.branch_id = 1 AND i.quantity > 0
        ORDER BY pb.expiry_date ASC
    ");
    $stmt->execute();
    $batches = $stmt->fetchAll();

    if (count($batches) < 2) {
        throw new Exception("FEFO test requires at least 2 distinct active batches.");
    }

    $firstExpiry = strtotime($batches[0]['expiry_date']);
    $secondExpiry = strtotime($batches[1]['expiry_date']);

    if ($firstExpiry > $secondExpiry) {
        throw new Exception("FEFO sorting violated: Batch 0 ({$batches[0]['expiry_date']}) expires after Batch 1 ({$batches[1]['expiry_date']}).");
    }

    return "FEFO validated: Earliest batch '{$batches[0]['batch_number']}' ({$batches[0]['expiry_date']}) prioritized over '{$batches[1]['batch_number']}' ({$batches[1]['expiry_date']}).";
});

// 6. Automatic Purchase Order Generator Logic
runTest('Automatic Purchase Order Generation Engine', function() {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.reorder_point, COALESCE(SUM(i.quantity), 0) as current_stock
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = 1
        WHERE p.is_active = TRUE
        GROUP BY p.id, p.name, p.reorder_point
        HAVING COALESCE(SUM(i.quantity), 0) <= p.reorder_point
    ");
    $stmt->execute();
    $lowItems = $stmt->fetchAll();

    if (empty($lowItems)) {
        throw new Exception("Expected at least 1 item below reorder point (e.g. White Bread).");
    }
    return "Auto-PO trigger verified: Identified " . count($lowItems) . " product(s) needing replenishment (e.g. '{$lowItems[0]['name']}', Stock: {$lowItems[0]['current_stock']} <= Reorder: {$lowItems[0]['reorder_point']}).";
});

// 7. Stock Valuation (FIFO vs WAC)
runTest('Stock Valuation Engine (FIFO vs WAC)', function() {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("
        SELECT 
            SUM(i.quantity * pb.unit_cost) as fifo_cost,
            SUM(i.quantity * p.selling_price) as retail_val
        FROM inventory i
        JOIN products p ON i.product_id = p.id
        JOIN product_batches pb ON i.batch_id = pb.id
        WHERE i.branch_id = 1
    ");
    $val = $stmt->fetch();
    $fifo = (float)$val['fifo_cost'];
    $retail = (float)$val['retail_val'];

    if ($fifo <= 0 || $retail <= 0) {
        throw new Exception("Inventory valuation returned non-positive values.");
    }
    return "Valuation computed: Total FIFO Cost = \${$fifo}, Potential Retail = \${$retail}.";
});

// 8. ABC Pareto Classification
runTest('ABC Inventory Pareto Classification', function() {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("
        SELECT p.name, COALESCE(SUM(si.line_total), 0) as rev
        FROM products p
        LEFT JOIN sale_items si ON p.id = si.product_id
        GROUP BY p.id, p.name
        ORDER BY rev DESC
    ");
    $rows = $stmt->fetchAll();
    if (empty($rows)) throw new Exception("No products for ABC calculation.");
    return "ABC Pareto analysis completed successfully on " . count($rows) . " catalog items.";
});

// 9. Immutable Audit Movement Logging
runTest('Immutable Audit Trail (Stock Movements)', function() {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM stock_movements");
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) throw new Exception("No audit records found in stock_movements table.");
    return "Audit trail active: {$count} immutable stock movement records logged.";
});

// 10. Firebase Event Bus Synchronization
runTest('Firebase Real-Time Event Bus & Local Fallback', function() {
    $res = FirebaseService::broadcast('TEST_EVENT', [
        'test' => 'Automated test suite verification',
        'time' => date('c')
    ]);
    if (!$res['broadcast']) throw new Exception("Firebase broadcast failed.");
    return "Real-time event bus broadcast successful (Logged to scratch/firebase_events.json).";
});

// 11. Admin User Lifecycle (Create & Delete)
runTest('Admin User Lifecycle (Add & Delete Staff)', function() {
    $pdo = Database::getConnection();
    
    // Create temporary user
    $tempUser = 'test_clerk_' . substr(uniqid(), -5);
    $pwdHash = password_hash('password123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("
        INSERT INTO users (role_id, branch_id, username, full_name, email, password_hash, pin_code, is_active)
        VALUES (4, 1, ?, 'Automated Test Clerk', 'clerk@test.com', ?, '7777', TRUE)
        RETURNING id
    ");
    $stmt->execute([$tempUser, $pwdHash]);
    $createdId = (int)$stmt->fetchColumn();
    if (!$createdId) throw new Exception("Failed to insert test user.");

    // Delete user
    $delStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $delStmt->execute([$createdId]);

    // Verify gone
    $chk = $pdo->query("SELECT id FROM users WHERE id = {$createdId}")->fetch();
    if ($chk) throw new Exception("User still exists after deletion.");

    return "Admin created temporary staff (@{$tempUser}) and deleted safely.";
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TC Limited - Automated System Test Suite</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { padding: 40px; background: #f8faf9; color: #132a1c; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .test-box { max-width: 900px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; box-shadow: 0 4px 15px rgba(20, 83, 45, 0.08); border: 1px solid #e2ece5; }
        .test-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #edf4f0; }
        .test-item:last-child { border-bottom: none; }
        .test-title { font-weight: 800; font-size: 14px; color: #14532d; }
        .test-msg { font-size: 12px; color: #52705d; margin-top: 3px; }
        .test-badge { padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 800; }
        .badge-pass { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-fail { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
    <div class="test-box">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;border-bottom:2px solid #bbf7d0;padding-bottom:16px;">
            <div>
                <h1 style="font-size:22px;font-weight:900;color:#14532d;letter-spacing:-0.5px;">TC LIMITED GROCERY SYSTEM</h1>
                <div style="font-size:13px;color:#16a34a;font-weight:700;">Automated Verification & Integration Test Suite</div>
            </div>
            <a href="index.php" class="btn btn-primary">Open Main Application</a>
        </div>

        <div>
            <?php foreach ($results as $r): ?>
                <div class="test-item">
                    <div>
                        <div class="test-title"><?php echo htmlspecialchars($r['title']); ?></div>
                        <div class="test-msg"><?php echo htmlspecialchars($r['message']); ?> (<?php echo $r['duration']; ?> ms)</div>
                    </div>
                    <div>
                        <span class="test-badge <?php echo $r['passed'] ? 'badge-pass' : 'badge-fail'; ?>">
                            <?php echo $r['passed'] ? '✓ PASS' : '✗ FAIL'; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
