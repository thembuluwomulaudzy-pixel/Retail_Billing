<?php
// =====================================================================
// TC LIMITED - Reporting & Analytics API (ABC, FIFO/WAC, Margins, Forecast)
// =====================================================================

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$action = $_GET['action'] ?? 'dashboard';
$branchId = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1);

switch ($action) {
    // 1. Executive Dashboard KPIs
    case 'dashboard':
        // Total Stock Value (FIFO/Current Cost)
        $valStmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(i.quantity * pb.unit_cost), 0) as total_inventory_cost,
                COALESCE(SUM(i.quantity * p.selling_price), 0) as total_inventory_retail,
                COALESCE(SUM(i.quantity), 0) as total_physical_units
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            JOIN product_batches pb ON i.batch_id = pb.id
            WHERE i.branch_id = ?
        ");
        $valStmt->execute([$branchId]);
        $val = $valStmt->fetch();

        // Low stock count (<= reorder point)
        $lowStmt = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT p.id
                FROM products p
                LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
                WHERE p.is_active = TRUE
                GROUP BY p.id, p.reorder_point
                HAVING COALESCE(SUM(i.quantity), 0) <= p.reorder_point
            ) sub
        ");
        $lowStmt->execute([$branchId]);
        $lowStockCount = (int)$lowStmt->fetchColumn();

        // Expiring Soon Batches (within 7 days)
        $expStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT pb.id)
            FROM product_batches pb
            JOIN inventory i ON pb.id = i.batch_id AND i.branch_id = ?
            WHERE i.quantity > 0 AND (pb.expiry_date - CURRENT_DATE) <= 7
        ");
        $expStmt->execute([$branchId]);
        $expiringCount = (int)$expStmt->fetchColumn();

        // Monthly Spoilage & Wastage Loss
        $wasteStmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(cost_loss), 0) as total_loss,
                COALESCE(SUM(quantity), 0) as total_wasted_units
            FROM wastage_logs
            WHERE branch_id = ? AND logged_at >= DATE_TRUNC('month', CURRENT_DATE)
        ");
        $wasteStmt->execute([$branchId]);
        $waste = $wasteStmt->fetch();

        // Sales Today
        $salesStmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) as revenue_today,
                COUNT(id) as transactions_today
            FROM sales
            WHERE branch_id = ? AND sale_time >= CURRENT_DATE AND status = 'COMPLETED'
        ");
        $salesStmt->execute([$branchId]);
        $todaySales = $salesStmt->fetch();

        // Category Breakdown for Chart
        $catStmt = $pdo->prepare("
            SELECT c.name as category_name, COALESCE(SUM(i.quantity * pb.unit_cost), 0) as category_value
            FROM categories c
            JOIN products p ON c.id = p.category_id
            JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
            JOIN product_batches pb ON i.batch_id = pb.id
            GROUP BY c.id, c.name
            ORDER BY category_value DESC
        ");
        $catStmt->execute([$branchId]);
        $categoryBreakdown = $catStmt->fetchAll();

        Auth::jsonSuccess([
            'inventory_cost_value' => (float)$val['total_inventory_cost'],
            'inventory_retail_value' => (float)$val['total_inventory_retail'],
            'total_units' => (float)$val['total_physical_units'],
            'low_stock_items' => $lowStockCount,
            'expiring_batches_count' => $expiringCount,
            'monthly_wastage_cost' => (float)$waste['total_loss'],
            'today_revenue' => (float)$todaySales['revenue_today'],
            'today_transactions' => (int)$todaySales['transactions_today'],
            'category_breakdown' => $categoryBreakdown
        ]);
        break;

    // 2. ABC Inventory Analysis (Pareto Classification)
    case 'abc_analysis':
        // Calculate cumulative annual / historical revenue contribution per product
        $stmt = $pdo->prepare("
            SELECT 
                p.id as product_id, p.sku, p.name as product_name, c.name as category_name,
                COALESCE(SUM(si.quantity), 0) as units_sold,
                COALESCE(SUM(si.line_total), 0) as total_revenue,
                COALESCE(SUM(i.quantity * p.cost_price), 0) as holding_value
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN sale_items si ON p.id = si.product_id
            LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
            WHERE p.is_active = TRUE
            GROUP BY p.id, p.sku, p.name, c.name
            ORDER BY total_revenue DESC, holding_value DESC
        ");
        $stmt->execute([$branchId]);
        $products = $stmt->fetchAll();

        $totalRevenue = array_sum(array_column($products, 'total_revenue'));
        if ($totalRevenue == 0) $totalRevenue = 1; // Prevent division by zero

        $runningRevenue = 0.0;
        foreach ($products as &$p) {
            $rev = (float)$p['total_revenue'];
            $runningRevenue += $rev;
            $cumPct = round(($runningRevenue / $totalRevenue) * 100, 2);
            $p['cumulative_percentage'] = $cumPct;

            if ($cumPct <= 80) {
                $p['abc_class'] = 'A';
                $p['management_priority'] = 'High Priority (Tight Daily Control & Strict FEFO)';
            } elseif ($cumPct <= 95) {
                $p['abc_class'] = 'B';
                $p['management_priority'] = 'Medium Priority (Weekly Review)';
            } else {
                $p['abc_class'] = 'C';
                $p['management_priority'] = 'Low Priority (Periodic Bulk Reorder)';
            }
        }

        Auth::jsonSuccess($products);
        break;

    // 3. Stock Valuation: FIFO vs Weighted Average Cost (WAC)
    case 'valuation':
        $stmt = $pdo->prepare("
            SELECT 
                p.id as product_id, p.sku, p.name, p.uom, c.name as category_name,
                COALESCE(SUM(i.quantity), 0) as total_stock,
                -- FIFO: Sum of remaining units * their specific batch unit cost
                COALESCE(SUM(i.quantity * pb.unit_cost), 0) as fifo_valuation,
                -- Weighted Average Cost: Total cost / Total units
                CASE 
                    WHEN SUM(i.quantity) > 0 THEN SUM(i.quantity * pb.unit_cost) / SUM(i.quantity)
                    ELSE p.cost_price 
                END as weighted_avg_unit_cost,
                -- Retail valuation
                COALESCE(SUM(i.quantity * p.selling_price), 0) as retail_valuation
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
            LEFT JOIN product_batches pb ON i.batch_id = pb.id
            WHERE p.is_active = TRUE
            GROUP BY p.id, p.sku, p.name, p.uom, c.name, p.cost_price, p.selling_price
            HAVING COALESCE(SUM(i.quantity), 0) > 0
            ORDER BY fifo_valuation DESC
        ");
        $stmt->execute([$branchId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $stock = (float)$r['total_stock'];
            $fifo = (float)$r['fifo_valuation'];
            $wacUnit = (float)$r['weighted_avg_unit_cost'];
            $r['wac_valuation'] = round($stock * $wacUnit, 2);
            $r['potential_gross_profit'] = round((float)$r['retail_valuation'] - $fifo, 2);
        }

        Auth::jsonSuccess($rows);
        break;

    // 4. Fast & Slow Movers
    case 'movers':
        $days = (int)($_GET['days'] ?? 30);
        $stmt = $pdo->prepare("
            SELECT 
                p.id as product_id, p.sku, p.name, c.name as category_name,
                COALESCE(SUM(si.quantity), 0) as units_sold,
                COALESCE(SUM(si.line_total), 0) as revenue_generated,
                COUNT(DISTINCT si.sale_id) as transaction_frequency,
                COALESCE(SUM(i.quantity), 0) as current_stock
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN sale_items si ON p.id = si.product_id
            LEFT JOIN sales s ON si.sale_id = s.id AND s.branch_id = ? AND s.sale_time >= CURRENT_DATE - (INTERVAL '1 day' * ?)
            LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
            WHERE p.is_active = TRUE
            GROUP BY p.id, p.sku, p.name, c.name
            ORDER BY units_sold DESC
        ");
        $stmt->execute([$branchId, $days, $branchId]);
        $all = $stmt->fetchAll();

        $fastMovers = array_slice($all, 0, 10);
        $slowMovers = array_reverse(array_slice($all, -10));

        Auth::jsonSuccess([
            'fast_movers' => $fastMovers,
            'slow_movers' => $slowMovers
        ]);
        break;

    // 5. Demand Forecasting & Seasonality Trends
    case 'forecasting':
        // Calculate average daily burn rate over past 30 days and Days of Inventory (DOI)
        $stmt = $pdo->prepare("
            SELECT 
                p.id as product_id, p.sku, p.name, p.reorder_point, p.max_stock_level,
                c.name as category_name,
                COALESCE(SUM(i.quantity), 0) as current_stock,
                COALESCE(sales_summary.units_sold_30d, 0) as units_sold_30d,
                ROUND(COALESCE(sales_summary.units_sold_30d, 0) / 30.0, 2) as daily_burn_rate
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
            LEFT JOIN (
                SELECT si.product_id, SUM(si.quantity) as units_sold_30d
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.id
                WHERE s.branch_id = ? AND s.sale_time >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY si.product_id
            ) sales_summary ON p.id = sales_summary.product_id
            WHERE p.is_active = TRUE
            GROUP BY p.id, p.sku, p.name, p.reorder_point, p.max_stock_level, c.name, sales_summary.units_sold_30d
            ORDER BY daily_burn_rate DESC
        ");
        $stmt->execute([$branchId, $branchId]);
        $forecasts = $stmt->fetchAll();

        foreach ($forecasts as &$f) {
            $burn = (float)$f['daily_burn_rate'];
            $stock = (float)$f['current_stock'];
            if ($burn > 0) {
                $doi = round($stock / $burn, 1);
                $f['days_of_inventory'] = $doi;
                $f['projected_runout_date'] = date('Y-m-d', strtotime("+{$doi} days"));
                if ($doi <= 3) {
                    $f['stockout_risk'] = 'CRITICAL (Immediate Stockout Risk)';
                } elseif ($doi <= 7) {
                    $f['stockout_risk'] = 'WARNING (Reorder Needed)';
                } else {
                    $f['stockout_risk'] = 'HEALTHY';
                }
            } else {
                $f['days_of_inventory'] = 999;
                $f['projected_runout_date'] = 'No sales trend';
                $f['stockout_risk'] = 'DORMANT (Slow or No Sales)';
            }
        }

        Auth::jsonSuccess($forecasts);
        break;

    // 6. Gross Margin by Product & Category
    case 'margins':
        $stmt = $pdo->prepare("
            SELECT 
                c.name as category_name,
                p.id as product_id, p.sku, p.name as product_name,
                COALESCE(SUM(si.quantity), 0) as units_sold,
                COALESCE(SUM(si.line_total), 0) as total_revenue,
                COALESCE(SUM(si.quantity * si.unit_cost), 0) as total_cogs,
                COALESCE(SUM(si.line_total - (si.quantity * si.unit_cost)), 0) as gross_profit,
                CASE 
                    WHEN SUM(si.line_total) > 0 
                    THEN ROUND((SUM(si.line_total - (si.quantity * si.unit_cost)) / SUM(si.line_total)) * 100, 2)
                    ELSE 0 
                END as gross_margin_percentage
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN sale_items si ON p.id = si.product_id
            LEFT JOIN sales s ON si.sale_id = s.id AND s.branch_id = ?
            WHERE p.is_active = TRUE
            GROUP BY c.name, p.id, p.sku, p.name
            HAVING COALESCE(SUM(si.quantity), 0) > 0
            ORDER BY gross_profit DESC
        ");
        $stmt->execute([$branchId]);
        Auth::jsonSuccess($stmt->fetchAll());
        break;

    default:
        Auth::jsonError('Invalid report action.');
}
