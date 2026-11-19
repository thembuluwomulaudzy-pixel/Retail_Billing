<?php
require_once __DIR__ . '/config/auth.php';
if (Auth::check()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TC Limited - Grocery Inventory Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .login-page {
            min-height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #14532d 0%, #166534 45%, #15803d 100%);
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            padding: 38px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 20px 35px -5px rgba(20, 83, 45, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.4);
            position: relative;
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #16a34a, #86efac);
            border-top-left-radius: var(--radius-lg);
            border-top-right-radius: var(--radius-lg);
        }
        .login-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 26px;
        }
        .login-brand .brand-icon {
            width: 48px;
            height: 48px;
            background: #dcfce7;
            color: #15803d;
            border: 2px solid #86efac;
            font-size: 24px;
            box-shadow: none;
        }
        .role-preset-btn {
            padding: 10px;
            border-radius: var(--radius-sm);
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #14532d;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s;
        }
        .role-preset-btn:hover {
            border-color: #16a34a;
            background: #dcfce7;
            color: #15803d;
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <div class="brand-icon">🛒</div>
            <div>
                <h1 style="font-size:22px;font-weight:900;color:var(--primary-darker);letter-spacing:-0.5px;">TC LIMITED</h1>
                <span style="font-size:11px;font-weight:800;color:var(--primary);letter-spacing:1px;text-transform:uppercase;">Grocery Inventory Portal</span>
            </div>
        </div>

        <form id="login-form" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" class="form-control" placeholder="Enter username" required value="admin">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" class="form-control" placeholder="Enter password" required value="password123">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;font-size:14px;margin-top:8px;">
                Sign In to System
            </button>
        </form>

        <div style="margin-top:24px;border-top:1px solid #e2ece5;padding-top:18px;">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;color:var(--primary-dark);margin-bottom:10px;letter-spacing:0.5px;">
                Demo Role Presets (1-Click Fill)
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <button type="button" class="role-preset-btn" onclick="fillRole('admin')">
                    👑 <strong>Admin</strong><br><small style="color:var(--text-muted);">All modules & config</small>
                </button>
                <button type="button" class="role-preset-btn" onclick="fillRole('manager')">
                    📊 <strong>Store Manager</strong><br><small style="color:var(--text-muted);">Purchasing & Audits</small>
                </button>
                <button type="button" class="role-preset-btn" onclick="fillRole('storekeeper')">
                    📦 <strong>Storekeeper</strong><br><small style="color:var(--text-muted);">GRN & Stocktake</small>
                </button>
                <button type="button" class="role-preset-btn" onclick="fillRole('cashier')">
                    💳 <strong>Cashier</strong><br><small style="color:var(--text-muted);">POS Sales & Returns</small>
                </button>
            </div>
        </div>

        <div id="login-error" style="display:none;margin-top:16px;padding:10px;background:#fee2e2;border:1px solid #f87171;color:#b91c1c;border-radius:6px;font-size:12px;text-align:center;"></div>
    </div>

    <script>
        function fillRole(role) {
            document.getElementById('username').value = role;
            document.getElementById('password').value = 'password123';
        }

        async function handleLogin(e) {
            e.preventDefault();
            const errEl = document.getElementById('login-error');
            errEl.style.display = 'none';

            const user = document.getElementById('username').value.trim();
            const pass = document.getElementById('password').value.trim();

            const formData = new FormData();
            formData.append('username', user);
            formData.append('password', pass);

            try {
                const res = await fetch('api/auth.php?action=login', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();
                if (json.success) {
                    window.location.href = json.data.redirect || 'index.php';
                } else {
                    errEl.innerText = json.error || 'Login failed.';
                    errEl.style.display = 'block';
                }
            } catch (err) {
                errEl.innerText = 'Network error: ' + err.message;
                errEl.style.display = 'block';
            }
        }
    </script>
</body>
</html>
