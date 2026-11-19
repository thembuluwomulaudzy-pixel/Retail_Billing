<?php
// =====================================================================
// TC LIMITED - Vercel Serverless Entrypoint & Front-Controller
// Routes requests to index.php, login.php, test_suite.php or API endpoints
// =====================================================================

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = trim($uri, '/');

// Handle API endpoints if routed here
if (str_starts_with($uri, 'api/')) {
    $script = substr($uri, 4);
    $apiFile = __DIR__ . '/' . $script;
    if (file_exists($apiFile)) {
        require $apiFile;
        exit;
    }
}

// Route based on path
if ($uri === 'login' || $uri === 'login.php' || str_contains($uri, 'login')) {
    require __DIR__ . '/../login.php';
} elseif ($uri === 'test' || $uri === 'test_suite' || $uri === 'test_suite.php' || str_contains($uri, 'test_suite')) {
    require __DIR__ . '/../test_suite.php';
} else {
    // Root or index.php
    require __DIR__ . '/../index.php';
}
