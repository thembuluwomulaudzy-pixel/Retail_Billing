<?php
// =====================================================================
// TC LIMITED - Authentication & Session API
// =====================================================================

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = Database::getConnection();

switch ($action) {
    case 'login':
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$username || !$password) {
            Auth::jsonError('Username and password are required.');
        }

        $stmt = $pdo->prepare("
            SELECT u.*, r.name as role_name, b.name as branch_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE u.username = ? AND u.is_active = TRUE
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Auth::jsonError('Invalid username or password.', 401);
        }

        // Set session & stateless serverless token
        Auth::loginUser($user);

        Auth::jsonSuccess([
            'user' => Auth::user(),
            'redirect' => 'index.php'
        ], 'Login successful');
        break;

    case 'quick_pin':
        // Quick POS cashier switcher via 4-digit PIN
        $pin = trim($_POST['pin'] ?? '');
        if (!$pin) {
            Auth::jsonError('PIN is required.');
        }

        $stmt = $pdo->prepare("
            SELECT u.*, r.name as role_name, b.name as branch_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE u.pin_code = ? AND u.is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([$pin]);
        $user = $stmt->fetch();

        if (!$user) {
            Auth::jsonError('Invalid Cashier PIN.', 401);
        }

        // Set session & stateless serverless token
        Auth::loginUser($user);

        Auth::jsonSuccess([
            'user' => Auth::user()
        ], 'Cashier switched successfully');
        break;

    case 'me':
        if (!Auth::check()) {
            Auth::jsonError('Not logged in', 401);
        }
        Auth::jsonSuccess(Auth::user());
        break;

    case 'branches':
        $stmt = $pdo->query("SELECT id, code, name, address, phone FROM branches WHERE is_active = TRUE ORDER BY id ASC");
        Auth::jsonSuccess($stmt->fetchAll());
        break;

    case 'switch_branch':
        Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_MANAGER);
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$branchId]);
        $b = $stmt->fetch();
        if (!$b) {
            Auth::jsonError('Invalid branch ID.');
        }
        $_SESSION['branch_id'] = (int)$b['id'];
        $_SESSION['branch_name'] = $b['name'];
        Auth::jsonSuccess([
            'branch_id' => $_SESSION['branch_id'],
            'branch_name' => $_SESSION['branch_name']
        ], 'Switched active branch');
        break;

    case 'logout':
        Auth::logout();
        Auth::jsonSuccess(null, 'Logged out successfully');
        break;

    default:
        Auth::jsonError('Invalid action requested.');
}
