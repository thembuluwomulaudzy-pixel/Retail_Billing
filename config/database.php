<?php
// =====================================================================
// TC LIMITED - Database Configuration & Serverless Cloud PostgreSQL Helper
// Supports local XAMPP PostgreSQL & Cloud PostgreSQL (Neon, Supabase, Vercel Postgres)
// =====================================================================

class Database {
    private static ?PDO $instance = null;

    /**
     * Resolves connection credentials from environment variables:
     * - POSTGRES_URL / DATABASE_URL (standard connection strings)
     * - POSTGRES_PRISMA_URL / POSTGRES_URL_NON_POOLING
     * - DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS (standard env vars)
     */
    public static function getDsnAndCredentials(): array {
        $dbUrl = getenv('POSTGRES_URL') 
            ?: getenv('DATABASE_URL') 
            ?: getenv('POSTGRES_PRISMA_URL') 
            ?: getenv('POSTGRES_URL_NON_POOLING') 
            ?: '';

        if (!empty($dbUrl)) {
            $parts = parse_url($dbUrl);
            $host = $parts['host'] ?? '127.0.0.1';
            $port = $parts['port'] ?? 5432;
            $user = isset($parts['user']) ? urldecode($parts['user']) : 'postgres';
            $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';
            $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : 'tc_grocery';
            
            $query = [];
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
            }
            $sslmode = $query['sslmode'] ?? (($host !== '127.0.0.1' && $host !== 'localhost') ? 'require' : null);
        } else {
            $host = getenv('DB_HOST') ?: getenv('POSTGRES_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: getenv('POSTGRES_PORT') ?: '5432';
            $dbname = getenv('DB_NAME') ?: getenv('POSTGRES_DATABASE') ?: 'tc_grocery';
            $user = getenv('DB_USER') ?: getenv('POSTGRES_USER') ?: 'postgres';
            $pass = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: getenv('POSTGRES_PASSWORD') ?: 'postgres';
            $sslmode = ($host !== '127.0.0.1' && $host !== 'localhost') ? 'require' : null;
        }

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        if ($sslmode) {
            $dsn .= ";sslmode={$sslmode}";
        }

        return [$dsn, $user, $pass];
    }

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            [$dsn, $user, $pass] = self::getDsnAndCredentials();

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
                self::ensureInitialized(self::$instance);
            } catch (PDOException $e) {
                // If requested via API, return clean JSON error
                if (php_sapi_name() !== 'cli' && (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                    header('Content-Type: application/json', true, 500);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Database connection failed: ' . $e->getMessage(),
                        'hint' => 'Ensure POSTGRES_URL or DATABASE_URL is set in Vercel project environment variables.'
                    ]);
                    exit;
                }
                throw new Exception("PostgreSQL Database Connection Error: " . $e->getMessage());
            }
        }
        return self::$instance;
    }

    /**
     * Auto-migrate / auto-seed fresh cloud databases (e.g., Neon or Supabase)
     * Checks if the `users` table exists. If not, automatically executes schema.sql and seeds data.
     */
    public static function ensureInitialized(PDO $pdo): void {
        try {
            $check = $pdo->query("SELECT to_regclass('public.users')")->fetchColumn();
            if (!$check) {
                // Fresh database detected: initialize tables and initial sample data
                $schemaFile = __DIR__ . '/../database/schema.sql';
                if (file_exists($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    $pdo->exec($sql);
                }
                $seedFile = __DIR__ . '/../database/seed.php';
                if (file_exists($seedFile)) {
                    require_once $seedFile;
                    if (function_exists('runGrocerySeeder')) {
                        runGrocerySeeder($pdo);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Database initialization check notice: " . $e->getMessage());
        }
    }

    /**
     * Helper to log stock movements (Immutable Audit Trail)
     */
    public static function logStockMovement(
        PDO $pdo,
        int $branchId,
        int $productId,
        int $batchId,
        string $locationType,
        string $movementType,
        string $referenceId,
        float $qtyBefore,
        float $qtyChange,
        float $qtyAfter,
        float $unitCost,
        ?int $userId = null,
        ?string $notes = null
    ): void {
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements 
            (branch_id, product_id, batch_id, location_type, movement_type, reference_id, quantity_before, quantity_change, quantity_after, unit_cost, user_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $branchId,
            $productId,
            $batchId,
            $locationType,
            $movementType,
            $referenceId,
            $qtyBefore,
            $qtyChange,
            $qtyAfter,
            $unitCost,
            $userId,
            $notes
        ]);
    }
}
