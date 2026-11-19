# TC Limited - Grocery Store Inventory Management System

An enterprise-grade, real-time Inventory Management System designed specifically for grocery retail operations (**TC Limited**), built with **HTML5, CSS3, PHP, JavaScript, PostgreSQL, and Firebase**.

---

## 🌟 Key Functional Architecture

### 1. Stock Tracking & Multi-Branch Architecture
- **Real-Time Multi-Location Matrix**: Tracks stock split between sales floor shelves (**SHELF**) and backroom/cold storage (**BACKROOM**) across multiple branches (*TC Central Supermarket*, *TC Express Westgate*, and *TC Fresh Mart Borrowdale*).
- **Batch & Lot Tracking**: Every inbound delivery receives a batch number (`LOT-YYYYMM-XXX`) linking supplier, manufacture date, expiry date, and unit cost for full traceability and rapid product recall capability.
- **Barcode & SKU Scanning**: Native support for hardware USB handheld barcode scanners (captures rapid input bursts ending with Return) and interactive software simulation.
- **Shelf Replenishment**: Direct transfer workflow moving pallets/crates from Backroom to Shelf with immediate audit logging.

### 2. Expiry & Perishables Management
- **FEFO (First-Expired, First-Out) Engine**: POS and order fulfillment algorithms strictly decrement the oldest active batch first, minimizing spoilage.
- **Near-Expiry Warning Matrix**: Categorizes perishable inventory into Expired, Critical (≤ 2 days), Warning (≤ 5 days), and Fresh.
- **Automated Clearance Markdown Engine**: Recommends dynamic price markdowns (e.g., 50% flash clearance or 30% discount) to clear perishables before expiration.
- **Wastage & Spoilage Logging**: Dedicated write-off portal with standardized grocery reason codes:
  - `EXPIRED` (Passed sell-by date)
  - `SPOILED_ROTTEN` (Natural decay / fresh produce deterioration)
  - `DAMAGED_DROPPED` (Broken bottles, crushed packaging)
  - `TEMP_FAILURE` (Cold-chain refrigeration failure)
  - `PEST_DAMAGE` (Rodent or insect contamination)
  - `THEFT_PILFERAGE` (Unaccounted shrinkage)

### 3. Purchasing & Replenishment
- **Min/Max Reorder Points**: Every product defines a safety buffer: Minimum Stock Level, Reorder Point, and Maximum Shelf Capacity.
- **1-Click Automatic Purchase Order Generator**: Automatically queries all SKUs at or below reorder points, aggregates them by preferred supplier contract price, and creates draft/sent POs with expected delivery dates based on supplier lead times.
- **Supplier Relationship Management**: Tracks lead times, payment terms (Net 7, Net 15, Net 30), contract price lists, and reliability ratings (1.0 - 5.0).
- **Goods Received Notes (GRN)**: Inbound delivery receiving matched against purchase orders, capturing invoice numbers, batch lot codes, and dual-location shelf/backroom allocation.

### 4. Sales Integration (Cashier POS)
- **High-Velocity POS Terminal**: Category filter pills, instant live product search, fast touch tiles, and hardware scanner listening.
- **FEFO Batch Decrement**: Checkout automatically depletes the earliest expiring batch on the sales shelf.
- **Promotions & Taxes**: Calculates VAT (15% standard on pantry/beverages/frozen, 0% on basic foodstuffs like bread, fresh milk, and raw produce).
- **Multi-Tender & Thermal Receipts**: Cash, Card, Mobile Money (EcoCash) with instant change calculation and 80mm thermal receipt printing.
- **Returns & Voids**: Sale voids reverse stock automatically; customer returns support routing undamaged goods back to the shelf or damaged items to wastage.

### 5. Stock Control & Auditing
- **Cycle Counts & Stocktakes**: Category-specific cycle counts, perishable morning counts, or full store stocktakes.
- **Variance Audit Reports**: Compares physical counted quantities against system book stock, calculating unit variance, financial variance cost, and variance reason codes.
- **1-Click Reconciliation**: Management approves variances, updating inventory and logging the reconciliation to the immutable audit trail.
- **Inter-Branch Transfers**: Requisition, dispatch (deducting from source branch), and receipt (adding to destination branch) workflow.

### 6. Reporting, Analytics & Intelligence
- **ABC Inventory Analysis (Pareto Classification)**:
  - **Class A** (Top 80% revenue): Tight daily control, strict FEFO auditing.
  - **Class B** (Next 15% revenue): Weekly review and standard replenishment.
  - **Class C** (Lowest 5% revenue): Bulk periodic reordering.
- **Dual Stock Valuation**: Real-time FIFO valuation (actual batch unit costs) vs. Weighted Average Cost (WAC) and potential retail gross margin.
- **Demand Forecasting & DOI**: Historical 30-day velocity, daily burn rate, Days of Inventory (DOI) remaining, and projected stockout dates.
- **Gross Margins by Product & Category**: Revenue, COGS, gross profit, and profit margin percentages.

### 7. Administration & Role-Based Access Control (RBAC)
- **Roles**:
  - `admin`: Complete authority across all modules, configuration, and user permissions.
  - `manager`: Purchasing, branch transfers, variance approvals, promotions, and reports.
  - `storekeeper`: Receiving (GRN), shelf replenishment, cycle counts, and wastage logging.
  - `cashier`: POS checkout, customer returns, price checks, and quick 4-digit PIN switching.
- **Immutable Audit Trail (`stock_movements`)**: Every stock increment or decrement records timestamp, product, batch, location, reference ID, before quantity, change quantity, after quantity, unit cost, and authenticated user ID.

### 8. Firebase Real-Time Synchronization
- Dual-mode architecture: Connects to Firebase Realtime Database for cross-terminal live events (`STOCK_UPDATE`, `EXPIRY_ALERT`, `NEW_SALE`, `AUTO_PO`, `WASTAGE_LOG`), with automatic fallback to a high-speed local event bus when working offline or in air-gapped environments.

---

## 💻 Tech Stack

| Layer | Technologies |
| :--- | :--- |
| **Frontend** | HTML5, CSS3 (Enterprise Design System), Vanilla JavaScript (ES6+), Chart.js |
| **Backend** | PHP 8.2 (RESTful MVC Architecture, PDO Driver, Prepared Statements) |
| **Database** | PostgreSQL 18 (Foreign Keys, Cascade Rules, Custom Indexes, Check Constraints) |
| **Realtime Bus** | Firebase Realtime Database & Local Polling Event Bus Fallback |
| **Server** | Apache / XAMPP / PHP Built-in Server |

---

## 🗄️ PostgreSQL Data Model Summary

```
branches (id, code, name, address, phone)
locations (id, branch_id, type [SHELF|BACKROOM], aisle_bay)
roles (id, name, description)
users (id, role_id, branch_id, username, password_hash, pin_code)
categories (id, name, code, default_tax_rate, shelf_life_days_default)
products (id, category_id, sku, barcode, name, uom, is_perishable, cost_price, selling_price, reorder_point, min_stock_level, max_stock_level)
suppliers (id, code, name, lead_time_days, payment_terms, rating)
supplier_products (id, supplier_id, product_id, supplier_sku, contract_price, lead_time_days)
product_batches (id, product_id, batch_number, manufacture_date, expiry_date, unit_cost, status)
inventory (id, branch_id, location_type, product_id, batch_id, quantity)
purchase_orders (id, po_number, branch_id, supplier_id, status, is_auto_generated, total_amount)
purchase_order_items (id, purchase_order_id, product_id, quantity_ordered, quantity_received, unit_cost, total_cost)
goods_received_notes (id, grn_number, purchase_order_id, branch_id, supplier_id, invoice_number)
goods_received_items (id, grn_id, product_id, batch_id, quantity_received, allocated_shelf_qty, allocated_backroom_qty)
promotions (id, product_id, batch_id, title, discount_percent, start_date, end_date, reason)
sales (id, receipt_number, branch_id, cashier_id, subtotal, discount_amount, tax_amount, total_amount, payment_method)
sale_items (id, sale_id, product_id, batch_id, location_type, quantity, unit_price, unit_cost, line_total)
sale_returns (id, sale_id, product_id, batch_id, quantity, refund_amount, reason_code, return_to_stock)
wastage_logs (id, branch_id, product_id, batch_id, location_type, quantity, cost_loss, reason_code)
stocktakes (id, count_number, branch_id, count_type, status, total_variance_cost)
stocktake_items (id, stocktake_id, product_id, batch_id, system_quantity, counted_quantity, variance_quantity, variance_cost)
branch_transfers (id, transfer_number, from_branch_id, to_branch_id, status)
branch_transfer_items (id, transfer_id, product_id, batch_id, quantity_requested, quantity_dispatched, quantity_received)
stock_movements (id, branch_id, product_id, batch_id, location_type, movement_type, reference_id, quantity_before, quantity_change, quantity_after, unit_cost, user_id)
```

---

## 🚀 Getting Started & Default Credentials

1. **Database Setup**:
   The PostgreSQL database `tc_grocery` has been initialized and seeded.
   To re-seed fresh test data at any time:
   ```bash
   php database/seed.php
   ```

2. **Automated Integration Test Suite**:
   Run the 10 automated test suites:
   ```bash
   php test_suite.php
   ```
   Or visit `http://localhost/Retail_Billing/test_suite.php` in your browser.

3. **Default Demo Users (Password: `password123`)**:
   - **Admin**: `admin` (PIN: `1234`)
   - **Store Manager**: `manager` (PIN: `2345`)
   - **Storekeeper**: `storekeeper` (PIN: `3456`)
   - **Cashier**: `cashier` (PIN: `4567`)
