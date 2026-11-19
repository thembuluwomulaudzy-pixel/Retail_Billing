<?php
// =====================================================================
// TC LIMITED - Realistic Grocery Inventory Database Seeder
// =====================================================================

require_once __DIR__ . '/../config/database.php';

function runGrocerySeeder(PDO $pdo): void {
    echo "Connecting to PostgreSQL...\n";

    // Clean existing data in logical order
    $tables = [
        'stock_movements', 'branch_transfer_items', 'branch_transfers',
        'stocktake_items', 'stocktakes', 'wastage_logs', 'sale_returns',
        'sale_items', 'sales', 'promotions', 'goods_received_items',
        'goods_received_notes', 'purchase_order_items', 'purchase_orders',
        'supplier_products', 'suppliers', 'inventory', 'product_batches',
        'products', 'categories', 'users', 'roles', 'locations', 'branches'
    ];
    foreach ($tables as $t) {
        $pdo->exec("TRUNCATE TABLE $t RESTART IDENTITY CASCADE;");
    }
    echo "Tables truncated.\n";

    // 1. Roles
    $roles = [
        ['admin', 'Full administrative authority across all branches and modules'],
        ['manager', 'Branch manager: purchasing, transfers, audits, promotions, and reports'],
        ['storekeeper', 'Warehouse and receiving: GRN, cycle counts, wastage logging, shelf stocking'],
        ['cashier', 'POS operator: sales checkout, item search, customer returns, price check']
    ];
    $stmtRole = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
    foreach ($roles as $r) {
        $stmtRole->execute($r);
    }
    echo "Roles seeded.\n";

    // 2. Branches
    $branches = [
        ['BR-01', 'TC Supermarket - Central Branch', '104 Jason Moyo Ave, Harare CBD', '+263 242 700101'],
        ['BR-02', 'TC Express - Westgate Mall', 'Shop 42, Westgate Shopping Complex', '+263 242 303202'],
        ['BR-03', 'TC Fresh Mart - Borrowdale', 'Borrowdale Brooke Village, Harare', '+263 242 885400']
    ];
    $stmtBranch = $pdo->prepare("INSERT INTO branches (code, name, address, phone) VALUES (?, ?, ?, ?)");
    foreach ($branches as $b) {
        $stmtBranch->execute($b);
    }
    echo "Branches seeded.\n";

    // 3. Locations (SHELF and BACKROOM per branch)
    $stmtLoc = $pdo->prepare("INSERT INTO locations (branch_id, type, aisle_bay) VALUES (?, ?, ?)");
    for ($bId = 1; $bId <= 3; $bId++) {
        $stmtLoc->execute([$bId, 'SHELF', "Main Sales Floor - Aisle 1-8"]);
        $stmtLoc->execute([$bId, 'BACKROOM', "Cold Room & Pallet Racks"]);
    }
    echo "Locations seeded.\n";

    // 4. Users (Password: password123)
    $pwdHash = password_hash('password123', PASSWORD_BCRYPT);
    $users = [
        [1, null, 'admin', 'Tendai Chidziwa (Admin)', 'admin@tclimited.co.zw', $pwdHash, '1234'],
        [2, 1, 'manager', 'Rudo Moyo (Store Manager)', 'manager@tclimited.co.zw', $pwdHash, '2345'],
        [3, 1, 'storekeeper', 'Farai Ncube (Storekeeper)', 'storekeeper@tclimited.co.zw', $pwdHash, '3456'],
        [4, 1, 'cashier', 'Tariro Sibanda (Cashier)', 'cashier@tclimited.co.zw', $pwdHash, '4567'],
        [2, 2, 'manager_westgate', 'Chipo Mutasa (Westgate Mgr)', 'chipo@tclimited.co.zw', $pwdHash, '5678'],
        [4, 2, 'cashier_westgate', 'Kudakwashe Dube (Cashier)', 'kuda@tclimited.co.zw', $pwdHash, '6789']
    ];
    $stmtUser = $pdo->prepare("INSERT INTO users (role_id, branch_id, username, full_name, email, password_hash, pin_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($users as $u) {
        $stmtUser->execute($u);
    }
    echo "Users seeded.\n";

    // 5. Categories
    $categories = [
        ['Dairy & Eggs', 'DAIR', 0.00, 14],
        ['Fresh Produce', 'PROD', 0.00, 7],
        ['Bakery & Pastry', 'BAKE', 0.00, 4],
        ['Butchery & Seafood', 'MEAT', 0.00, 6],
        ['Pantry & Canned', 'PANT', 15.00, 180],
        ['Beverages & Juices', 'BEV', 15.00, 120],
        ['Frozen Foods', 'FROZ', 15.00, 90],
        ['Household & Cleaning', 'HOUS', 15.00, 365]
    ];
    $stmtCat = $pdo->prepare("INSERT INTO categories (name, code, default_tax_rate, shelf_life_days_default) VALUES (?, ?, ?, ?)");
    foreach ($categories as $c) {
        $stmtCat->execute($c);
    }
    echo "Categories seeded.\n";

    // 6. Suppliers
    $suppliers = [
        ['SUP-01', 'Golden Meadow Dairy Co', 'Tinashe Chikosi', 'orders@goldenmeadow.co.zw', '+263 772 100200', 1, 'Net 15', 4.9],
        ['SUP-02', 'FreshHarvest Farms Ltd', 'Grace Marufu', 'sales@freshharvest.co.zw', '+263 773 200300', 2, 'Net 7', 4.8],
        ['SUP-03', 'Sunrise Bakery Mills', 'Patrick Dzingai', 'dispatch@sunrisebakery.co.zw', '+263 774 300400', 1, 'Net 15', 4.7],
        ['SUP-04', 'PrimeCuts Butchery & Poultry', 'David Ndlovu', 'orders@primecuts.co.zw', '+263 771 400500', 2, 'Net 7', 4.6],
        ['SUP-05', 'National Foods & Pantry Ltd', 'Alistair Smith', 'national@natfoods.co.zw', '+263 242 620000', 4, 'Net 30', 4.5],
        ['SUP-06', 'PureFlow Springs & Drinks', 'Brenda Mpofu', 'sales@pureflow.co.zw', '+263 775 600700', 3, 'Net 30', 4.8]
    ];
    $stmtSup = $pdo->prepare("INSERT INTO suppliers (code, name, contact_person, email, phone, lead_time_days, payment_terms, rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($suppliers as $s) {
        $stmtSup->execute($s);
    }
    echo "Suppliers seeded.\n";

    // 7. Products (20 items)
    // [category_id, sku, barcode, name, desc, uom, is_perishable, cost, price, reorder, min, max, shelf_life, alert_days]
    $products = [
        // Dairy (cat 1)
        [1, 'DAIR-MILK-1L', '8901001001', 'Fresh Whole Milk 1L', 'Pasteurized homogenized full-cream cow milk', 'litres', true, 1.20, 1.65, 25, 10, 100, 7, 2],
        [1, 'DAIR-YOG-500G', '8901001002', 'Plain Greek Yogurt 500g', 'Creamy unsweetened high-protein Greek yogurt', 'unit', true, 1.50, 2.10, 15, 5, 60, 14, 3],
        [1, 'DAIR-EGG-30', '8901001003', 'Farm Fresh Large Eggs 30-Pack', 'Grade A large brown eggs tray', 'pack', true, 3.80, 5.00, 20, 8, 80, 21, 4],
        [1, 'DAIR-CHS-250G', '8901001004', 'Mature Cheddar Cheese 250g', 'Aged creamy mature cheddar block', 'unit', true, 3.20, 4.50, 12, 5, 45, 60, 5],
        
        // Produce (cat 2)
        [2, 'PROD-APP-1KG', '8902001001', 'Red Crisp Apples 1kg', 'Hand-picked crisp sweet red apples prepacked', 'kg', true, 1.80, 2.60, 20, 10, 80, 14, 3],
        [2, 'PROD-TOM-1KG', '8902001002', 'Roma Salad Tomatoes 1kg', 'Firm fresh ripe tomatoes for cooking and salads', 'kg', true, 1.10, 1.75, 30, 15, 100, 7, 2],
        [2, 'PROD-LET-UNIT', '8902001003', 'Fresh Iceberg Lettuce', 'Crisp whole head green iceberg lettuce', 'unit', true, 0.70, 1.10, 15, 5, 50, 5, 2],
        
        // Bakery (cat 3)
        [3, 'BAKE-BRD-WHT', '8903001001', 'Sliced White Sandwich Bread 800g', 'Freshly baked sliced soft white bread loaf', 'unit', true, 0.85, 1.20, 30, 10, 120, 4, 1],
        [3, 'BAKE-BRD-BRN', '8903001002', 'Whole Wheat Brown Bread 800g', 'High fibre whole grain health bread', 'unit', true, 0.90, 1.30, 25, 10, 90, 4, 1],
        [3, 'BAKE-CRS-4PK', '8903001003', 'Butter Croissants 4-Pack', 'Golden flaky french butter pastry', 'pack', true, 2.00, 3.20, 10, 4, 30, 3, 1],
        
        // Butchery (cat 4)
        [4, 'MEAT-CHK-WHL', '8904001001', 'Fresh Whole Dressed Chicken 1.4kg', 'Plump tender farm raised grain-fed chicken', 'kg', true, 4.50, 6.20, 20, 8, 60, 5, 2],
        [4, 'MEAT-BEEF-TBN', '8904001002', 'Prime Beef T-Bone Steak 500g', 'Tender aged grass-fed beef steak cuts', 'unit', true, 5.50, 7.90, 15, 5, 40, 5, 2],
        
        // Pantry (cat 5)
        [5, 'PANT-RICE-2KG', '8905001001', 'Long Grain White Rice 2kg', 'Triple polished premium long grain parboiled rice', 'pack', false, 2.30, 3.30, 30, 12, 150, 365, 30],
        [5, 'PANT-OIL-2L', '8905001002', 'Pure Sunflower Cooking Oil 2L', '100% triple refined cholesterol-free cooking oil', 'unit', false, 3.80, 5.20, 25, 10, 100, 365, 30],
        [5, 'PANT-PST-500G', '8905001003', 'Spaghetti Pasta 500g', 'Durum wheat semolina classic spaghetti', 'pack', false, 0.90, 1.40, 35, 15, 120, 365, 30],
        [5, 'PANT-BN-410G', '8905001004', 'Baked Beans in Tomato Sauce 410g', 'Rich savory canned navy beans', 'can', false, 0.80, 1.25, 25, 10, 100, 730, 60],
        
        // Beverages (cat 6)
        [6, 'BEV-H2O-1.5L', '8906001001', 'Natural Spring Water 1.5L', 'Still bottled pristine mountain mineral water', 'unit', false, 0.50, 0.85, 40, 20, 200, 365, 30],
        [6, 'BEV-OJ-1L', '8906001002', '100% Pure Orange Juice 1L', 'Chilled pure squeezed orange juice with pulp', 'unit', true, 1.60, 2.40, 20, 8, 70, 30, 5],
        [6, 'BEV-COLA-2L', '8906001003', 'Classic Cola Soda 2L', 'Carbonated sparkling refreshment 2L bottle', 'unit', false, 1.40, 2.10, 30, 12, 120, 180, 20],
        
        // Frozen (cat 7)
        [7, 'FROZ-CORN-1KG', '8907001001', 'Frozen Sweet Corn Kernels 1kg', 'Quick frozen golden juicy sweetcorn', 'pack', false, 2.10, 3.00, 15, 5, 50, 180, 15],
        [7, 'FROZ-FRY-1KG', '8907001002', 'Crispy Potato French Fries 1kg', 'Straight cut blanched ready to fry potatoes', 'pack', false, 2.40, 3.50, 20, 8, 60, 180, 15]
    ];

    $stmtProd = $pdo->prepare("
        INSERT INTO products 
        (category_id, sku, barcode, name, description, uom, is_perishable, cost_price, selling_price, reorder_point, min_stock_level, max_stock_level, shelf_life_days, alert_before_expiry_days)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($products as $p) {
        $p[6] = $p[6] ? 'true' : 'false';
        $stmtProd->execute($p);
    }
    echo "Products seeded (" . count($products) . " items).\n";

    // 8. Supplier Products Mapping
    $supplierMapping = [
        // Dairy supplier (1)
        [1, 1, 'GM-M1L', 1.15, 1],
        [1, 2, 'GM-Y500', 1.45, 1],
        [1, 3, 'GM-E30', 3.70, 1],
        [1, 4, 'GM-C250', 3.10, 2],
        // Produce supplier (2)
        [2, 5, 'FH-APP', 1.70, 2],
        [2, 6, 'FH-TOM', 1.00, 1],
        [2, 7, 'FH-LET', 0.65, 1],
        // Bakery supplier (3)
        [3, 8, 'SB-WHT', 0.80, 1],
        [3, 9, 'SB-BRN', 0.85, 1],
        [3, 10, 'SB-CRS', 1.90, 1],
        // Butchery supplier (4)
        [4, 11, 'PC-CHK', 4.30, 2],
        [4, 12, 'PC-TBN', 5.20, 2],
        // National Foods (5)
        [5, 13, 'NF-R2KG', 2.20, 3],
        [5, 14, 'NF-O2L', 3.65, 4],
        [5, 15, 'NF-P500', 0.85, 3],
        [5, 16, 'NF-B410', 0.75, 4],
        // Beverage supplier (6)
        [6, 17, 'PF-W15', 0.45, 2],
        [6, 18, 'PF-OJ1', 1.50, 2],
        [6, 19, 'PF-C2L', 1.30, 2],
        // Frozen with NatFoods (5)
        [5, 20, 'NF-CORN', 1.95, 3],
        [5, 21, 'NF-FRY', 2.25, 3]
    ];
    $stmtSupProd = $pdo->prepare("INSERT INTO supplier_products (supplier_id, product_id, supplier_sku, contract_price, lead_time_days) VALUES (?, ?, ?, ?, ?)");
    foreach ($supplierMapping as $sm) {
        $stmtSupProd->execute($sm);
    }
    echo "Supplier products mapped.\n";

    // 9. Batches (Lot Tracking, Expiry, FEFO)
    // Create multiple batches per product:
    // Batch A: Expiring very soon (e.g. 2-3 days ahead) -> for Near-Expiry alerts, FEFO picking, markdowns!
    // Batch B: Fresh batch (healthy expiry)
    // Batch C: Depleted or expired
    $today = new DateTime();
    $stmtBatch = $pdo->prepare("
        INSERT INTO product_batches 
        (product_id, batch_number, manufacture_date, expiry_date, supplier_id, unit_cost, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");

    $batchIds = [];

    // Loop through each product and create realistic batches
    for ($pId = 1; $pId <= count($products); $pId++) {
        $isPerish = $products[$pId - 1][6];
        $cost = $products[$pId - 1][7];
        
        if ($isPerish) {
            // Batch 1: Expiring in 2 days (Near Expiry!)
            $mfg1 = (clone $today)->modify('-4 days')->format('Y-m-d');
            $exp1 = (clone $today)->modify('+2 days')->format('Y-m-d');
            $stmtBatch->execute([$pId, "LOT-".date('Ym')."-A".$pId, $mfg1, $exp1, 1, $cost, 'EXPIRING_SOON']);
            $b1 = $stmtBatch->fetchColumn();
            $batchIds[$pId][] = ['id' => $b1, 'expiry' => $exp1, 'expiring' => true, 'cost' => $cost];

            // Batch 2: Fresh batch expiring in 10-25 days
            $mfg2 = (clone $today)->modify('-1 day')->format('Y-m-d');
            $exp2 = (clone $today)->modify('+14 days')->format('Y-m-d');
            $stmtBatch->execute([$pId, "LOT-".date('Ym')."-B".$pId, $mfg2, $exp2, 1, $cost, 'ACTIVE']);
            $b2 = $stmtBatch->fetchColumn();
            $batchIds[$pId][] = ['id' => $b2, 'expiry' => $exp2, 'expiring' => false, 'cost' => $cost];
        } else {
            // Non-perishable: Expiring in 180 to 365 days
            $mfg = (clone $today)->modify('-30 days')->format('Y-m-d');
            $exp = (clone $today)->modify('+300 days')->format('Y-m-d');
            $stmtBatch->execute([$pId, "LOT-".date('Ym')."-STD".$pId, $mfg, $exp, 5, $cost, 'ACTIVE']);
            $b = $stmtBatch->fetchColumn();
            $batchIds[$pId][] = ['id' => $b, 'expiry' => $exp, 'expiring' => false, 'cost' => $cost];
        }
    }
    echo "Batches created.\n";

    // 10. Inventory Seed (Split between SHELF and BACKROOM across branches)
    $stmtInv = $pdo->prepare("
        INSERT INTO inventory (branch_id, location_type, product_id, batch_id, quantity)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($batchIds as $pId => $batches) {
        $pInfo = $products[$pId - 1];
        $reorderPt = $pInfo[9];
        
        // For Product 8 (Sliced White Bread), let's intentionally set stock LOW (e.g. 6 packs)
        // so it triggers the Low Stock Alert and Auto PO generation!
        $isLowStockTest = ($pId === 8);

        foreach ($batches as $idx => $b) {
            $batchId = $b['id'];
            if ($isLowStockTest) {
                // Shelf has 4, backroom has 2 -> total 6, below reorder point of 30!
                $shelfQty = 4.0;
                $backroomQty = 2.0;
            } elseif ($b['expiring']) {
                // Near expiry batch: 12 on shelf, 5 in backroom
                $shelfQty = 12.0;
                $backroomQty = 5.0;
            } else {
                // Fresh batch: 35 on shelf, 40 in backroom
                $shelfQty = 35.0;
                $backroomQty = 40.0;
            }

            // Branch 1 (Central)
            $stmtInv->execute([1, 'SHELF', $pId, $batchId, $shelfQty]);
            $stmtInv->execute([1, 'BACKROOM', $pId, $batchId, $backroomQty]);

            // Branch 2 (Westgate)
            $stmtInv->execute([2, 'SHELF', $pId, $batchId, round($shelfQty * 0.6)]);
            $stmtInv->execute([2, 'BACKROOM', $pId, $batchId, round($backroomQty * 0.6)]);

            // Audit log initial stock load
            Database::logStockMovement($pdo, 1, $pId, $batchId, 'SHELF', 'INTERNAL_CORRECTION', 'INIT-SETUP', 0, $shelfQty, $shelfQty, $b['cost'], 1, 'Initial inventory setup');
            Database::logStockMovement($pdo, 1, $pId, $batchId, 'BACKROOM', 'INTERNAL_CORRECTION', 'INIT-SETUP', 0, $backroomQty, $backroomQty, $b['cost'], 1, 'Initial inventory setup');
        }
    }
    echo "Inventory & initial movements seeded.\n";

    // 11. Promotions (Markdown for Near-Expiry products)
    $stmtPromo = $pdo->prepare("
        INSERT INTO promotions 
        (product_id, batch_id, title, discount_percent, discount_amount, start_date, end_date, is_active, reason)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // Milk Near-Expiry 30% markdown
    $milkBatch1 = $batchIds[1][0]['id'];
    $stmtPromo->execute([1, $milkBatch1, 'Fresh Milk Near-Expiry Clearance - 30% Off', 30.00, 0.00, date('Y-m-d'), (clone $today)->modify('+2 days')->format('Y-m-d'), true, 'NEAR_EXPIRY']);
    // Produce Weekend Special
    $stmtPromo->execute([5, null, 'Crisp Apples Weekend Saver 15% Off', 15.00, 0.00, date('Y-m-d'), (clone $today)->modify('+5 days')->format('Y-m-d'), true, 'PROMOTIONAL']);
    echo "Promotions seeded.\n";

    // 12. Sample Wastage Log (Recorded Spoilage)
    $stmtWastage = $pdo->prepare("
        INSERT INTO wastage_logs 
        (branch_id, product_id, batch_id, location_type, quantity, cost_loss, reason_code, logged_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // 3 yogurts dropped and damaged in backroom
    $yogBatch1 = $batchIds[2][0]['id'];
    $stmtWastage->execute([1, 2, $yogBatch1, 'BACKROOM', 3.0, 4.50, 'DAMAGED_DROPPED', 3, 'Carton crushed during pallet unstacking']);
    // 2 lettuces spoiled
    $letBatch1 = $batchIds[7][0]['id'];
    $stmtWastage->execute([1, 7, $letBatch1, 'SHELF', 2.0, 1.40, 'SPOILED_ROTTEN', 3, 'Wilted on display shelf']);
    echo "Wastage logs seeded.\n";

    // 13. Sample Purchase Order & GRN
    $poNumber = 'PO-' . date('Ym') . '-0001';
    $stmtPO = $pdo->prepare("
        INSERT INTO purchase_orders 
        (po_number, branch_id, supplier_id, order_date, expected_delivery_date, status, is_auto_generated, total_amount, notes, created_by)
        VALUES (?, 1, 1, CURRENT_DATE - INTERVAL '2 days', CURRENT_DATE, 'COMPLETED', false, 115.00, 'Weekly dairy order', 2)
        RETURNING id
    ");
    $stmtPO->execute([$poNumber]);
    $poId = $stmtPO->fetchColumn();

    $stmtPOItem = $pdo->prepare("
        INSERT INTO purchase_order_items 
        (purchase_order_id, product_id, quantity_ordered, quantity_received, unit_cost, total_cost)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtPOItem->execute([$poId, 1, 100, 100, 1.15, 115.00]);

    $grnNumber = 'GRN-' . date('Ym') . '-0001';
    $stmtGRN = $pdo->prepare("
        INSERT INTO goods_received_notes 
        (grn_number, purchase_order_id, branch_id, supplier_id, invoice_number, received_by, notes)
        VALUES (?, ?, 1, 1, 'INV-GM-9941', 3, 'Delivered in refrigerated truck at 3 deg C')
        RETURNING id
    ");
    $stmtGRN->execute([$grnNumber, $poId]);
    $grnId = $stmtGRN->fetchColumn();

    $stmtGRNItem = $pdo->prepare("
        INSERT INTO goods_received_items 
        (grn_id, product_id, batch_id, quantity_received, allocated_shelf_qty, allocated_backroom_qty, unit_cost, expiry_date)
        VALUES (?, 1, ?, 100, 40, 60, 1.15, ?)
    ");
    $stmtGRNItem->execute([$grnId, $batchIds[1][1]['id'], $batchIds[1][1]['expiry']]);
    echo "Purchase Order & GRN seeded.\n";

    // 14. Sample Completed POS Sales
    for ($s = 1; $s <= 5; $s++) {
        $recNo = 'REC-' . date('Ymd') . '-' . str_pad($s, 4, '0', STR_PAD_LEFT);
        $stmtSale = $pdo->prepare("
            INSERT INTO sales 
            (receipt_number, branch_id, cashier_id, sale_time, subtotal, discount_amount, tax_amount, total_amount, payment_method, amount_tendered, change_due, status)
            VALUES (?, 1, 4, CURRENT_TIMESTAMP - (INTERVAL '1 hour' * ?), 14.50, 0.50, 1.20, 15.20, 'CASH', 20.00, 4.80, 'COMPLETED')
            RETURNING id
        ");
        $stmtSale->execute([$recNo, $s]);
        $saleId = $stmtSale->fetchColumn();

        // Sale items with batch assignment
        $stmtSaleItem = $pdo->prepare("
            INSERT INTO sale_items 
            (sale_id, product_id, batch_id, location_type, quantity, unit_price, unit_cost, discount_amount, line_total)
            VALUES (?, ?, ?, 'SHELF', ?, ?, ?, 0.00, ?)
        ");
        $stmtSaleItem->execute([$saleId, 1, $batchIds[1][0]['id'], 2, 1.65, 1.20, 3.30]);
        $stmtSaleItem->execute([$saleId, 8, $batchIds[8][0]['id'], 1, 1.20, 0.85, 1.20]);
    }
    echo "Sample POS sales seeded.\n";

    // 15. Sample Inter-branch transfer
    $stmtTrf = $pdo->prepare("
        INSERT INTO branch_transfers 
        (transfer_number, from_branch_id, to_branch_id, status, requested_by, notes)
        VALUES (?, 1, 2, 'PENDING', 2, 'Replenishment transfer for weekend peak')
        RETURNING id
    ");
    $stmtTrf->execute(['TRF-' . date('Ym') . '-0001']);
    $trfId = $stmtTrf->fetchColumn();

    $stmtTrfItem = $pdo->prepare("
        INSERT INTO branch_transfer_items (transfer_id, product_id, batch_id, quantity_requested)
        VALUES (?, ?, ?, ?)
    ");
    $stmtTrfItem->execute([$trfId, 13, $batchIds[13][0]['id'], 20]);
    echo "Inter-branch transfer seeded.\n";

    // 16. Sample Stocktake audit
    $stmtST = $pdo->prepare("
        INSERT INTO stocktakes 
        (count_number, branch_id, count_type, status, started_by, notes)
        VALUES (?, 1, 'CYCLE_COUNT_PERISHABLES', 'IN_PROGRESS', 3, 'Perishables dairy and produce morning cycle count')
        RETURNING id
    ");
    $stmtST->execute(['ST-' . date('Ym') . '-0001']);
    $stId = $stmtST->fetchColumn();

    $stmtSTItem = $pdo->prepare("
        INSERT INTO stocktake_items 
        (stocktake_id, product_id, batch_id, location_type, system_quantity, counted_quantity, variance_quantity, unit_cost, variance_cost, reason_code)
        VALUES (?, 1, ?, 'SHELF', 12.0, 11.0, -1.0, 1.20, -1.20, 'UNRECORDED_SPOILAGE')
    ");
    $stmtSTItem->execute([$stId, $batchIds[1][0]['id']]);
    echo "Stocktake session seeded.\n";

    echo "\n=== TC LIMITED GROCERY STORE SEED COMPLETED SUCCESSFULLY! ===\n";
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    try {
        $pdo = Database::getConnection();
        runGrocerySeeder($pdo);
    } catch (Exception $e) {
        echo "SEEDING ERROR: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}
