<?php
// =====================================================================
// TC LIMITED - Unified Serverless Gateway & Front Controller
// Bypasses Vercel Hobby 12-function limit by serving all routes via 1 function.
// =====================================================================

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);

// Clean path (strip trailing/leading slashes and local XAMPP subdirectories if present)
$cleanPath = trim($path, '/');
if (str_starts_with($cleanPath, 'Retail_Billing/')) {
    $cleanPath = substr($cleanPath, 15);
}

// 1. API Routing: /api/{module}.php or /api/{module}
if (str_starts_with($cleanPath, 'api/')) {
    $module = substr($cleanPath, 4); // remove 'api/'
    if (str_ends_with($module, '.php')) {
        $module = substr($module, 0, -4);
    }
    
    // Safety check: allow only alphanumeric and underscore module names
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $module)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid API module name.']);
        exit;
    }
    
    $handler = __DIR__ . '/../backend/api/' . $module . '.php';
    if (file_exists($handler)) {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../config/auth.php';
        require $handler;
        exit;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "API endpoint '{$module}' not found."]);
        exit;
    }
}

// 2. Front-End Web Page Routing
if ($cleanPath === 'login' || $cleanPath === 'login.php' || str_contains($cleanPath, 'login')) {
    require __DIR__ . '/../login.php';
} elseif ($cleanPath === 'test' || $cleanPath === 'test_suite' || $cleanPath === 'test_suite.php' || str_contains($cleanPath, 'test_suite')) {
    require __DIR__ . '/../test_suite.php';
} else {
    // Root or dashboard
    require __DIR__ . '/../index.php';
}
