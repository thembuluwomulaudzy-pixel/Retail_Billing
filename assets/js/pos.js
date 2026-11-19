// =====================================================================
// TC LIMITED - Point of Sale (POS) Terminal Controller
// =====================================================================

const TC_POS = {
    cart: [],
    catalog: [],
    categories: [],
    selectedCategory: null,
    searchQuery: '',

    async init() {
        console.log('[POS] Initializing terminal...');
        await this.loadCategories();
        await this.loadCatalog();
        this.renderCart();
    },

    async loadCategories() {
        try {
            const res = await fetch('api/products.php?action=categories');
            const json = await res.json();
            if (json.success) {
                this.categories = json.data;
                this.renderCategoryTabs();
            }
        } catch (e) {
            console.error('[POS] Failed to load categories:', e);
        }
    },

    renderCategoryTabs() {
        const container = document.getElementById('pos-categories');
        if (!container) return;
        let html = `<div class="category-pill ${!this.selectedCategory ? 'active' : ''}" onclick="TC_POS.filterCategory(null)">All Items</div>`;
        this.categories.forEach(c => {
            const isActive = this.selectedCategory === c.id ? 'active' : '';
            html += `<div class="category-pill ${isActive}" onclick="TC_POS.filterCategory(${c.id})">${c.name}</div>`;
        });
        container.innerHTML = html;
    },

    filterCategory(catId) {
        this.selectedCategory = catId;
        this.renderCategoryTabs();
        this.renderCatalog();
    },

    async loadCatalog() {
        try {
            const branchId = window.TC_App ? window.TC_App.currentBranchId : 1;
            const res = await fetch(`api/products.php?branch_id=${branchId}`);
            const json = await res.json();
            if (json.success) {
                this.catalog = json.data;
                this.renderCatalog();
            }
        } catch (e) {
            console.error('[POS] Failed to load products:', e);
        }
    },

    renderCatalog() {
        const container = document.getElementById('pos-products-grid');
        if (!container) return;

        let filtered = this.catalog;
        if (this.selectedCategory) {
            filtered = filtered.filter(p => parseInt(p.category_id) === this.selectedCategory);
        }
        if (this.searchQuery) {
            const q = this.searchQuery.toLowerCase();
            filtered = filtered.filter(p => 
                p.name.toLowerCase().includes(q) || 
                p.sku.toLowerCase().includes(q) || 
                p.barcode.includes(q)
            );
        }

        if (filtered.length === 0) {
            container.innerHTML = `<div style="grid-column: 1/-1; padding: 40px; text-align: center; color: var(--text-muted);">No products found matching filters.</div>`;
            return;
        }

        let html = '';
        filtered.forEach(p => {
            const price = parseFloat(p.selling_price).toFixed(2);
            const stock = parseFloat(p.shelf_stock || p.total_stock || 0);
            const isOutOfStock = stock <= 0;

            html += `
                <div class="product-tile ${isOutOfStock ? 'out-of-stock' : ''}" onclick="TC_POS.addToCart(${p.id})">
                    <div>
                        <div class="tile-title">${p.name}</div>
                        <div class="tile-sku">${p.sku}</div>
                    </div>
                    <div class="tile-footer">
                        <div class="tile-price">$${price}</div>
                        <div class="tile-stock">${stock} ${p.uom}</div>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    },

    searchProducts(query) {
        this.searchQuery = query.trim();
        this.renderCatalog();
    },

    async handleScan(barcode) {
        try {
            const branchId = window.TC_App ? window.TC_App.currentBranchId : 1;
            const res = await fetch(`api/products.php?action=lookup&code=${encodeURIComponent(barcode)}&branch_id=${branchId}`);
            const json = await res.json();
            if (json.success && json.data) {
                this.addProductToCart(json.data);
                window.TC_App.toast(`Scanned: ${json.data.name}`, 'success');
            } else {
                window.TC_App.toast(`Item with barcode ${barcode} not found!`, 'danger');
            }
        } catch (e) {
            window.TC_App.toast('Barcode lookup error: ' + e.message, 'danger');
        }
    },

    addToCart(productId) {
        const product = this.catalog.find(p => parseInt(p.id) === productId);
        if (product) {
            this.addProductToCart(product);
        }
    },

    addProductToCart(product) {
        const existing = this.cart.find(item => item.id === product.id);
        const availableStock = parseFloat(product.shelf_stock || product.total_stock || 0);

        if (existing) {
            if (existing.quantity + 1 > availableStock) {
                window.TC_App.toast(`Warning: Cannot add more than available shelf stock (${availableStock} ${product.uom})`, 'warning');
            }
            existing.quantity += 1;
        } else {
            if (availableStock <= 0) {
                window.TC_App.toast(`Warning: ${product.name} is currently out of stock on shelf!`, 'warning');
            }
            this.cart.push({
                id: product.id,
                sku: product.sku,
                barcode: product.barcode,
                name: product.name,
                uom: product.uom,
                unit_price: parseFloat(product.effective_price || product.selling_price),
                base_price: parseFloat(product.selling_price),
                tax_rate: parseFloat(product.default_tax_rate || 0),
                quantity: 1,
                has_promo: !!product.active_promotion
            });
        }
        this.renderCart();
    },

    updateQty(productId, delta) {
        const item = this.cart.find(i => i.id === productId);
        if (!item) return;

        item.quantity += delta;
        if (item.quantity <= 0) {
            this.cart = this.cart.filter(i => i.id !== productId);
        }
        this.renderCart();
    },

    clearCart() {
        this.cart = [];
        this.renderCart();
    },

    renderCart() {
        const container = document.getElementById('pos-cart-items');
        if (!container) return;

        if (this.cart.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 50px 20px; color: var(--text-muted);">
                    <div style="font-size: 36px; margin-bottom: 8px;">🛒</div>
                    <div style="font-weight: 600;">Cart is empty</div>
                    <div style="font-size: 12px; margin-top: 4px;">Scan barcode or click items to add</div>
                </div>
            `;
            this.updateSummary(0, 0, 0, 0);
            return;
        }

        let html = '';
        let subtotal = 0;
        let discount = 0;
        let tax = 0;

        this.cart.forEach(item => {
            const lineSubtotal = item.quantity * item.base_price;
            const lineEffective = item.quantity * item.unit_price;
            const lineDisc = lineSubtotal - lineEffective;
            const lineTax = (lineEffective * item.tax_rate) / 100;

            subtotal += lineSubtotal;
            discount += lineDisc;
            tax += lineTax;

            html += `
                <div class="cart-item-row">
                    <div class="cart-item-info">
                        <div class="item-name">${item.name}</div>
                        <div class="item-meta">
                            $${item.unit_price.toFixed(2)} / ${item.uom}
                            ${item.has_promo ? '<span style="color:var(--danger);font-weight:700;"> (PROMO)</span>' : ''}
                        </div>
                    </div>
                    <div class="cart-qty-ctrl">
                        <button onclick="TC_POS.updateQty(${item.id}, -1)">−</button>
                        <span>${item.quantity}</span>
                        <button onclick="TC_POS.updateQty(${item.id}, 1)">+</button>
                    </div>
                    <div class="cart-item-total">$${lineEffective.toFixed(2)}</div>
                </div>
            `;
        });

        container.innerHTML = html;
        const grandTotal = subtotal - discount + tax;
        this.updateSummary(subtotal, discount, tax, grandTotal);
    },

    updateSummary(subtotal, discount, tax, grandTotal) {
        document.getElementById('pos-subtotal').innerText = '$' + subtotal.toFixed(2);
        document.getElementById('pos-discount').innerText = '-$' + discount.toFixed(2);
        document.getElementById('pos-tax').innerText = '$' + tax.toFixed(2);
        document.getElementById('pos-grand-total').innerText = '$' + grandTotal.toFixed(2);
        this.grandTotal = grandTotal;
    },

    openCheckoutModal() {
        if (this.cart.length === 0) {
            window.TC_App.toast('Cart is empty. Add items to checkout.', 'warning');
            return;
        }
        const modal = document.getElementById('modal-checkout');
        document.getElementById('checkout-payable').innerText = '$' + this.grandTotal.toFixed(2);
        const tenderInput = document.getElementById('checkout-tendered');
        tenderInput.value = this.grandTotal.toFixed(2);
        this.calculateChange();
        modal.classList.add('active');
        tenderInput.focus();
    },

    closeCheckoutModal() {
        document.getElementById('modal-checkout').classList.remove('active');
    },

    calculateChange() {
        const payable = this.grandTotal || 0;
        const tendered = parseFloat(document.getElementById('checkout-tendered').value || 0);
        const change = Math.max(0, tendered - payable);
        document.getElementById('checkout-change').innerText = '$' + change.toFixed(2);
    },

    async processPayment() {
        const paymentMethod = document.getElementById('checkout-payment-method').value;
        const tendered = parseFloat(document.getElementById('checkout-tendered').value || 0);
        const branchId = window.TC_App ? window.TC_App.currentBranchId : 1;

        if (paymentMethod === 'CASH' && tendered < this.grandTotal) {
            window.TC_App.toast('Tendered cash is less than payable amount!', 'warning');
            return;
        }

        const payload = {
            branch_id: branchId,
            payment_method: paymentMethod,
            amount_tendered: tendered,
            items: this.cart.map(i => ({
                product_id: i.id,
                quantity: i.quantity
            }))
        };

        try {
            const res = await fetch('api/pos.php?action=checkout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            if (json.success) {
                this.closeCheckoutModal();
                this.printReceipt(json.data);
                this.clearCart();
                window.TC_App.toast(`Checkout successful! Receipt: ${json.data.receipt_number}`, 'success');
                // Reload catalog to update live shelf stock
                this.loadCatalog();
            } else {
                window.TC_App.toast('Payment failed: ' + json.error, 'danger');
            }
        } catch (e) {
            window.TC_App.toast('Payment error: ' + e.message, 'danger');
        }
    },

    printReceipt(saleData) {
        const printable = document.getElementById('printable-receipt');
        if (!printable) return;

        let itemsHtml = '';
        saleData.lines.forEach(l => {
            const product = this.catalog.find(p => p.id === l.product_id) || { name: 'Item', uom: 'unit' };
            itemsHtml += `
                <tr>
                    <td>${product.name}<br><small>${l.quantity} x $${parseFloat(l.unit_price).toFixed(2)}</small></td>
                    <td style="text-align:right;">$${parseFloat(l.line_total).toFixed(2)}</td>
                </tr>
            `;
        });

        printable.innerHTML = `
            <div class="receipt-header">
                <h2>TC LIMITED GROCERY</h2>
                <div>Fresh Produce & Quality Groceries</div>
                <div>Harare, Zimbabwe</div>
                <div>Tel: +263 242 700101</div>
                <div style="margin-top:6px;">Receipt: <strong>${saleData.receipt_number}</strong></div>
                <div>Date: ${new Date().toLocaleString()}</div>
            </div>
            <table class="receipt-items">
                <thead>
                    <tr><th>Item</th><th style="text-align:right;">Total</th></tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
            <div class="receipt-totals">
                <div class="line"><span>Subtotal:</span><span>$${saleData.subtotal.toFixed(2)}</span></div>
                <div class="line"><span>Discount:</span><span>-$${saleData.discount_amount.toFixed(2)}</span></div>
                <div class="line"><span>Tax (VAT):</span><span>$${saleData.tax_amount.toFixed(2)}</span></div>
                <div class="line grand-total"><span>TOTAL:</span><span>$${saleData.total_amount.toFixed(2)}</span></div>
                <div class="line"><span>Paid (${saleData.payment_method}):</span><span>$${saleData.amount_tendered.toFixed(2)}</span></div>
                <div class="line"><span>Change Due:</span><span>$${saleData.change_due.toFixed(2)}</span></div>
            </div>
            <div class="receipt-footer">
                <div>Thank you for shopping with TC Limited!</div>
                <div>Goods returnable within 48h with receipt.</div>
                <div>*** FEFO Stock Batch Verified ***</div>
            </div>
        `;

        // Trigger browser print
        setTimeout(() => window.print(), 300);
    }
};

window.TC_POS = TC_POS;
