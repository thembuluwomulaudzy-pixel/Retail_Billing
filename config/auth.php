<?php
// =====================================================================
// TC LIMITED - Authentication & Role-Based Access Control (RBAC)
// Enhanced for Serverless Environments (Vercel Stateless Cookie Fallback)
// =====================================================================

$tempDir = sys_get_temp_dir();
if (is_dir($tempDir) && is_writable($tempDir)) {
    @session_save_path($tempDir);
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

define('AUTH_SECRET', getenv('AUTH_SECRET') ?: getenv('VERCEL_GIT_COMMIT_SHA') ?: 'tc_grocery_super_secret_auth_key_2026');

class Auth {
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_STOREKEEPER = 'storekeeper';
    public const ROLE_CASHIER = 'cashier';

    public static function check(): bool {
        self::restoreSessionFromCookie();
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role_name'],
            'role_id' => $_SESSION['role_id'],
            'branch_id' => $_SESSION['branch_id'],
            'branch_name' => $_SESSION['branch_name'] ?? 'All Branches'
        ];
    }

    public static function hasRole(string ...$roles): bool {
        if (!self::check()) return false;
        $currentRole = $_SESSION['role_name'] ?? '';
        if ($currentRole === self::ROLE_ADMIN) return true; // Superuser bypass
        return in_array($currentRole, $roles, true);
    }

    public static function requireRole(string ...$roles): void {
        if (!self::check()) {
            self::jsonError('Unauthenticated. Please log in.', 401);
        }
        if (!self::hasRole(...$roles)) {
            self::jsonError('Access Denied. You do not have permission to perform this action.', 403);
        }
    }

    /**
     * Set session and stateless HMAC-signed cookie for serverless persistence
     */
    public static function loginUser(array $user): void {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role_id'] = (int)$user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['branch_id'] = !empty($user['branch_id']) ? (int)$user['branch_id'] : 1;
        $_SESSION['branch_name'] = $user['branch_name'] ?? 'TC Central Supermarket';

        $payload = [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role_id' => $_SESSION['role_id'],
            'role_name' => $_SESSION['role_name'],
            'branch_id' => $_SESSION['branch_id'],
            'branch_name' => $_SESSION['branch_name'],
            'exp' => time() + (86400 * 7) // 7 days expiration
        ];

        $json = json_encode($payload);
        $signature = hash_hmac('sha256', $json, AUTH_SECRET);
        $token = base64_encode($json) . '.' . $signature;

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('tc_session_token', $token, [
            'expires' => time() + (86400 * 7),
            'path' => '/',
            'httponly' => true,
            'secure' => $isHttps,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Restore session from signed cookie if session file is absent in cold serverless container
     */
    public static function restoreSessionFromCookie(): void {
        if (!empty($_SESSION['user_id'])) {
            return;
        }

        if (empty($_COOKIE['tc_session_token'])) {
            return;
        }

        $parts = explode('.', $_COOKIE['tc_session_token'], 2);
        if (count($parts) !== 2) {
            return;
        }

        [$b64Payload, $receivedSig] = $parts;
        $json = base64_decode($b64Payload, true);
        if (!$json) {
            return;
        }

        $expectedSig = hash_hmac('sha256', $json, AUTH_SECRET);
        if (!hash_equals($expectedSig, $receivedSig)) {
            return;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['user_id']) || empty($payload['exp']) || $payload['exp'] < time()) {
            return;
        }

        $_SESSION['user_id'] = (int)$payload['user_id'];
        $_SESSION['username'] = $payload['username'] ?? '';
        $_SESSION['full_name'] = $payload['full_name'] ?? '';
        $_SESSION['role_id'] = (int)($payload['role_id'] ?? 1);
        $_SESSION['role_name'] = $payload['role_name'] ?? 'cashier';
        $_SESSION['branch_id'] = (int)($payload['branch_id'] ?? 1);
        $_SESSION['branch_name'] = $payload['branch_name'] ?? 'TC Central Supermarket';
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        setcookie('tc_session_token', '', [
            'expires' => time() - 3600,
            'path' => '/'
        ]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    public static function jsonSuccess(mixed $data = null, string $message = 'Success'): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    public static function jsonError(string $error, int $statusCode = 400, mixed $details = null): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $error,
            'details' => $details
        ]);
        exit;
    }
}

// Auto-restore session from stateless token on script initialization
Auth::restoreSessionFromCookie();
