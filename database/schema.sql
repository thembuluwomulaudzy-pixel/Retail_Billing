-- =====================================================================
-- TC LIMITED GROCERY STORE INVENTORY MANAGEMENT SYSTEM
-- PostgreSQL Database Schema
-- =====================================================================

DROP TABLE IF EXISTS stock_movements CASCADE;
DROP TABLE IF EXISTS branch_transfer_items CASCADE;
DROP TABLE IF EXISTS branch_transfers CASCADE;
DROP TABLE IF EXISTS stocktake_items CASCADE;
DROP TABLE IF EXISTS stocktakes CASCADE;
DROP TABLE IF EXISTS wastage_logs CASCADE;
DROP TABLE IF EXISTS sale_returns CASCADE;
DROP TABLE IF EXISTS sale_items CASCADE;
DROP TABLE IF EXISTS sales CASCADE;
DROP TABLE IF EXISTS promotions CASCADE;
DROP TABLE IF EXISTS goods_received_items CASCADE;
DROP TABLE IF EXISTS goods_received_notes CASCADE;
DROP TABLE IF EXISTS purchase_order_items CASCADE;
DROP TABLE IF EXISTS purchase_orders CASCADE;
DROP TABLE IF EXISTS supplier_products CASCADE;
DROP TABLE IF EXISTS suppliers CASCADE;
DROP TABLE IF EXISTS inventory CASCADE;
DROP TABLE IF EXISTS product_batches CASCADE;
DROP TABLE IF EXISTS products CASCADE;
DROP TABLE IF EXISTS categories CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS roles CASCADE;
DROP TABLE IF EXISTS locations CASCADE;
DROP TABLE IF EXISTS branches CASCADE;

-- 1. Branches
CREATE TABLE branches (
    id SERIAL PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    phone VARCHAR(30),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Locations (Shelf vs Backroom per branch)
CREATE TABLE locations (
    id SERIAL PRIMARY KEY,
    branch_id INT NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    type VARCHAR(20) NOT NULL CHECK (type IN ('SHELF', 'BACKROOM')),
    aisle_bay VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Roles
CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(30) UNIQUE NOT NULL,
    description TEXT
);

-- 4. Users
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    role_id INT NOT NULL REFERENCES roles(id),
    branch_id INT REFERENCES branches(id),
    username VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    password_hash VARCHAR(255) NOT NULL,
    pin_code VARCHAR(10) DEFAULT '1234',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. Categories
CREATE TABLE categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL,
    default_tax_rate NUMERIC(5,2) DEFAULT 0.00,
    shelf_life_days_default INT DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Products
CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    category_id INT NOT NULL REFERENCES categories(id),
    sku VARCHAR(50) UNIQUE NOT NULL,
    barcode VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    uom VARCHAR(20) NOT NULL, -- 'kg', 'litres', 'pack', 'unit', 'can', 'bunch'
    is_perishable BOOLEAN DEFAULT TRUE,
    cost_price NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    selling_price NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    reorder_point INT NOT NULL DEFAULT 10,
    min_stock_level INT NOT NULL DEFAULT 5,
    max_stock_level INT NOT NULL DEFAULT 100,
    shelf_life_days INT DEFAULT 7,
    alert_before_expiry_days INT DEFAULT 3,
    image_url VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Suppliers
CREATE TABLE suppliers (
    id SERIAL PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(50),
    lead_time_days INT DEFAULT 3,
    payment_terms VARCHAR(50) DEFAULT 'Net 30',
    rating NUMERIC(3,2) DEFAULT 4.5,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Supplier Product Catalog & Contract Prices
CREATE TABLE supplier_products (
    id SERIAL PRIMARY KEY,
    supplier_id INT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    supplier_sku VARCHAR(50),
    contract_price NUMERIC(12,2) NOT NULL,
    lead_time_days INT,
    is_preferred BOOLEAN DEFAULT TRUE,
    UNIQUE (supplier_id, product_id)
);

-- 9. Product Batches (Lot Tracking, Expiry, FEFO)
CREATE TABLE product_batches (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    batch_number VARCHAR(50) NOT NULL,
    manufacture_date DATE,
    expiry_date DATE NOT NULL,
    supplier_id INT REFERENCES suppliers(id),
    unit_cost NUMERIC(12,2) NOT NULL,
    received_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    status VARCHAR(20) DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'EXPIRING_SOON', 'EXPIRED', 'RECALLED', 'DEPLETED')),
    UNIQUE (product_id, batch_number)
);

-- 10. Inventory (Stock levels per Branch, Location SHELF/BACKROOM, Batch)
CREATE TABLE inventory (
    id SERIAL PRIMARY KEY,
    branch_id INT NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    location_type VARCHAR(20) NOT NULL CHECK (location_type IN ('SHELF', 'BACKROOM')),
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    batch_id INT NOT NULL REFERENCES product_batches(id) ON DELETE CASCADE,
    quantity NUMERIC(12,3) NOT NULL DEFAULT 0.000,
    last_counted_at TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (branch_id, location_type, product_id, batch_id)
);

-- 11. Purchase Orders
CREATE TABLE purchase_orders (
    id SERIAL PRIMARY KEY,
    po_number VARCHAR(50) UNIQUE NOT NULL,
    branch_id INT NOT NULL REFERENCES branches(id),
    supplier_id INT NOT NULL REFERENCES suppliers(id),
    order_date DATE NOT NULL DEFAULT CURRENT_DATE,
    expected_delivery_date DATE,
    status VARCHAR(30) DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SENT', 'PARTIALLY_RECEIVED', 'COMPLETED', 'CANCELLED')),
    is_auto_generated BOOLEAN DEFAULT FALSE,
    total_amount NUMERIC(12,2) DEFAULT 0.00,
    notes TEXT,
    created_by INT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 12. Purchase Order Items
CREATE TABLE purchase_order_items (
    id SERIAL PRIMARY KEY,
    purchase_order_id INT NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    product_id INT NOT NULL REFERENCES products(id),
    quantity_ordered NUMERIC(12,3) NOT NULL,
    quantity_received NUMERIC(12,3) DEFAULT 0.000,
    unit_cost NUMERIC(12,2) NOT NULL,
    total_cost NUMERIC(12,2) NOT NULL
);

-- 13. Goods Received Notes (GRN)
CREATE TABLE goods_received_notes (
    id SERIAL PRIMARY KEY,
    grn_number VARCHAR(50) UNIQUE NOT NULL,
    purchase_order_id INT REFERENCES purchase_orders(id),
    branch_id INT NOT NULL REFERENCES branches(id),
    supplier_id INT NOT NULL REFERENCES suppliers(id),
    invoice_number VARCHAR(50),
    received_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    received_by INT REFERENCES users(id) ON DELETE SET NULL,
    notes TEXT
);

-- 14. Goods Received Items (with Shelf/Backroom allocation & batch generation)
CREATE TABLE goods_received_items (
    id SERIAL PRIMARY KEY,
    grn_id INT NOT NULL REFERENCES goods_received_notes(id) ON DELETE CASCADE,
    product_id INT NOT NULL REFERENCES products(id),
    batch_id INT REFERENCES product_batches(id),
    quantity_received NUMERIC(12,3) NOT NULL,
    allocated_shelf_qty NUMERIC(12,3) DEFAULT 0.000,
    allocated_backroom_qty NUMERIC(12,3) DEFAULT 0.000,
    unit_cost NUMERIC(12,2) NOT NULL,
    expiry_date DATE NOT NULL
);

-- 15. Promotions & Markdowns (Near-expiry discounts, seasonal)
CREATE TABLE promotions (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    batch_id INT REFERENCES product_batches(id),
    title VARCHAR(100) NOT NULL,
    discount_percent NUMERIC(5,2) DEFAULT 0.00,
    discount_amount NUMERIC(12,2) DEFAULT 0.00,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    reason VARCHAR(50) DEFAULT 'PROMOTIONAL' CHECK (reason IN ('NEAR_EXPIRY', 'SEASONAL', 'CLEARANCE', 'PROMOTIONAL'))
);

-- 16. Sales (POS)
CREATE TABLE sales (
    id SERIAL PRIMARY KEY,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    branch_id INT NOT NULL REFERENCES branches(id),
    cashier_id INT REFERENCES users(id) ON DELETE SET NULL,
    sale_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    subtotal NUMERIC(12,2) NOT NULL,
    discount_amount NUMERIC(12,2) DEFAULT 0.00,
    tax_amount NUMERIC(12,2) DEFAULT 0.00,
    total_amount NUMERIC(12,2) NOT NULL,
    payment_method VARCHAR(30) DEFAULT 'CASH' CHECK (payment_method IN ('CASH', 'CARD', 'MOBILE_MONEY', 'SPLIT')),
    amount_tendered NUMERIC(12,2),
    change_due NUMERIC(12,2),
    status VARCHAR(20) DEFAULT 'COMPLETED' CHECK (status IN ('COMPLETED', 'VOIDED', 'REFUNDED', 'RETURNED'))
);

-- 17. Sale Items (Decrementing stock via FEFO)
CREATE TABLE sale_items (
    id SERIAL PRIMARY KEY,
    sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    product_id INT NOT NULL REFERENCES products(id),
    batch_id INT REFERENCES product_batches(id),
    location_type VARCHAR(20) DEFAULT 'SHELF',
    quantity NUMERIC(12,3) NOT NULL,
    unit_price NUMERIC(12,2) NOT NULL,
    unit_cost NUMERIC(12,2) NOT NULL,
    discount_amount NUMERIC(12,2) DEFAULT 0.00,
    line_total NUMERIC(12,2) NOT NULL
);

-- 18. Sale Returns, Voids & Adjustments
CREATE TABLE sale_returns (
    id SERIAL PRIMARY KEY,
    sale_id INT NOT NULL REFERENCES sales(id),
    sale_item_id INT REFERENCES sale_items(id),
    product_id INT NOT NULL REFERENCES products(id),
    batch_id INT REFERENCES product_batches(id),
    branch_id INT NOT NULL REFERENCES branches(id),
    processed_by INT REFERENCES users(id) ON DELETE SET NULL,
    quantity NUMERIC(12,3) NOT NULL,
    refund_amount NUMERIC(12,2) NOT NULL,
    reason_code VARCHAR(50) NOT NULL, -- 'EXPIRED', 'DAMAGED_PACKAGING', 'CUSTOMER_MISTAKE', 'DEFECTIVE_PRODUCT', 'OTHER'
    return_to_stock BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 19. Wastage & Spoilage Logging
CREATE TABLE wastage_logs (
    id SERIAL PRIMARY KEY,
    branch_id INT NOT NULL REFERENCES branches(id),
    product_id INT NOT NULL REFERENCES products(id),
    batch_id INT NOT NULL REFERENCES product_batches(id),
    location_type VARCHAR(20) NOT NULL CHECK (location_type IN ('SHELF', 'BACKROOM')),
    quantity NUMERIC(12,3) NOT NULL,
    cost_loss NUMERIC(12,2) NOT NULL,
    reason_code VARCHAR(50) NOT NULL, -- 'EXPIRED', 'SPOILED_ROTTEN', 'DAMAGED_DROPPED', 'TEMP_FAILURE', 'PEST_DAMAGE', 'THEFT_PILFERAGE'
    logged_by INT REFERENCES users(id) ON DELETE SET NULL,
    notes TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 20. Stocktakes (Cycle counts and full stocktakes)
CREATE TABLE stocktakes (
    id SERIAL PRIMARY KEY,
    count_number VARCHAR(50) UNIQUE NOT NULL,
    branch_id INT NOT NULL REFERENCES branches(id),
    count_type VARCHAR(35) NOT NULL CHECK (count_type IN ('FULL_STOCKTAKE', 'CYCLE_COUNT_CATEGORY', 'CYCLE_COUNT_PERISHABLES')),
    category_id INT REFERENCES categories(id),
    status VARCHAR(20) DEFAULT 'IN_PROGRESS' CHECK (status IN ('IN_PROGRESS', 'COMPLETED', 'RECONCILED', 'CANCELLED')),
    started_by INT REFERENCES users(id) ON DELETE SET NULL,
    reconciled_by INT REFERENCES users(id) ON DELETE SET NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reconciled_at TIMESTAMP,
    total_variance_cost NUMERIC(12,2) DEFAULT 0.00,
    notes TEXT
);

-- 21. Stocktake Items (Variance analysis)
CREATE TABLE stocktake_items (
    id SERIAL PRIMARY KEY,
    stocktake_id INT NOT NULL REFERENCES stocktakes(id) ON DELETE CASCADE,
    product_id INT NOT NULL REFERENCES products(id),
    batch_id INT NOT NULL REFERENCES product_batches(id),
    location_type VARCHAR(20) NOT NULL,
    system_quantity NUMERIC(12,3) NOT NULL,
    counted_quantity NUMERIC(12,3) NOT NULL,
    variance_quantity NUMERIC(12,3) NOT NULL, -- counted - system
    unit_cost NUMERIC(12,2) NOT NULL,
    variance_cost NUMERIC(12,2) NOT NULL,
    reason_code VARCHAR(50),
    adjustment_applied BOOLEAN DEFAULT FALSE
);

-- 22. Branch Transfers
CREATE TABLE branch_transfers (
    id SERIAL PRIMARY KEY,
    transfer_number VARCHAR(50) UNIQUE NOT NULL,
    from_branch_id INT NOT NULL REFERENCES branches(id),
    to_branch_id INT NOT NULL REFERENCES branches(id),
    status VARCHAR(20) DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'APPROVED', 'DISPATCHED', 'RECEIVED', 'CANCELLED')),
    requested_by INT REFERENCES users(id) ON DELETE SET NULL,
    approved_by INT REFERENCES users(id) ON DELETE SET NULL,
    dispatched_at TIMESTAMP,
    received_at TIMESTAMP,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 23. Branch Transfer Items
CREATE TABLE branch_transfer_items (
    id SERIAL PRIMARY KEY,
    transfer_id INT NOT NULL REFERENCES branch_transfers(id) ON DELETE CASCADE,
    product_id INT NOT NULL REFERENCES products(id),
    batch_id INT NOT NULL REFERENCES product_batches(id),
    quantity_requested NUMERIC(12,3) NOT NULL,
    quantity_dispatched NUMERIC(12,3) DEFAULT 0.000,
    quantity_received NUMERIC(12,3) DEFAULT 0.000
);

-- 24. Audit Trail (Stock Movements)
CREATE TABLE stock_movements (
    id SERIAL PRIMARY KEY,
    branch_id INT NOT NULL REFERENCES branches(id),
    product_id INT NOT NULL REFERENCES products(id),
    batch_id INT NOT NULL REFERENCES product_batches(id),
    location_type VARCHAR(20) NOT NULL,
    movement_type VARCHAR(30) NOT NULL, -- 'GRN_RECEIVE', 'POS_SALE', 'POS_RETURN', 'WASTAGE_LOG', 'STOCKTAKE_ADJUST', 'TRANSFER_OUT', 'TRANSFER_IN', 'SHELF_REPLENISH', 'INTERNAL_CORRECTION'
    reference_id VARCHAR(50),
    quantity_before NUMERIC(12,3) NOT NULL,
    quantity_change NUMERIC(12,3) NOT NULL,
    quantity_after NUMERIC(12,3) NOT NULL,
    unit_cost NUMERIC(12,2) NOT NULL,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for ultra-fast barcode lookups, FEFO sorting, and audit reporting
CREATE INDEX idx_products_barcode ON products(barcode);
CREATE INDEX idx_products_sku ON products(sku);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_batches_product_expiry ON product_batches(product_id, expiry_date ASC);
CREATE INDEX idx_inventory_lookup ON inventory(branch_id, product_id, location_type);
CREATE INDEX idx_movements_prod_branch ON stock_movements(product_id, branch_id, created_at DESC);
CREATE INDEX idx_sales_date ON sales(sale_time DESC);
