// =====================================================================
// TC LIMITED - Main Application Core & View Controller
// =====================================================================

const TC_App = {
    currentView: 'dashboard',
    currentBranchId: 1,
    currentUser: null,
    branches: [],

    async init() {
        console.log('[TC_App] Initializing TC Limited Grocery Inventory System...');
        await this.checkAuth();
        await this.loadBranches();
        this.bindEvents();
        this.navigateTo(this.currentView);

        // Initialize Firebase Sync
        if (window.FirebaseSync) {
            window.FirebaseSync.init(window.TC_FIREBASE_CONFIG || {});
        }
    },

    async checkAuth() {
        try {
            const res = await fetch('api/auth.php?action=me');
            const json = await res.json();
            if (json.success) {
                this.currentUser = json.data;
                this.currentBranchId = this.currentUser.branch_id || 1;
                this.updateUserUI();
            } else {
                window.location.href = 'login.php';
            }
        } catch (e) {
            console.error('[TC_App] Auth check failed:', e);
        }
    },

    updateUserUI() {
        if (!this.currentUser) return;
        const nameEl = document.getElementById('user-full-name');
        const roleEl = document.getElementById('user-role-badge');
        const avatarEl = document.getElementById('user-avatar');

        if (nameEl) nameEl.innerText = this.currentUser.full_name;
        if (roleEl) roleEl.innerText = this.currentUser.role.toUpperCase();
        if (avatarEl) {
            avatarEl.innerText = this.currentUser.full_name.charAt(0);
        }

        // Apply RBAC UI restrictions
        document.querySelectorAll('[data-role-min]').forEach(el => {
            const minRole = el.getAttribute('data-role-min');
            if (!this.hasMinRole(minRole)) {
                el.style.display = 'none';
            }
        });
    },

    hasMinRole(requiredRole) {
        if (!this.currentUser) return false;
        const roleOrder = { 'cashier': 1, 'storekeeper': 2, 'manager': 3, 'admin': 4 };
        const userLevel = roleOrder[this.currentUser.role] || 1;
        const reqLevel = roleOrder[requiredRole] || 1;
        return userLevel >= reqLevel;
    },

    async loadBranches() {
        try {
            const res = await fetch('api/auth.php?action=branches');
            const json = await res.json();
            if (json.success) {
                this.branches = json.data;
                const select = document.getElementById('branch-select');
                if (select) {
                    select.innerHTML = this.branches.map(b => 
                        `<option value="${b.id}" ${b.id == this.currentBranchId ? 'selected' : ''}>${b.name}</option>`
                    ).join('');
                }
            }
        } catch (e) {
            console.error('[TC_App] Failed to load branches:', e);
        }
    },

    async switchBranch(branchId) {
        try {
            const formData = new FormData();
            formData.append('branch_id', branchId);
            const res = await fetch('api/auth.php?action=switch_branch', { method: 'POST', body: formData });
            const json = await res.json();
            if (json.success) {
                this.currentBranchId = parseInt(branchId);
                this.toast(`Switched branch to ${json.data.branch_name}`, 'info');
                this.refreshCurrentView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Failed to switch branch: ' + e.message, 'danger');
        }
    },

    bindEvents() {
        // Nav items click
        document.querySelectorAll('.nav-link[data-view]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const view = link.getAttribute('data-view');
                this.navigateTo(view);
            });
        });

        // Branch switcher change
        const branchSelect = document.getElementById('branch-select');
        if (branchSelect) {
            branchSelect.addEventListener('change', (e) => this.switchBranch(e.target.value));
        }
    },

    navigateTo(viewName) {
        this.currentView = viewName;

        // Update nav active classes
        document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
        const activeLink = document.querySelector(`.nav-link[data-view="${viewName}"]`);
        if (activeLink) activeLink.classList.add('active');

        // Hide all views, show requested view
        document.querySelectorAll('.view-panel').forEach(p => p.style.display = 'none');
        const targetView = document.getElementById(`view-${viewName}`);
        if (targetView) {
            targetView.style.display = 'block';
        }

        // Update page title
        const titleEl = document.getElementById('page-title-text');
        if (titleEl) {
            titleEl.innerText = this.getViewTitle(viewName);
        }

        // Load specific view data
        this.loadViewData(viewName);
    },

    getViewTitle(view) {
        const titles = {
            'dashboard': 'Executive Grocery Dashboard',
            'stock': 'Multi-Branch Inventory & Shelf Tracking',
            'pos': 'Cashier POS Terminal',
            'expiry': 'Perishables, FEFO & Suggested Markdowns',
            'purchasing': 'Purchasing & Auto-Replenishment Engine',
            'grn': 'Goods Receiving Notes (GRN)',
            'stocktake': 'Stocktakes, Cycle Counts & Variance Audits',
            'transfers': 'Inter-Branch Stock Transfers',
            'reports': 'Analytics, ABC Classification & Valuation',
            'audit': 'Stock Movement Audit Trail',
            'users': 'Staff Access & Role Management'
        };
        return titles[view] || 'Inventory Management';
    },

    refreshCurrentView() {
        this.loadViewData(this.currentView);
    },

    loadViewData(view) {
        switch (view) {
            case 'dashboard': this.loadDashboard(); break;
            case 'stock': this.loadStockView(); break;
            case 'pos': if (window.TC_POS) window.TC_POS.init(); break;
            case 'expiry': this.loadExpiryView(); break;
            case 'purchasing': this.loadPurchasingView(); break;
            case 'grn': this.loadGRNView(); break;
            case 'stocktake': this.loadStocktakeView(); break;
            case 'transfers': this.loadTransfersView(); break;
            case 'reports': this.loadReportsView(); break;
            case 'audit': this.loadAuditView(); break;
            case 'users': this.loadUsersView(); break;
        }
    },

    // 1. Dashboard
    async loadDashboard() {
        try {
            const res = await fetch(`api/reports.php?action=dashboard&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                const d = json.data;
                document.getElementById('dash-val-cost').innerText = '$' + d.inventory_cost_value.toLocaleString(undefined, {minimumFractionDigits: 2});
                document.getElementById('dash-val-retail').innerText = '$' + d.inventory_retail_value.toLocaleString(undefined, {minimumFractionDigits: 2});
                document.getElementById('dash-low-stock').innerText = d.low_stock_items;
                document.getElementById('dash-expiring').innerText = d.expiring_batches_count;
                document.getElementById('dash-wastage').innerText = '$' + d.monthly_wastage_cost.toFixed(2);
                document.getElementById('dash-today-sales').innerText = '$' + d.today_revenue.toFixed(2);
                document.getElementById('dash-today-trans').innerText = d.today_transactions;

                // Update notification badge on nav
                const expBadge = document.getElementById('badge-expiry-count');
                if (expBadge) {
                    expBadge.innerText = d.expiring_batches_count;
                    expBadge.style.display = d.expiring_batches_count > 0 ? 'inline-block' : 'none';
                }

                // Render Chart
                if (window.TC_Charts && d.category_breakdown) {
                    window.TC_Charts.renderCategoryDonut('chart-categories', d.category_breakdown);
                }
            }
        } catch (e) {
            console.error('[Dashboard] Error loading metrics:', e);
        }
    },

    // 2. Stock Matrix
    async loadStockView() {
        try {
            const res = await fetch(`api/stock.php?action=matrix`);
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-stock-matrix tbody');
                if (!tbody) return;
                tbody.innerHTML = json.data.map(p => `
                    <tr>
                        <td><strong>${p.name}</strong><br><small class="text-muted">${p.sku} | ${p.barcode}</small></td>
                        <td>${p.category_name}</td>
                        <td><span class="badge-tag shelf">${p.br1_shelf}</span> / <span class="badge-tag backroom">${p.br1_backroom}</span> (<strong>${p.br1_total}</strong>)</td>
                        <td><span class="badge-tag shelf">${p.br2_shelf}</span> / <span class="badge-tag backroom">${p.br2_backroom}</span> (<strong>${p.br2_total}</strong>)</td>
                        <td><strong>${p.company_total}</strong> ${p.uom}</td>
                        <td>
                            ${parseFloat(p.company_total) <= parseInt(p.reorder_point) 
                                ? '<span class="badge-tag critical">REORDER</span>' 
                                : '<span class="badge-tag success">HEALTHY</span>'}
                        </td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick="TC_App.openBatchesModal(${p.product_id}, '${p.name.replace(/'/g, "\\'")}')">Batches</button>
                            <button class="btn btn-primary btn-sm" onclick="TC_App.openReplenishModal(${p.product_id}, '${p.name.replace(/'/g, "\\'")}')">Replenish Shelf</button>
                        </td>
                    </tr>
                `).join('');
            }
        } catch (e) {
            this.toast('Error loading stock matrix: ' + e.message, 'danger');
        }
    },

    // 3. Batches Modal
    async openBatchesModal(productId, productName) {
        document.getElementById('modal-batches-title').innerText = `Batch & Lot Details: ${productName}`;
        const modal = document.getElementById('modal-batches');
        modal.classList.add('active');

        try {
            const res = await fetch(`api/stock.php?action=batches&product_id=${productId}&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-batches tbody');
                tbody.innerHTML = json.data.map(b => {
                    const days = parseInt(b.days_to_expiry);
                    let badge = '<span class="badge-tag success">FRESH</span>';
                    if (days < 0) badge = '<span class="badge-tag expired">EXPIRED</span>';
                    else if (days <= 3) badge = `<span class="badge-tag critical">${days} DAYS LEFT</span>`;
                    else if (days <= 7) badge = `<span class="badge-tag warning">${days} DAYS LEFT</span>`;

                    return `
                        <tr>
                            <td><strong>${b.batch_number}</strong></td>
                            <td>${b.expiry_date} (${badge})</td>
                            <td>$${parseFloat(b.unit_cost).toFixed(2)}</td>
                            <td><span class="badge-tag shelf">${b.shelf_quantity}</span></td>
                            <td><span class="badge-tag backroom">${b.backroom_quantity}</span></td>
                            <td><strong>${b.total_quantity}</strong></td>
                            <td>${b.supplier_name || 'N/A'}</td>
                        </tr>
                    `;
                }).join('');
            }
        } catch (e) {
            this.toast('Failed to load batches: ' + e.message, 'danger');
        }
    },

    closeBatchesModal() {
        document.getElementById('modal-batches').classList.remove('active');
    },

    // 4. Shelf Replenishment Modal
    async openReplenishModal(productId, productName) {
        document.getElementById('replenish-product-id').value = productId;
        document.getElementById('replenish-product-name').innerText = productName;
        const select = document.getElementById('replenish-batch-select');
        const modal = document.getElementById('modal-replenish');
        modal.classList.add('active');

        try {
            const res = await fetch(`api/stock.php?action=batches&product_id=${productId}&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                select.innerHTML = json.data.map(b => 
                    `<option value="${b.batch_id}">Batch: ${b.batch_number} (Avail in Backroom: ${b.backroom_quantity}) - Exp: ${b.expiry_date}</option>`
                ).join('');
            }
        } catch (e) {
            this.toast('Failed to load batch list: ' + e.message, 'danger');
        }
    },

    closeReplenishModal() {
        document.getElementById('modal-replenish').classList.remove('active');
    },

    async submitReplenishment() {
        const pId = document.getElementById('replenish-product-id').value;
        const bId = document.getElementById('replenish-batch-select').value;
        const qty = parseFloat(document.getElementById('replenish-quantity').value || 0);

        if (qty <= 0) {
            this.toast('Please enter a valid positive replenishment quantity.', 'warning');
            return;
        }

        try {
            const res = await fetch('api/stock.php?action=replenish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    branch_id: this.currentBranchId,
                    product_id: parseInt(pId),
                    batch_id: parseInt(bId),
                    quantity: qty
                })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.closeReplenishModal();
                this.loadStockView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Replenishment failed: ' + e.message, 'danger');
        }
    },

    // 5. Expiry & Perishables View
    async loadExpiryView() {
        try {
            const res = await fetch(`api/expiry.php?action=alerts&branch_id=${this.currentBranchId}&days=14`);
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-expiry tbody');
                if (!tbody) return;

                tbody.innerHTML = json.data.map(item => {
                    const days = parseInt(item.days_remaining);
                    let badge = '';
                    if (days < 0) badge = '<span class="badge-tag expired">EXPIRED</span>';
                    else if (days <= 2) badge = `<span class="badge-tag critical">${days} DAYS LEFT</span>`;
                    else if (days <= 5) badge = `<span class="badge-tag warning">${days} DAYS LEFT</span>`;
                    else badge = `<span class="badge-tag info">${days} DAYS</span>`;

                    return `
                        <tr>
                            <td><strong>${item.product_name}</strong><br><small class="text-muted">${item.sku}</small></td>
                            <td>${item.batch_number}</td>
                            <td>${item.expiry_date} (${badge})</td>
                            <td><span class="badge-tag shelf">${item.shelf_quantity}</span> / <span class="badge-tag backroom">${item.backroom_quantity}</span></td>
                            <td>$${parseFloat(item.selling_price).toFixed(2)}</td>
                            <td>
                                <strong>${item.action_recommendation}</strong>
                                ${item.suggested_markdown_pct > 0 ? `<br><small class="text-muted">Target Promo Price: $${item.suggested_promo_price}</small>` : ''}
                            </td>
                            <td>
                                ${item.suggested_markdown_pct > 0 && !item.active_promo_id ? `
                                    <button class="btn btn-warning btn-sm" onclick="TC_App.applyMarkdown(${item.product_id}, ${item.batch_id}, ${item.suggested_markdown_pct}, '${item.product_name.replace(/'/g, "\\'")}')">
                                        Apply ${item.suggested_markdown_pct}% Markdown
                                    </button>
                                ` : ''}
                                ${item.active_promo_id ? '<span class="badge-tag success">PROMO ACTIVE</span>' : ''}
                                <button class="btn btn-danger btn-sm" onclick="TC_App.openWastageModal(${item.product_id}, ${item.batch_id}, '${item.product_name.replace(/'/g, "\\'")}', '${item.batch_number}')">
                                    Log Spoilage
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
        } catch (e) {
            this.toast('Failed to load expiry alerts: ' + e.message, 'danger');
        }
    },

    async applyMarkdown(productId, batchId, discountPct, productName) {
        if (!confirm(`Apply ${discountPct}% clearance markdown to ${productName} (Batch #${batchId})?`)) return;

        try {
            const res = await fetch('api/expiry.php?action=apply_markdown', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    product_id: productId,
                    batch_id: batchId,
                    discount_percent: discountPct,
                    title: `${productName} Near-Expiry ${discountPct}% Clearance`,
                    duration_days: 3
                })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.loadExpiryView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Markdown failed: ' + e.message, 'danger');
        }
    },

    // 6. Spoilage / Wastage Modal
    openWastageModal(productId, batchId, productName, batchNumber) {
        document.getElementById('waste-product-id').value = productId;
        document.getElementById('waste-batch-id').value = batchId;
        document.getElementById('waste-item-name').innerText = `${productName} (Batch: ${batchNumber})`;
        document.getElementById('modal-wastage').classList.add('active');
    },

    closeWastageModal() {
        document.getElementById('modal-wastage').classList.remove('active');
    },

    async submitWastage() {
        const productId = document.getElementById('waste-product-id').value;
        const batchId = document.getElementById('waste-batch-id').value;
        const loc = document.getElementById('waste-location').value;
        const qty = parseFloat(document.getElementById('waste-quantity').value || 0);
        const reason = document.getElementById('waste-reason').value;
        const notes = document.getElementById('waste-notes').value;

        if (qty <= 0) {
            this.toast('Please enter a valid wastage quantity.', 'warning');
            return;
        }

        try {
            const res = await fetch('api/expiry.php?action=log_wastage', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    branch_id: this.currentBranchId,
                    product_id: parseInt(productId),
                    batch_id: parseInt(batchId),
                    location_type: loc,
                    quantity: qty,
                    reason_code: reason,
                    notes: notes
                })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.closeWastageModal();
                this.loadExpiryView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Wastage logging error: ' + e.message, 'danger');
        }
    },

    // 7. Purchasing View
    async loadPurchasingView() {
        // Load Reorder Alerts
        try {
            const res = await fetch(`api/purchasing.php?action=reorder_alerts&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-reorder-alerts tbody');
                if (tbody) {
                    if (json.data.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-muted);">All items have adequate inventory levels! No reorders needed right now.</td></tr>`;
                    } else {
                        tbody.innerHTML = json.data.map(item => `
                            <tr>
                                <td><strong>${item.product_name}</strong><br><small class="text-muted">${item.sku}</small></td>
                                <td>${item.category_name}</td>
                                <td><span class="badge-tag critical">${item.current_stock}</span> (Reorder: ${item.reorder_point})</td>
                                <td><strong>${item.suggested_order_qty}</strong> ${item.uom}</td>
                                <td>${item.supplier_name}</td>
                                <td>$${parseFloat(item.unit_order_cost).toFixed(2)}</td>
                                <td>$${(item.suggested_order_qty * item.unit_order_cost).toFixed(2)}</td>
                            </tr>
                        `).join('');
                    }
                }
            }

            // Load Existing POs
            const poRes = await fetch(`api/purchasing.php?branch_id=${this.currentBranchId}`);
            const poJson = await poRes.json();
            if (poJson.success) {
                const poTbody = document.querySelector('#table-pos tbody');
                if (poTbody) {
                    poTbody.innerHTML = poJson.data.map(po => `
                        <tr>
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.supplier_name}</td>
                            <td>${po.order_date}</td>
                            <td>${po.expected_delivery_date || 'N/A'}</td>
                            <td><strong>$${parseFloat(po.total_amount).toFixed(2)}</strong></td>
                            <td><span class="badge-tag ${po.status === 'COMPLETED' ? 'success' : 'warning'}">${po.status}</span></td>
                            <td>${po.is_auto_generated ? '<span class="badge-tag info">AUTO-GENERATED</span>' : 'Manual'}</td>
                        </tr>
                    `).join('');
                }
            }
        } catch (e) {
            this.toast('Failed to load purchasing: ' + e.message, 'danger');
        }
    },

    async triggerAutoPO() {
        if (!confirm('Run Automatic Purchase Order Engine now? This will scan all stock against reorder points and create draft POs for preferred suppliers.')) return;

        try {
            const res = await fetch('api/purchasing.php?action=auto_generate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ branch_id: this.currentBranchId })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.loadPurchasingView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Auto-PO error: ' + e.message, 'danger');
        }
    },

    // 8. Goods Receiving Notes (GRN) View
    async loadGRNView() {
        try {
            const res = await fetch(`api/grn.php?branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-grn tbody');
                if (tbody) {
                    tbody.innerHTML = json.data.map(grn => `
                        <tr>
                            <td><strong>${grn.grn_number}</strong></td>
                            <td>${grn.po_number || 'Direct Delivery'}</td>
                            <td>${grn.supplier_name}</td>
                            <td>${grn.invoice_number || 'N/A'}</td>
                            <td>${grn.received_date}</td>
                            <td><strong>${grn.total_units_received}</strong> units</td>
                            <td>${grn.received_by_name}</td>
                        </tr>
                    `).join('');
                }
            }
        } catch (e) {
            this.toast('Failed to load GRNs: ' + e.message, 'danger');
        }
    },

    openNewGRNModal() {
        document.getElementById('modal-grn').classList.add('active');
        this.addGRNRow();
    },

    closeGRNModal() {
        document.getElementById('modal-grn').classList.remove('active');
    },

    addGRNRow() {
        const tbody = document.querySelector('#table-grn-items tbody');
        if (!tbody) return;
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <select class="form-control grn-p-select">
                    ${(window.TC_POS ? window.TC_POS.catalog : []).map(p => `<option value="${p.id}" data-cost="${p.cost_price}">${p.name} (${p.sku})</option>`).join('')}
                </select>
            </td>
            <td><input type="text" class="form-control grn-batch" value="LOT-${new Date().toISOString().slice(0,10).replace(/-/g,'')}-01"></td>
            <td><input type="date" class="form-control grn-exp" value="${new Date(Date.now() + 30*86400000).toISOString().slice(0,10)}"></td>
            <td><input type="number" class="form-control grn-qty" value="50" min="1"></td>
            <td><input type="number" class="form-control grn-shelf" value="20" min="0"></td>
            <td><input type="number" class="form-control grn-cost" value="1.20" step="0.01"></td>
            <td><button class="btn btn-danger btn-sm" onclick="this.closest('tr').remove()">✕</button></td>
        `;
        tbody.appendChild(row);
    },

    async submitGRN() {
        const supId = document.getElementById('grn-supplier-id').value;
        const invNo = document.getElementById('grn-invoice-no').value;
        const notes = document.getElementById('grn-notes').value;

        const itemRows = document.querySelectorAll('#table-grn-items tbody tr');
        if (itemRows.length === 0) {
            this.toast('Add at least one product row to receive.', 'warning');
            return;
        }

        const items = [];
        itemRows.forEach(r => {
            const pId = parseInt(r.querySelector('.grn-p-select').value);
            const batch = r.querySelector('.grn-batch').value.trim();
            const exp = r.querySelector('.grn-exp').value;
            const qty = parseFloat(r.querySelector('.grn-qty').value || 0);
            const shelf = parseFloat(r.querySelector('.grn-shelf').value || 0);
            const backroom = Math.max(0, qty - shelf);
            const cost = parseFloat(r.querySelector('.grn-cost').value || 0);

            items.push({
                product_id: pId,
                batch_number: batch,
                expiry_date: exp,
                quantity_received: qty,
                allocated_shelf_qty: shelf,
                allocated_backroom_qty: backroom,
                unit_cost: cost
            });
        });

        try {
            const res = await fetch('api/grn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    branch_id: this.currentBranchId,
                    supplier_id: parseInt(supId),
                    invoice_number: invNo,
                    notes: notes,
                    items: items
                })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.closeGRNModal();
                this.loadGRNView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('GRN error: ' + e.message, 'danger');
        }
    },

    // 9. Stocktake & Variance Audits
    async loadStocktakeView() {
        try {
            const res = await fetch(`api/stocktake.php?branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-stocktakes tbody');
                if (tbody) {
                    tbody.innerHTML = json.data.map(st => `
                        <tr>
                            <td><strong>${st.count_number}</strong></td>
                            <td>${st.count_type.replace(/_/g, ' ')}</td>
                            <td><span class="badge-tag ${st.status === 'RECONCILED' ? 'success' : 'warning'}">${st.status}</span></td>
                            <td>${st.started_at}</td>
                            <td>${st.item_count} items (${st.variance_count} variances)</td>
                            <td>$${parseFloat(st.total_variance_cost).toFixed(2)}</td>
                            <td>
                                <button class="btn btn-secondary btn-sm" onclick="TC_App.openVarianceReport(${st.id})">View Variance Report</button>
                                ${st.status !== 'RECONCILED' ? `
                                    <button class="btn btn-primary btn-sm" onclick="TC_App.reconcileStocktake(${st.id})">Reconcile & Adjust</button>
                                ` : ''}
                            </td>
                        </tr>
                    `).join('');
                }
            }
        } catch (e) {
            this.toast('Failed to load stocktakes: ' + e.message, 'danger');
        }
    },

    async openVarianceReport(stocktakeId) {
        const modal = document.getElementById('modal-variance-report');
        modal.classList.add('active');

        try {
            const res = await fetch(`api/stocktake.php?stocktake_id=${stocktakeId}`);
            const json = await res.json();
            if (json.success) {
                document.getElementById('variance-report-title').innerText = `Variance Audit Report: ${json.data.stocktake.count_number}`;
                const tbody = document.querySelector('#table-variance-items tbody');
                tbody.innerHTML = json.data.items.map(it => {
                    const varQty = parseFloat(it.variance_quantity);
                    let varBadge = '<span class="badge-tag success">0 MATCH</span>';
                    if (varQty < 0) varBadge = `<span class="badge-tag critical">${varQty} LOSS</span>`;
                    else if (varQty > 0) varBadge = `<span class="badge-tag warning">+${varQty} SURPLUS</span>`;

                    return `
                        <tr>
                            <td><strong>${it.product_name}</strong><br><small class="text-muted">${it.sku} | ${it.batch_number}</small></td>
                            <td>${it.location_type}</td>
                            <td>${it.system_quantity}</td>
                            <td><strong>${it.counted_quantity}</strong></td>
                            <td>${varBadge}</td>
                            <td>$${parseFloat(it.variance_cost).toFixed(2)}</td>
                            <td>${it.reason_code || 'Unspecified'}</td>
                        </tr>
                    `;
                }).join('');
            }
        } catch (e) {
            this.toast('Failed to load variance report: ' + e.message, 'danger');
        }
    },

    closeVarianceModal() {
        document.getElementById('modal-variance-report').classList.remove('active');
    },

    async reconcileStocktake(stocktakeId) {
        if (!confirm('Reconcile this stocktake session? This will automatically adjust physical inventory levels and record the variances into the Immutable Audit Trail.')) return;

        try {
            const res = await fetch('api/stocktake.php?action=reconcile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ stocktake_id: stocktakeId })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.loadStocktakeView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Reconcile failed: ' + e.message, 'danger');
        }
    },

    // 10. Inter-branch Transfers View
    async loadTransfersView() {
        try {
            const res = await fetch('api/transfers.php');
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-transfers tbody');
                if (tbody) {
                    tbody.innerHTML = json.data.map(trf => `
                        <tr>
                            <td><strong>${trf.transfer_number}</strong></td>
                            <td>${trf.from_branch_name}</td>
                            <td>${trf.to_branch_name}</td>
                            <td><span class="badge-tag ${trf.status === 'RECEIVED' ? 'success' : (trf.status === 'DISPATCHED' ? 'warning' : 'info')}">${trf.status}</span></td>
                            <td>${trf.created_at}</td>
                            <td>
                                ${trf.status === 'PENDING' ? `
                                    <button class="btn btn-primary btn-sm" onclick="TC_App.dispatchTransfer(${trf.id})">Dispatch Stock</button>
                                ` : ''}
                                ${trf.status === 'DISPATCHED' ? `
                                    <button class="btn btn-success btn-sm" onclick="TC_App.receiveTransfer(${trf.id})">Receive into Stock</button>
                                ` : ''}
                            </td>
                        </tr>
                    `).join('');
                }
            }
        } catch (e) {
            this.toast('Failed to load transfers: ' + e.message, 'danger');
        }
    },

    async dispatchTransfer(transferId) {
        if (!confirm('Dispatch this inter-branch transfer? Stock will be deducted from the source branch.')) return;
        try {
            const res = await fetch('api/transfers.php?action=dispatch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ transfer_id: transferId })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.loadTransfersView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Dispatch error: ' + e.message, 'danger');
        }
    },

    async receiveTransfer(transferId) {
        if (!confirm('Receive this transfer? Stock will be incremented in the destination branch.')) return;
        try {
            const res = await fetch('api/transfers.php?action=receive', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ transfer_id: transferId })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.loadTransfersView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Receive error: ' + e.message, 'danger');
        }
    },

    // 11. Reports & Analytics View (ABC, Valuation, Forecasting, Margins)
    async loadReportsView() {
        this.switchReportTab('abc');
    },

    async switchReportTab(tab) {
        document.querySelectorAll('.report-tab-btn').forEach(b => b.classList.remove('active'));
        const activeBtn = document.querySelector(`.report-tab-btn[data-tab="${tab}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        const content = document.getElementById('report-content-container');
        content.innerHTML = `<div style="text-align:center;padding:40px;">Loading analytical report...</div>`;

        if (tab === 'abc') {
            const res = await fetch(`api/reports.php?action=abc_analysis&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                content.innerHTML = `
                    <div style="margin-bottom:12px;font-size:13px;color:var(--text-muted);">
                        <strong>ABC Analysis (Pareto Classification):</strong> Class A items represent top 80% revenue volume (tight control & daily FEFO verification); Class B items represent next 15%; Class C items represent lowest 5%.
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr><th>Class</th><th>Product</th><th>Category</th><th>Units Sold</th><th>Total Revenue</th><th>Cum. %</th><th>Management Action</th></tr>
                        </thead>
                        <tbody>
                            ${json.data.map(i => `
                                <tr>
                                    <td><span class="badge-tag ${i.abc_class === 'A' ? 'critical' : (i.abc_class === 'B' ? 'warning' : 'info')}">Class ${i.abc_class}</span></td>
                                    <td><strong>${i.product_name}</strong><br><small class="text-muted">${i.sku}</small></td>
                                    <td>${i.category_name}</td>
                                    <td>${i.units_sold}</td>
                                    <td><strong>$${parseFloat(i.total_revenue).toFixed(2)}</strong></td>
                                    <td>${i.cumulative_percentage}%</td>
                                    <td>${i.management_priority}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
        } else if (tab === 'valuation') {
            const res = await fetch(`api/reports.php?action=valuation&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                content.innerHTML = `
                    <div style="margin-bottom:12px;font-size:13px;color:var(--text-muted);">
                        <strong>Inventory Valuation Methods:</strong> Compares FIFO (lot/batch purchase cost) vs Weighted Average Cost (WAC) and unrealized retail value.
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr><th>Product</th><th>Category</th><th>Total Units</th><th>FIFO Valuation</th><th>WAC Valuation</th><th>Retail Value</th><th>Gross Margin Potential</th></tr>
                        </thead>
                        <tbody>
                            ${json.data.map(i => `
                                <tr>
                                    <td><strong>${i.name}</strong><br><small class="text-muted">${i.sku}</small></td>
                                    <td>${i.category_name}</td>
                                    <td>${i.total_stock} ${i.uom}</td>
                                    <td><strong>$${parseFloat(i.fifo_valuation).toFixed(2)}</strong></td>
                                    <td>$${parseFloat(i.wac_valuation).toFixed(2)}</td>
                                    <td>$${parseFloat(i.retail_valuation).toFixed(2)}</td>
                                    <td><span class="badge-tag success">+$${parseFloat(i.potential_gross_profit).toFixed(2)}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
        } else if (tab === 'forecast') {
            const res = await fetch(`api/reports.php?action=forecasting&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success) {
                content.innerHTML = `
                    <div style="margin-bottom:12px;font-size:13px;color:var(--text-muted);">
                        <strong>Demand Forecasting & Stockout Predictor:</strong> Calculates 30-day velocity, daily burn rate, and Days of Inventory (DOI) remaining.
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr><th>Product</th><th>Daily Burn Rate</th><th>Current Stock</th><th>Days of Inventory (DOI)</th><th>Projected Runout Date</th><th>Risk Status</th></tr>
                        </thead>
                        <tbody>
                            ${json.data.map(i => `
                                <tr>
                                    <td><strong>${i.name}</strong><br><small class="text-muted">${i.sku}</small></td>
                                    <td>${i.daily_burn_rate} units/day</td>
                                    <td>${i.current_stock}</td>
                                    <td><strong>${i.days_of_inventory} days</strong></td>
                                    <td>${i.projected_runout_date}</td>
                                    <td><span class="badge-tag ${i.stockout_risk.includes('CRITICAL') ? 'critical' : (i.stockout_risk.includes('WARNING') ? 'warning' : 'success')}">${i.stockout_risk}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
        }
    },

    // 12. Audit Trail View
    async loadAuditView() {
        try {
            const res = await fetch(`api/audit.php?branch_id=${this.currentBranchId}&limit=50`);
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-audit tbody');
                if (tbody) {
                    tbody.innerHTML = json.data.map(a => {
                        const change = parseFloat(a.quantity_change);
                        const changeBadge = change >= 0 
                            ? `<span class="badge-tag success">+${change}</span>` 
                            : `<span class="badge-tag critical">${change}</span>`;

                        return `
                            <tr>
                                <td><small>${a.created_at}</small></td>
                                <td><strong>${a.product_name}</strong><br><small class="text-muted">${a.sku} | ${a.batch_number || 'N/A'}</small></td>
                                <td><span class="badge-tag ${a.location_type === 'SHELF' ? 'shelf' : 'backroom'}">${a.location_type}</span></td>
                                <td><strong>${a.movement_type}</strong></td>
                                <td><code>${a.reference_id || '-'}</code></td>
                                <td>${a.quantity_before}</td>
                                <td>${changeBadge}</td>
                                <td><strong>${a.quantity_after}</strong></td>
                                <td>${a.user_name || 'System'}</td>
                                <td><small class="text-muted">${a.notes || ''}</small></td>
                            </tr>
                        `;
                    }).join('');
                }
            }
        } catch (e) {
            this.toast('Failed to load audit trail: ' + e.message, 'danger');
        }
    },

    // 13. Users Administration View (Add & Delete Users)
    async loadUsersView() {
        try {
            const res = await fetch('api/users.php');
            const json = await res.json();
            if (json.success) {
                const tbody = document.querySelector('#table-users tbody');
                if (tbody) {
                    const currentId = this.currentUser ? parseInt(this.currentUser.id) : 0;
                    tbody.innerHTML = json.data.map(u => {
                        const isSelf = parseInt(u.id) === currentId;
                        const isPrimaryAdmin = u.username === 'admin';

                        let actionBtn = '';
                        if (isPrimaryAdmin) {
                            actionBtn = `<span class="badge-tag info">Root Admin</span>`;
                        } else if (isSelf) {
                            actionBtn = `<span class="badge-tag success">Your Account</span>`;
                        } else {
                            actionBtn = `
                                <button class="btn btn-danger btn-sm" onclick="TC_App.deleteUser(${u.id}, '${u.username}', '${u.full_name.replace(/'/g, "\\'")}')">
                                    🗑️ Delete
                                </button>
                            `;
                        }

                        return `
                            <tr>
                                <td><strong>${u.full_name}</strong></td>
                                <td><code>@${u.username}</code></td>
                                <td><span class="badge-tag ${u.role_name === 'admin' ? 'critical' : (u.role_name === 'manager' ? 'warning' : 'info')}">${u.role_name.toUpperCase()}</span></td>
                                <td>${u.branch_name || 'All Branches (Floating)'}</td>
                                <td><code>${u.pin_code}</code></td>
                                <td><span class="badge-tag success">ACTIVE</span></td>
                                <td>${actionBtn}</td>
                            </tr>
                        `;
                    }).join('');
                }
            }
        } catch (e) {
            this.toast('Failed to load users: ' + e.message, 'danger');
        }
    },

    openAddUserModal() {
        const modal = document.getElementById('modal-add-user');
        if (modal) {
            document.getElementById('add-user-error').style.display = 'none';
            document.getElementById('new-user-fullname').value = '';
            document.getElementById('new-user-username').value = '';
            document.getElementById('new-user-email').value = '';
            document.getElementById('new-user-role').value = '4';
            document.getElementById('new-user-password').value = 'password123';
            document.getElementById('new-user-pin').value = '1234';
            modal.classList.add('active');
            document.getElementById('new-user-fullname').focus();
        }
    },

    closeAddUserModal() {
        const modal = document.getElementById('modal-add-user');
        if (modal) modal.classList.remove('active');
    },

    async submitAddUser() {
        const errEl = document.getElementById('add-user-error');
        errEl.style.display = 'none';

        const fullName = document.getElementById('new-user-fullname').value.trim();
        const username = document.getElementById('new-user-username').value.trim().toLowerCase();
        const email = document.getElementById('new-user-email').value.trim();
        const roleId = parseInt(document.getElementById('new-user-role').value);
        const branchVal = document.getElementById('new-user-branch').value;
        const branchId = branchVal ? parseInt(branchVal) : null;
        const password = document.getElementById('new-user-password').value.trim();
        const pin = document.getElementById('new-user-pin').value.trim();

        if (!fullName || !username || !password) {
            errEl.innerText = 'Full Name, Username, and Password are required.';
            errEl.style.display = 'block';
            return;
        }

        try {
            const res = await fetch('api/users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    full_name: fullName,
                    username: username,
                    email: email,
                    role_id: roleId,
                    branch_id: branchId,
                    password: password,
                    pin_code: pin
                })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.closeAddUserModal();
                this.loadUsersView();
            } else {
                errEl.innerText = json.error || 'Failed to create user.';
                errEl.style.display = 'block';
            }
        } catch (e) {
            errEl.innerText = 'Network error: ' + e.message;
            errEl.style.display = 'block';
        }
    },

    async deleteUser(userId, username, fullName) {
        if (!confirm(`Are you sure you want to permanently delete user "${fullName}" (@${username}) from the system?\n\nThis will remove the login credentials while preserving historical audit records.`)) {
            return;
        }

        try {
            const res = await fetch('api/users.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: userId })
            });
            const json = await res.json();
            if (json.success) {
                this.toast(json.message, 'success');
                this.loadUsersView();
            } else {
                this.toast(json.error, 'danger');
            }
        } catch (e) {
            this.toast('Delete user error: ' + e.message, 'danger');
        }
    },

    // Global Barcode Scan routing
    handleGlobalBarcodeScan(barcode) {
        this.toast(`Global scan detected: ${barcode}`, 'info');
        // If not in POS, open stock check modal
        this.openStockCheckModal(barcode);
    },

    async openStockCheckModal(barcode) {
        try {
            const res = await fetch(`api/products.php?action=lookup&code=${encodeURIComponent(barcode)}&branch_id=${this.currentBranchId}`);
            const json = await res.json();
            if (json.success && json.data) {
                const p = json.data;
                alert(`BARCODE LOOKUP:\nProduct: ${p.name}\nSKU: ${p.sku}\nSelling Price: $${parseFloat(p.selling_price).toFixed(2)}\nShelf Stock: ${p.shelf_stock} ${p.uom}\nBackroom Stock: ${p.backroom_stock} ${p.uom}\nTotal Available: ${p.total_stock} ${p.uom}`);
            } else {
                this.toast('Product not found for barcode: ' + barcode, 'warning');
            }
        } catch (e) {
            this.toast('Scan error: ' + e.message, 'danger');
        }
    },

    toast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.innerHTML = `<span>${message}</span>`;
        container.appendChild(el);

        setTimeout(() => {
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 300);
        }, 4000);
    },

    async logout() {
        if (!confirm('Log out of TC Limited Grocery System?')) return;
        await fetch('api/auth.php?action=logout');
        window.location.href = 'login.php';
    }
};

window.TC_App = TC_App;
document.addEventListener('DOMContentLoaded', () => TC_App.init());
