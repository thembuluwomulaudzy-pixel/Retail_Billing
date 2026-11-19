<?php
// =====================================================================
// TC LIMITED - User Administration & RBAC API (Add & Delete Users)
// =====================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Roles listing
if ($action === 'roles') {
    $stmt = $pdo->query("SELECT * FROM roles ORDER BY id ASC");
    Auth::jsonSuccess($stmt->fetchAll());
}

// 2. Query Users List
if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT 
            u.id, u.username, u.full_name, u.email, u.pin_code, u.is_active, u.created_at,
            r.id as role_id, r.name as role_name, r.description as role_description,
            b.id as branch_id, b.name as branch_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN branches b ON u.branch_id = b.id
        ORDER BY u.id ASC
    ");
    Auth::jsonSuccess($stmt->fetchAll());
}

// 3. Delete User (Supports DELETE method or POST with action=delete)
if ($method === 'DELETE' || $action === 'delete') {
    Auth::requireRole(Auth::ROLE_ADMIN);
    
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $userId = (int)($data['id'] ?? $_GET['id'] ?? 0);
    $currentUserId = Auth::user()['id'] ?? 0;

    if (!$userId) {
        Auth::jsonError('User ID is required for deletion.');
    }

    // Protection 1: Cannot delete self
    if ($userId === (int)$currentUserId) {
        Auth::jsonError('You cannot delete your own active administrator account.', 403);
    }

    // Fetch user to delete
    $chkStmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ?");
    $chkStmt->execute([$userId]);
    $targetUser = $chkStmt->fetch();

    if (!$targetUser) {
        Auth::jsonError('User not found in system.', 404);
    }

    // Protection 2: Cannot delete primary 'admin' account
    if ($targetUser['username'] === 'admin') {
        Auth::jsonError('The primary superadmin account cannot be deleted for system safety.', 403);
    }

    try {
        $delStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $delStmt->execute([$userId]);

        Auth::jsonSuccess([
            'deleted_user_id' => $userId,
            'username' => $targetUser['username']
        ], "User '{$targetUser['full_name']}' (@{$targetUser['username']}) was permanently deleted.");
    } catch (Exception $e) {
        Auth::jsonError('Failed to delete user: ' . $e->getMessage(), 500);
    }
}

// 4. Create New User (Admin Only)
if ($method === 'POST') {
    Auth::requireRole(Auth::ROLE_ADMIN);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $username = strtolower(trim($data['username'] ?? ''));
    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $roleId = (int)($data['role_id'] ?? 4); // Default cashier
    $branchId = !empty($data['branch_id']) ? (int)$data['branch_id'] : null;
    $password = trim($data['password'] ?? 'password123');
    $pin = trim($data['pin_code'] ?? '1234');

    if (!$username || !$fullName || !$password) {
        Auth::jsonError('Username, Full Name, and Password are required.');
    }

    // Validate alphanumeric username
    if (!preg_match('/^[a-z0-9_.-]{3,30}$/', $username)) {
        Auth::jsonError('Username must be 3-30 characters long and contain only letters, numbers, dots, or underscores.');
    }

    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $chk->execute([$username]);
    if ($chk->fetch()) {
        Auth::jsonError("Username '{$username}' is already taken. Please choose another.");
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("
        INSERT INTO users (role_id, branch_id, username, full_name, email, password_hash, pin_code, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)
        RETURNING id
    ");
    $stmt->execute([$roleId, $branchId, $username, $fullName, $email, $hash, $pin]);
    $newId = $stmt->fetchColumn();

    Auth::jsonSuccess([
        'id' => $newId,
        'username' => $username,
        'full_name' => $fullName
    ], "Staff user '{$fullName}' (@{$username}) created successfully!");
}
