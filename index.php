<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/firebase.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
$currentUser = Auth::user();
$firebaseClientConfig = json_encode(FirebaseService::getClientConfig());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TC Limited - Grocery Store Inventory Management System</title>
    <!-- Modern Enterprise Styles -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
    <!-- Chart.js CDN with safe fallback -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- Firebase SDK (Compat) -->
    <script src="https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.23.0/firebase-database-compat.js"></script>
    <script>
        window.TC_FIREBASE_CONFIG = <?php echo $firebaseClientConfig; ?>;
    </script>
</head>
<body>

    <!-- Sidebar Navigation -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">🛒</div>
            <div class="brand-text">
                <h1>TC LIMITED</h1>
                <span>Grocery Inventory</span>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-header">Core Operations</li>
            <li class="nav-item">
                <a class="nav-link active" data-view="dashboard">
                    <span>📊 Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-view="stock">
                    <span>📦 Stock Matrix</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-view="pos">
                    <span>💳 Cashier POS</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-view="expiry">
                    <span>🍎 Perishables & FEFO</span>
                    <span class="badge" id="badge-expiry-count" style="display:none;">0</span>
                </a>
            </li>

            <li class="nav-header" data-role-min="storekeeper">Inventory Control</li>
            <li class="nav-item" data-role-min="manager">
                <a class="nav-link" data-view="purchasing">
                    <span>📑 Purchasing & Auto PO</span>
                </a>
            </li>
            <li class="nav-item" data-role-min="storekeeper">
                <a class="nav-link" data-view="grn">
                    <span>📥 Goods Received (GRN)</span>
                </a>
            </li>
            <li class="nav-item" data-role-min="storekeeper">
                <a class="nav-link" data-view="stocktake">
                    <span>🔍 Stocktakes & Variances</span>
                </a>
            </li>
            <li class="nav-item" data-role-min="manager">
                <a class="nav-link" data-view="transfers">
                    <span>🔄 Branch Transfers</span>
                </a>
            </li>

            <li class="nav-header" data-role-min="manager">Intelligence & Admin</li>
            <li class="nav-item" data-role-min="manager">
                <a class="nav-link" data-view="reports">
                    <span>📈 Analytics & ABC</span>
                </a>
            </li>
            <li class="nav-item" data-role-min="manager">
                <a class="nav-link" data-view="audit">
                    <span>📜 Audit Trail</span>
                </a>
            </li>
            <li class="nav-item" data-role-min="admin">
                <a class="nav-link" data-view="users">
                    <span>👥 Staff & Roles</span>
                </a>
            </li>
        </ul>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar" id="user-avatar">U</div>
                <div class="user-details">
                    <div class="user-name" id="user-full-name">User</div>
                    <div class="user-role" id="user-role-badge">Role</div>
                </div>
            </div>
            <button class="btn-icon" title="Logout" onclick="TC_App.logout()">🚪</button>
        </div>
    </aside>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-left">
                <h2 class="page-title" id="page-title-text">Executive Grocery Dashboard</h2>
                <div class="branch-selector">
                    <span>📍 Branch:</span>
                    <select id="branch-select"></select>
                </div>
            </div>

            <div class="topbar-right">
                <div class="live-indicator" id="live-status-badge">
                    <span class="live-dot"></span> Firebase Real-Time
                </div>
                <button class="btn btn-secondary btn-sm" onclick="BarcodeScanner.openSimModal()">
                    📟 Barcode Scanner
                </button>
                <a href="test_suite.php" target="_blank" class="btn btn-secondary btn-sm">
                    🧪 Automated Tests
                </a>
            </div>
        </header>

        <!-- Dynamic Content Area -->
        <main class="content-area">

            <!-- 1. VIEW: DASHBOARD -->
            <section id="view-dashboard" class="view-panel">
                <!-- KPI Grid -->
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-info">
                            <h3>Total Stock Value (FIFO)</h3>
                            <div class="kpi-value" id="dash-val-cost">$0.00</div>
                            <div class="kpi-sub">Retail Valuation: <span id="dash-val-retail">$0.00</span></div>
                        </div>
                        <div class="kpi-icon kpi-green">💰</div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-info">
                            <h3>Low Stock Alerts</h3>
                            <div class="kpi-value" id="dash-low-stock">0</div>
                            <div class="kpi-sub">At or below reorder point</div>
                        </div>
                        <div class="kpi-icon kpi-amber">⚠️</div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-info">
                            <h3>Expiring Batches (≤ 7d)</h3>
                            <div class="kpi-value" id="dash-expiring">0</div>
                            <div class="kpi-sub">Suggested markdowns available</div>
                        </div>
                        <div class="kpi-icon kpi-red">⏰</div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-info">
                            <h3>Wastage & Spoilage (MTD)</h3>
                            <div class="kpi-value" id="dash-wastage">$0.00</div>
                            <div class="kpi-sub">Recorded damage & expired</div>
                        </div>
                        <div class="kpi-icon kpi-purple">🗑️</div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-info">
                            <h3>Today's POS Sales</h3>
                            <div class="kpi-value" id="dash-today-sales">$0.00</div>
                            <div class="kpi-sub"><span id="dash-today-trans">0</span> completed receipts</div>
                        </div>
                        <div class="kpi-icon kpi-blue">💳</div>
                    </div>
                </div>

                <!-- Dashboard Charts & Action Panels -->
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">📦 Inventory Valuation by Category</span>
                        </div>
                        <div class="card-body" style="height:280px;position:relative;">
                            <canvas id="chart-categories"></canvas>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">⚡ Quick Core Actions</span>
                        </div>
                        <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
                            <button class="btn btn-primary" onclick="TC_App.navigateTo('pos')">
                                🛒 Open Cashier POS Terminal
                            </button>
                            <button class="btn btn-warning" onclick="TC_App.triggerAutoPO()">
                                ⚡ Run 1-Click Auto PO Engine
                            </button>
                            <button class="btn btn-secondary" onclick="TC_App.openNewGRNModal()">
                                📥 Record Inbound GRN Delivery
                            </button>
                            <button class="btn btn-secondary" onclick="TC_App.navigateTo('expiry')">
                                🍎 Review Expiring & Markdowns
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 2. VIEW: STOCK MATRIX -->
            <section id="view-stock" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">🏢 Multi-Branch Real-Time Inventory Matrix</span>
                        <div class="toolbar-right">
                            <button class="btn btn-secondary btn-sm" onclick="TC_App.loadStockView()">🔄 Refresh Stock</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-stock-matrix">
                                <thead>
                                    <tr>
                                        <th>Product / SKU</th>
                                        <th>Category</th>
                                        <th>TC Central (Shelf / Backroom)</th>
                                        <th>TC Westgate (Shelf / Backroom)</th>
                                        <th>Company Total</th>
                                        <th>Stock Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. VIEW: CASHIER POS TERMINAL -->
            <section id="view-pos" class="view-panel" style="display:none;">
                <div class="pos-container">
                    <!-- Left: Product Catalog & Quick Search -->
                    <div class="pos-catalog-panel">
                        <div class="pos-search-bar">
                            <div class="search-input-wrap" style="flex:1;">
                                <span class="search-icon">🔍</span>
                                <input type="text" class="scanner-input" placeholder="Scan barcode or type name..." oninput="TC_POS.searchProducts(this.value)">
                            </div>
                            <button class="btn btn-secondary btn-sm" onclick="BarcodeScanner.openSimModal()">
                                📟 Test Scan
                            </button>
                        </div>
                        <div class="pos-categories-tabs" id="pos-categories"></div>
                        <div class="pos-products-grid" id="pos-products-grid"></div>
                    </div>

                    <!-- Right: Cart & Tender -->
                    <div class="pos-cart-panel">
                        <div class="pos-cart-header">
                            <h3>Active Cart Items</h3>
                            <button class="btn btn-secondary btn-sm" onclick="TC_POS.clearCart()">Clear</button>
                        </div>
                        <div class="pos-cart-items" id="pos-cart-items"></div>
                        <div class="cart-summary">
                            <div class="summary-line"><span>Subtotal:</span><span id="pos-subtotal">$0.00</span></div>
                            <div class="summary-line"><span>Promotional Discount:</span><span id="pos-discount" style="color:var(--danger);">-$0.00</span></div>
                            <div class="summary-line"><span>Sales Tax (VAT):</span><span id="pos-tax">$0.00</span></div>
                            <div class="summary-line total"><span>Total Payable:</span><span id="pos-grand-total">$0.00</span></div>
                            <button class="pos-checkout-btn" onclick="TC_POS.openCheckoutModal()">
                                💳 Proceed to Tender / Pay
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 4. VIEW: PERISHABLES & EXPIRY -->
            <section id="view-expiry" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">🍎 Expiry Tracking, FEFO Picking & Markdown Engine</span>
                        <div style="font-size:12px;color:var(--text-muted);">
                            Highlights batches expiring within 14 days with automated price markdown recommendations.
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-expiry">
                                <thead>
                                    <tr>
                                        <th>Product / SKU</th>
                                        <th>Batch #</th>
                                        <th>Expiry Date</th>
                                        <th>Stock (Shelf / Backroom)</th>
                                        <th>Price</th>
                                        <th>Suggested Clearance Markdown</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 5. VIEW: PURCHASING & AUTO REPLENISHMENT -->
            <section id="view-purchasing" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">⚠️ Reorder Point Stock Alerts</span>
                        <button class="btn btn-warning" onclick="TC_App.triggerAutoPO()">
                            ⚡ Generate Automatic Purchase Orders
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-reorder-alerts">
                                <thead>
                                    <tr>
                                        <th>Product / SKU</th>
                                        <th>Category</th>
                                        <th>Current Stock</th>
                                        <th>Suggested Order Qty</th>
                                        <th>Preferred Supplier</th>
                                        <th>Contract Unit Cost</th>
                                        <th>Est. Total Cost</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">📑 Purchase Orders Log</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-pos">
                                <thead>
                                    <tr>
                                        <th>PO Number</th>
                                        <th>Supplier</th>
                                        <th>Order Date</th>
                                        <th>Expected Delivery</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Generation Type</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 6. VIEW: GOODS RECEIVED NOTES (GRN) -->
            <section id="view-grn" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">📥 Goods Received Notes (GRN) Receiving</span>
                        <button class="btn btn-primary" onclick="TC_App.openNewGRNModal()">
                            + New Inbound Delivery (GRN)
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-grn">
                                <thead>
                                    <tr>
                                        <th>GRN #</th>
                                        <th>Matched PO #</th>
                                        <th>Supplier</th>
                                        <th>Invoice #</th>
                                        <th>Received Date</th>
                                        <th>Units Received</th>
                                        <th>Received By</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 7. VIEW: STOCKTAKES & VARIANCES -->
            <section id="view-stocktake" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">🔍 Stocktake Audits & Variance Reconciliation</span>
                        <div class="toolbar-right">
                            <button class="btn btn-primary" onclick="TC_App.startNewStocktakePrompt()">
                                + Start New Stocktake Session
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-stocktakes">
                                <thead>
                                    <tr>
                                        <th>Count #</th>
                                        <th>Audit Type</th>
                                        <th>Status</th>
                                        <th>Started At</th>
                                        <th>Lines Counted</th>
                                        <th>Net Variance Cost</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 8. VIEW: INTER-BRANCH TRANSFERS -->
            <section id="view-transfers" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">🔄 Inter-Branch Stock Transfers</span>
                        <button class="btn btn-primary" onclick="TC_App.openTransferModal()">
                            + Request New Transfer
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-transfers">
                                <thead>
                                    <tr>
                                        <th>Transfer #</th>
                                        <th>From Branch</th>
                                        <th>To Branch</th>
                                        <th>Status</th>
                                        <th>Requested At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 9. VIEW: REPORTS & ANALYTICS -->
            <section id="view-reports" class="view-panel" style="display:none;">
                <div style="display:flex;gap:10px;margin-bottom:18px;">
                    <button class="btn btn-secondary report-tab-btn active" data-tab="abc" onclick="TC_App.switchReportTab('abc')">
                        📊 ABC Pareto Analysis
                    </button>
                    <button class="btn btn-secondary report-tab-btn" data-tab="valuation" onclick="TC_App.switchReportTab('valuation')">
                        💰 Stock Valuation (FIFO vs WAC)
                    </button>
                    <button class="btn btn-secondary report-tab-btn" data-tab="forecast" onclick="TC_App.switchReportTab('forecast')">
                        📈 Demand Forecasting & DOI
                    </button>
                </div>
                <div class="card">
                    <div class="card-body" id="report-content-container"></div>
                </div>
            </section>

            <!-- 10. VIEW: AUDIT TRAIL -->
            <section id="view-audit" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">📜 Immutable Stock Movement Audit Trail</span>
                        <button class="btn btn-secondary btn-sm" onclick="TC_App.loadAuditView()">🔄 Refresh Log</button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-audit">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Product / SKU</th>
                                        <th>Location</th>
                                        <th>Movement Type</th>
                                        <th>Reference ID</th>
                                        <th>Before</th>
                                        <th>Qty Change</th>
                                        <th>After</th>
                                        <th>User</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 11. VIEW: USERS & RBAC -->
            <section id="view-users" class="view-panel" style="display:none;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">👥 Staff Directory & Role-Based Access Control</span>
                        <button class="btn btn-primary" onclick="TC_App.openAddUserModal()">
                            + Add New Staff User
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table" id="table-users">
                                <thead>
                                    <tr>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Assigned Role</th>
                                        <th>Home Branch</th>
                                        <th>POS PIN</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Toast Notifications Container -->
    <div class="toast-container" id="toast-container"></div>

    <!-- Printable Thermal Receipt (Hidden on screen, visible during window.print()) -->
    <div id="printable-receipt" class="print-only"></div>

    <!-- ===================================================================
         MODALS
         =================================================================== -->

    <!-- Modal 1: Barcode Scanner Simulation -->
    <div class="modal-overlay" id="modal-scanner-sim">
        <div class="modal-card">
            <div class="modal-header">
                <h3>📟 Barcode Scanner Simulator</h3>
                <button class="modal-close" onclick="BarcodeScanner.closeSimModal()">✕</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                    Scan using your connected USB handheld barcode scanner, or choose a barcode below to simulate a real-time scan event:
                </p>
                <div class="form-group">
                    <label>Scan Barcode / SKU</label>
                    <input type="text" id="sim-barcode-input" class="form-control" placeholder="e.g. 8901001001" onkeydown="if(event.key==='Enter') BarcodeScanner.submitSimScan()">
                </div>
                <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:var(--dark);">Quick Test Barcodes:</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('sim-barcode-input').value='8901001001'; BarcodeScanner.submitSimScan()">🥛 Milk 1L (8901001001)</button>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('sim-barcode-input').value='8902001002'; BarcodeScanner.submitSimScan()">🍅 Tomatoes 1kg (8902001002)</button>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('sim-barcode-input').value='8903001001'; BarcodeScanner.submitSimScan()">🍞 White Bread (8903001001)</button>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('sim-barcode-input').value='8905001001'; BarcodeScanner.submitSimScan()">🍚 Basmati Rice (8905001001)</button>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('sim-barcode-input').value='8906001001'; BarcodeScanner.submitSimScan()">💧 Spring Water (8906001001)</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="BarcodeScanner.closeSimModal()">Cancel</button>
                <button class="btn btn-primary" onclick="BarcodeScanner.submitSimScan()">Trigger Scan</button>
            </div>
        </div>
    </div>

    <!-- Modal 2: POS Checkout / Tender -->
    <div class="modal-overlay" id="modal-checkout">
        <div class="modal-card">
            <div class="modal-header">
                <h3>💳 Complete POS Checkout</h3>
                <button class="modal-close" onclick="TC_POS.closeCheckoutModal()">✕</button>
            </div>
            <div class="modal-body">
                <div style="text-align:center;padding:16px 0;background:#f8fafc;border-radius:var(--radius-sm);margin-bottom:16px;">
                    <div style="font-size:13px;color:var(--text-muted);font-weight:600;">AMOUNT DUE</div>
                    <div style="font-size:32px;font-weight:800;color:var(--primary-dark);" id="checkout-payable">$0.00</div>
                </div>

                <div class="form-group">
                    <label>Payment Method</label>
                    <select id="checkout-payment-method" class="form-control">
                        <option value="CASH">Cash</option>
                        <option value="CARD">Debit / Credit Card</option>
                        <option value="MOBILE_MONEY">EcoCash / Mobile Money</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Amount Tendered ($)</label>
                    <input type="number" step="0.01" id="checkout-tendered" class="form-control" oninput="TC_POS.calculateChange()">
                </div>

                <div style="display:flex;justify-content:space-between;padding:12px;background:#f1f5f9;border-radius:6px;font-size:14px;font-weight:700;">
                    <span>Change Due:</span>
                    <span id="checkout-change" style="color:var(--dark);">$0.00</span>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="TC_POS.closeCheckoutModal()">Cancel</button>
                <button class="btn btn-primary" onclick="TC_POS.processPayment()">Complete Sale & Print Receipt</button>
            </div>
        </div>
    </div>

    <!-- Modal 3: Batches Detail -->
    <div class="modal-overlay" id="modal-batches">
        <div class="modal-card wide">
            <div class="modal-header">
                <h3 id="modal-batches-title">Batch & Lot Details</h3>
                <button class="modal-close" onclick="TC_App.closeBatchesModal()">✕</button>
            </div>
            <div class="modal-body">
                <table class="data-table" id="table-batches">
                    <thead>
                        <tr>
                            <th>Batch #</th>
                            <th>Expiry Date</th>
                            <th>Unit Cost</th>
                            <th>Shelf Qty</th>
                            <th>Backroom Qty</th>
                            <th>Total Qty</th>
                            <th>Supplier</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="TC_App.closeBatchesModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal 4: Shelf Replenishment -->
    <div class="modal-overlay" id="modal-replenish">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Shelf Replenishment</h3>
                <button class="modal-close" onclick="TC_App.closeReplenishModal()">✕</button>
            </div>
            <div class="modal-body">
                <div style="font-size:14px;font-weight:700;margin-bottom:12px;" id="replenish-product-name">Product Name</div>
                <input type="hidden" id="replenish-product-id">

                <div class="form-group">
                    <label>Select Batch to Move (FEFO Priority)</label>
                    <select id="replenish-batch-select" class="form-control"></select>
                </div>

                <div class="form-group">
                    <label>Quantity to Transfer to Sales Shelf</label>
                    <input type="number" id="replenish-quantity" class="form-control" value="10" min="1">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="TC_App.closeReplenishModal()">Cancel</button>
                <button class="btn btn-primary" onclick="TC_App.submitReplenishment()">Move to Shelf</button>
            </div>
        </div>
    </div>

    <!-- Modal 5: Spoilage / Wastage Logging -->
    <div class="modal-overlay" id="modal-wastage">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Log Wastage & Spoilage</h3>
                <button class="modal-close" onclick="TC_App.closeWastageModal()">✕</button>
            </div>
            <div class="modal-body">
                <div style="font-size:14px;font-weight:700;margin-bottom:12px;" id="waste-item-name">Item</div>
                <input type="hidden" id="waste-product-id">
                <input type="hidden" id="waste-batch-id">

                <div class="form-group">
                    <label>Quarantine Location</label>
                    <select id="waste-location" class="form-control">
                        <option value="SHELF">Sales Floor Shelf</option>
                        <option value="BACKROOM">Backroom Cold Room / Rack</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantity Spoilage / Wasted</label>
                    <input type="number" id="waste-quantity" class="form-control" value="1" min="1">
                </div>

                <div class="form-group">
                    <label>Reason Code</label>
                    <select id="waste-reason" class="form-control">
                        <option value="EXPIRED">Passed Expiry Date</option>
                        <option value="SPOILED_ROTTEN">Spoiled / Rotten (Perishable Decay)</option>
                        <option value="DAMAGED_DROPPED">Damaged / Broken / Dropped</option>
                        <option value="TEMP_FAILURE">Cold-Chain Temperature Abuse</option>
                        <option value="PEST_DAMAGE">Pest / Rodent Contamination</option>
                        <option value="THEFT_PILFERAGE">Theft / Unaccounted Shortage</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Notes / Inspector Remarks</label>
                    <input type="text" id="waste-notes" class="form-control" placeholder="e.g. Broken packaging discovered during shelf reset">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="TC_App.closeWastageModal()">Cancel</button>
                <button class="btn btn-danger" onclick="TC_App.submitWastage()">Confirm Wastage Write-Off</button>
            </div>
        </div>
    </div>

    <!-- Modal 6: New Inbound GRN Delivery -->
    <div class="modal-overlay" id="modal-grn">
        <div class="modal-card wide">
            <div class="modal-header">
                <h3>📥 Inbound Delivery: Goods Received Note (GRN)</h3>
                <button class="modal-close" onclick="TC_App.closeGRNModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Supplier</label>
                        <select id="grn-supplier-id" class="form-control">
                            <option value="1">Golden Meadow Dairy Co</option>
                            <option value="2">FreshHarvest Farms Ltd</option>
                            <option value="3">Sunrise Bakery Mills</option>
                            <option value="4">PrimeCuts Butchery</option>
                            <option value="5">National Foods & Pantry</option>
                            <option value="6">PureFlow Springs</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Delivery Invoice #</label>
                        <input type="text" id="grn-invoice-no" class="form-control" placeholder="e.g. INV-2026-992">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" id="grn-notes" class="form-control" placeholder="Refrigerated truck ok">
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin:16px 0 8px 0;">
                    <div style="font-weight:700;font-size:13px;">Receiving Items & Shelf/Backroom Allocation</div>
                    <button class="btn btn-secondary btn-sm" onclick="TC_App.addGRNRow()">+ Add Item Row</button>
                </div>

                <div class="table-responsive">
                    <table class="data-table" id="table-grn-items">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Batch / Lot #</th>
                                <th>Expiry Date</th>
                                <th>Qty Recv</th>
                                <th>Shelf Qty</th>
                                <th>Unit Cost ($)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="TC_App.closeGRNModal()">Cancel</button>
                <button class="btn btn-primary" onclick="TC_App.submitGRN()">Post GRN to Inventory</button>
            </div>
        </div>
    </div>

    <!-- Modal 7: Variance Report Modal -->
    <div class="modal-overlay" id="modal-variance-report">
        <div class="modal-card wide">
            <div class="modal-header">
                <h3 id="variance-report-title">Variance Audit Report</h3>
                <button class="modal-close" onclick="TC_App.closeVarianceModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="data-table" id="table-variance-items">
                        <thead>
                            <tr>
                                <th>Product / SKU</th>
                                <th>Location</th>
                                <th>System Qty</th>
                                <th>Counted Qty</th>
                                <th>Variance</th>
                                <th>Variance Cost</th>
                                <th>Reason Code</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="TC_App.closeVarianceModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal 8: Add New Staff User -->
    <div class="modal-overlay" id="modal-add-user">
        <div class="modal-card">
            <div class="modal-header">
                <h3>👥 Add New Staff Member</h3>
                <button class="modal-close" onclick="TC_App.closeAddUserModal()">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-add-user" onsubmit="event.preventDefault(); TC_App.submitAddUser();">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" id="new-user-fullname" class="form-control" placeholder="e.g. Simba Moyo" required>
                        </div>
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" id="new-user-username" class="form-control" placeholder="e.g. simba" required autocomplete="off">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" id="new-user-email" class="form-control" placeholder="e.g. simba@tclimited.co.zw">
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Role Assignment *</label>
                            <select id="new-user-role" class="form-control" required>
                                <option value="4">Cashier (POS Checkout & Returns)</option>
                                <option value="3">Storekeeper (GRN, Stocktakes, Wastage)</option>
                                <option value="2">Store Manager (Purchasing, Audits, Analytics)</option>
                                <option value="1">Administrator (Full System Access)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Assigned Branch</label>
                            <select id="new-user-branch" class="form-control">
                                <option value="">All Branches (Admin / Floating)</option>
                                <option value="1" selected>TC Supermarket - Central Branch</option>
                                <option value="2">TC Express - Westgate Mall</option>
                                <option value="3">TC Fresh Mart - Borrowdale</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" id="new-user-password" class="form-control" value="password123" required>
                        </div>
                        <div class="form-group">
                            <label>POS Quick PIN (4 digits)</label>
                            <input type="text" id="new-user-pin" class="form-control" value="1234" maxlength="6" pattern="[0-9]+" placeholder="1234">
                        </div>
                    </div>

                    <div id="add-user-error" style="display:none;padding:10px;background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;border-radius:6px;font-size:12px;margin-bottom:12px;"></div>

                    <div class="modal-footer" style="padding:0;padding-top:16px;background:none;border-top:1px solid var(--border);">
                        <button type="button" class="btn btn-secondary" onclick="TC_App.closeAddUserModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create User Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/js/firebase-sync.js"></script>
    <script src="assets/js/scanner.js"></script>
    <script src="assets/js/charts.js"></script>
    <script src="assets/js/pos.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>
