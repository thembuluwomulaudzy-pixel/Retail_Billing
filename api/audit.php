<?php
// =====================================================================
// TC LIMITED - Stock Movement Immutable Audit Trail API
// =====================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);
$productId = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$movementType = !empty($_GET['movement_type']) ? trim($_GET['movement_type']) : null;
$startDate = !empty($_GET['start_date']) ? trim($_GET['start_date']) : null;
$endDate = !empty($_GET['end_date']) ? trim($_GET['end_date']) : null;
$limit = min(200, (int)($_GET['limit'] ?? 100));

$sql = "
    SELECT 
        sm.*, p.name as product_name, p.sku, p.uom,
        pb.batch_number, pb.expiry_date,
        b.name as branch_name,
        u.full_name as user_name, u.username
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    LEFT JOIN product_batches pb ON sm.batch_id = pb.id
    JOIN branches b ON sm.branch_id = b.id
    LEFT JOIN users u ON sm.user_id = u.id
    WHERE sm.branch_id = ?
";
$params = [$branchId];

if ($productId) {
    $sql .= " AND sm.product_id = ?";
    $params[] = $productId;
}

if ($movementType) {
    $sql .= " AND sm.movement_type = ?";
    $params[] = $movementType;
}

if ($startDate) {
    $sql .= " AND sm.created_at >= ?";
    $params[] = $startDate . ' 00:00:00';
}

if ($endDate) {
    $sql .= " AND sm.created_at <= ?";
    $params[] = $endDate . ' 23:59:59';
}

$sql .= " ORDER BY sm.created_at DESC LIMIT " . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
Auth::jsonSuccess($stmt->fetchAll());
