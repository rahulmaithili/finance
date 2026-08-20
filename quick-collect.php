<?php
// Quick Collect POS module for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

$active_page = 'quick_collect';
$error = '';
$success = '';

if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['setting_value'] : $default;
        } catch (PDOException $e) { return $default; }
    }
}

// Load UPI Settings
$upi_id = getSetting('upi_id', '');
$upi_name = getSetting('upi_name', '');
$static_qr = getSetting('static_qr', '');

// Fetch active bank accounts
$accounts = $pdo->query("SELECT id, account_name, bank_name, current_balance FROM bank_accounts WHERE status = 'active' ORDER BY account_name ASC")->fetchAll();

// Handle Delete/Revert collection Action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. Fetch collection log
        $stmt = $pdo->prepare("SELECT * FROM quick_collections WHERE id = ? FOR UPDATE");
        $stmt->execute([$delete_id]);
        $col = $stmt->fetch();
        
        if ($col) {
            $amount = (float)$col['amount'];
            $acc_id = (int)$col['account_id'];
            $income_id = $col['income_id'];
            
            // 2. Reverse balance: subtract amount from bank account
            $upd_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
            $upd_stmt->execute([$amount, $acc_id]);
            
            // 3. Delete income log
            if ($income_id) {
                $del_inc = $pdo->prepare("DELETE FROM income WHERE id = ?");
                $del_inc->execute([$income_id]);
            }
            
            // 4. Delete collection log
            $del_col = $pdo->prepare("DELETE FROM quick_collections WHERE id = ?");
            $del_col->execute([$delete_id]);
            
            log_activity("Reverted Quick Collection ID {$delete_id}: Subtracted " . format_currency($amount) . " from Acc ID {$acc_id}");
            $pdo->commit();
            set_flash_message('success', 'Collection entry reverted and account balance adjusted.');
        } else {
            $pdo->rollBack();
            set_flash_message('error', 'Collection entry not found.');
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash_message('error', 'Failed to revert collection: ' . $e->getMessage());
    }
    header("Location: quick-collect.php");
    exit;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $payer_name = clean($_POST['payer_name'] ?? '');
        $payer_phone = clean($_POST['payer_phone'] ?? '');
        $purpose = clean($_POST['purpose'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0.00);
        $account_id = (int)($_POST['account_id'] ?? 0);
        $payment_method = clean($_POST['payment_method'] ?? 'cash');
        $reference_no = clean($_POST['reference_no'] ?? '');
        $created_by = $_SESSION['user_id'];
        
        if (empty($payer_name) || empty($purpose) || $amount <= 0 || $account_id <= 0) {
            $error = 'Please fill out all required fields and ensure amount is positive.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // 1. Create entry in income table
                $desc = "Quick collection from " . $payer_name . ($payer_phone ? " (" . $payer_phone . ")" : "") . ". Purpose: " . $purpose;
                $inc_stmt = $pdo->prepare("INSERT INTO income (account_id, title, category, amount, payment_method, reference_no, description, income_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $inc_stmt->execute([
                    $account_id,
                    "Quick Collect: " . $purpose,
                    "Instant Biller",
                    $amount,
                    $payment_method,
                    $reference_no ?: "QC-" . time(),
                    $desc,
                    date('Y-m-d'),
                    $created_by
                ]);
                $income_id = $pdo->lastInsertId();
                
                // 2. Update bank account balance
                $upd_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $upd_stmt->execute([$amount, $account_id]);
                
                // 3. Save inside quick_collections
                $col_stmt = $pdo->prepare("INSERT INTO quick_collections (payer_name, payer_phone, purpose, amount, account_id, payment_method, rzp_payment_id, income_id, status, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $col_stmt->execute([
                    $payer_name,
                    $payer_phone ?: null,
                    $purpose,
                    $amount,
                    $account_id,
                    $payment_method,
                    $reference_no ?: null,
                    $income_id,
                    'paid',
                    $created_by
                ]);
                
                log_activity("Quick Collection Recorded: " . format_currency($amount) . " from {$payer_name} into Account ID {$account_id}");
                $pdo->commit();
                set_flash_message('success', 'Collection recorded successfully!');
                header("Location: quick-collect.php");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to save collection: ' . $e->getMessage();
            }
        }
    }
}

// Fetch past collections
try {
    $collections = $pdo->query("
        SELECT c.*, a.account_name, a.bank_name, u.full_name as recorder_name 
        FROM quick_collections c
        JOIN bank_accounts a ON c.account_id = a.id
        JOIN users u ON c.recorded_by = u.id
        ORDER BY c.created_at DESC LIMIT 100
    ")->fetchAll();
} catch (PDOException $e) {
    $collections = [];
}

// Currency Symbol helper
$currency_symbol = getSetting('currency_symbol', '₹');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Collect - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.dataTables.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .module-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .module-grid {
                grid-template-columns: 1fr;
            }
            /* Force collection card to sit on top of the list for mobile layouts */
            .collection-form-card {
                order: -1;
            }
        }
        /* Overlay alert z-index overwrite */
        .swal2-container {
            z-index: 99999 !important;
        }
        .qr-card {
            background: var(--bg-primary);
            border: 1px dashed var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-top: 15px;
            transition: all 0.3s ease;
        }
        .qr-placeholder {
            width: 180px;
            height: 180px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: #1e293b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin: 10px auto;
        }
        .qr-image {
            width: 180px;
            height: 180px;
            display: block;
            margin: 10px auto;
            border-radius: 8px;
            border: 4px solid white;
        }
    </style>
</head>
<body>

    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Panel Content -->
        <div class="main-content">
            <!-- Mobile Menu -->
            <?php include 'mobile-menu.php'; ?>
            
            <!-- Navbar -->
            <div class="navbar">
                <div class="page-title"><i class="fa-solid fa-qrcode" style="margin-right: 8px; color: #06b6d4;"></i>Quick Collect Manager</div>
                <div class="nav-actions">
                    <a href="?toggle_theme=1" class="nav-btn" title="Toggle Theme">
                        <i class="fa-solid <?= ($_SESSION['theme'] === 'light') ? 'fa-moon' : 'fa-sun' ?>"></i>
                    </a>
                </div>
            </div>
            
            <!-- Content Body -->
            <div class="content-body">
                <!-- Flash messages -->
                <?php display_flash_message(); ?>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="margin-bottom: 20px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span><?= clean($error) ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="module-grid">
                    <!-- Left: Collections Log Table -->
                    <div class="table-card">
                        <div class="header-title-section" style="margin-bottom: 20px;">
                            <h2>Collection Ledger</h2>
                            <p>History of instant payments collected.</p>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="custom-table" id="collectionsTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Payer</th>
                                        <th>Purpose</th>
                                        <th>Account</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Recorded By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($collections as $col): ?>
                                        <tr>
                                            <td><?= clean(date('d M Y, h:i A', strtotime($col['created_at']))) ?></td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--text-light);"><?= clean($col['payer_name']) ?></div>
                                                <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($col['payer_phone'] ?: '-') ?></span>
                                            </td>
                                            <td><span class="badge" style="background: rgba(6, 182, 212, 0.1); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.25);"><?= clean($col['purpose']) ?></span></td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--text-light);"><?= clean($col['account_name']) ?></div>
                                                <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($col['bank_name']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge" style="background: <?= ($col['payment_method'] === 'upi') ? 'rgba(99, 102, 241, 0.1)' : 'rgba(16, 185, 129, 0.1)' ?>; color: <?= ($col['payment_method'] === 'upi') ? '#6366f1' : '#10b981' ?>; border: 1px solid <?= ($col['payment_method'] === 'upi') ? 'rgba(99, 102, 241, 0.25)' : 'rgba(16, 185, 129, 0.25)' ?>;">
                                                    <?= strtoupper(clean($col['payment_method'])) ?>
                                                </span>
                                            </td>
                                            <td style="font-weight: 700; color: var(--success);"><?= format_currency($col['amount']) ?></td>
                                            <td><span style="font-size: 0.85rem; color: var(--text-secondary);"><?= clean($col['recorder_name']) ?></span></td>
                                            <td>
                                                <button class="action-btn delete-btn" onclick="confirmRevert(<?= $col['id'] ?>, <?= $col['amount'] ?>)" title="Revert Payment">
                                                    <i class="fa-solid fa-trash-can-arrow-up"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Right: Instant Biller Card -->
                    <div class="table-card collection-form-card">
                        <div class="header-title-section" style="margin-bottom: 20px;">
                            <h2>Instant Biller</h2>
                            <p>Generate one-off collections or invoices instantly.</p>
                        </div>
                        
                        <form method="POST" id="quickCollectForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            
                            <div class="form-group">
                                <label class="form-label">Deposit Account *</label>
                                <select name="account_id" id="accountId" class="form-control" required>
                                    <option value="">-- Choose Target Account --</option>
                                    <?php foreach ($accounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>"><?= clean($acc['account_name']) ?> (<?= clean($acc['bank_name']) ?>) - Balance: <?= format_currency($acc['current_balance']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Payer Name *</label>
                                <input type="text" name="payer_name" id="payerName" class="form-control" placeholder="e.g. Ramesh Kumar" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Payer Contact (Optional)</label>
                                <input type="text" name="payer_phone" id="payerPhone" class="form-control" placeholder="e.g. 9876543210">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Collection Purpose *</label>
                                <input type="text" name="purpose" id="purpose" class="form-control" placeholder="e.g. Consulting Fee / Rent" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Amount (<?= $currency_symbol ?>) *</label>
                                <input type="number" step="0.01" name="amount" id="amount" class="form-control" placeholder="0.00" oninput="updateDynamicQR()" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Payment Method *</label>
                                <div style="display: flex; gap: 20px; margin-top: 5px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--text-light); font-size: 0.9rem;">
                                        <input type="radio" name="payment_method" value="cash" checked onclick="togglePaymentMethod('cash')">
                                        <i class="fa-solid fa-money-bill-1-wave" style="color:#10b981;"></i> Cash
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--text-light); font-size: 0.9rem;">
                                        <input type="radio" name="payment_method" value="upi" onclick="togglePaymentMethod('upi')">
                                        <i class="fa-solid fa-qrcode" style="color:#6366f1;"></i> UPI / QR Code
                                    </label>
                                </div>
                            </div>

                            <div class="form-group" id="refNoGroup">
                                <label class="form-label" id="refLabel">Transaction Reference ID</label>
                                <input type="text" name="reference_no" id="refNo" class="form-control" placeholder="e.g. UPI Ref Number">
                            </div>

                            <!-- UPI Dynamic QR Container -->
                            <div class="qr-card" id="upiQrContainer" style="display: none;">
                                <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-light); margin-bottom: 5px;">Scan UPI QR Code to Pay</div>
                                <div id="qrStatusArea">
                                    <?php if (empty($upi_id)): ?>
                                        <?php if (!empty($static_qr)): ?>
                                            <img src="<?= clean($static_qr) ?>" class="qr-image" alt="Static UPI QR">
                                            <div style="font-size: 0.72rem; color: var(--text-secondary); margin-top: 5px;">Static Backup QR Code</div>
                                        <?php else: ?>
                                            <div class="alert alert-warning" style="font-size: 0.78rem; padding: 8px; margin: 5px 0;">
                                                <i class="fa-solid fa-circle-info"></i> Configure UPI VPA ID in Settings -> Payments to generate Dynamic QR.
                                            </div>
                                            <div class="qr-placeholder"><i class="fa-solid fa-qrcode" style="font-size:32px;margin-bottom:8px;"></i><br>UPI Not Setup</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="qr-placeholder" id="qrLoader">Enter amount above...</div>
                                        <img id="qrImageElement" class="qr-image" style="display: none;" alt="Scan to pay">
                                        <div style="font-size: 0.72rem; color: var(--text-secondary); margin-top: 5px;" id="qrMetaDesc">UPI ID: <?= clean($upi_id) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary" style="margin-top: 20px; width: 100%; justify-content: center;">
                                <i class="fa-solid fa-circle-check"></i> Record Collection
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const VPA_ID = '<?= clean($upi_id) ?>';
        const PAYEE_NAME = '<?= clean($upi_name) ?>';

        $(document).ready(function() {
            $('#collectionsTable').DataTable({
                responsive: true,
                order: [[0, 'desc']],
                pageLength: 10,
                language: {
                    searchPlaceholder: "Search ledger..."
                }
            });
        });

        function togglePaymentMethod(method) {
            const upiQrContainer = document.getElementById('upiQrContainer');
            const refLabel = document.getElementById('refLabel');
            const refNo = document.getElementById('refNo');

            if (method === 'upi') {
                upiQrContainer.style.display = 'block';
                refLabel.textContent = "UPI Txn Reference ID (UTR) *";
                refNo.required = true;
                refNo.placeholder = "Enter 12-digit UPI UTR number";
                updateDynamicQR();
            } else {
                upiQrContainer.style.display = 'none';
                refLabel.textContent = "Transaction Reference ID";
                refNo.required = false;
                refNo.placeholder = "e.g. Receipt Number";
            }
        }

        function updateDynamicQR() {
            if (typeof VPA_ID === 'undefined' || !VPA_ID) return;
            
            const amt = parseFloat(document.getElementById('amount').value) || 0;
            const purposeVal = document.getElementById('purpose').value.trim() || 'Payment';
            const payerVal = document.getElementById('payerName').value.trim() || 'Customer';
            
            const qrLoader = document.getElementById('qrLoader');
            const qrImageElement = document.getElementById('qrImageElement');

            if (amt <= 0) {
                if (qrLoader) {
                    qrLoader.style.display = 'inline-flex';
                    qrLoader.textContent = "Enter amount above...";
                }
                if (qrImageElement) qrImageElement.style.display = 'none';
                return;
            }

            if (qrLoader) {
                qrLoader.style.display = 'inline-flex';
                qrLoader.textContent = "Generating QR Code...";
            }
            if (qrImageElement) qrImageElement.style.display = 'none';

            // Construct UPI deep-link URI
            const encodedPayee = encodeURIComponent(PAYEE_NAME);
            const encodedPurpose = encodeURIComponent(purposeVal + " from " + payerVal);
            const upiString = `upi://pay?pa=${VPA_ID}&pn=${encodedPayee}&am=${amt.toFixed(2)}&tn=${encodedPurpose}&cu=INR`;
            
            const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(upiString)}`;

            // Pre-load image to avoid flicker
            const tempImg = new Image();
            tempImg.onload = function() {
                if (qrLoader) qrLoader.style.display = 'none';
                if (qrImageElement) {
                    qrImageElement.src = qrApiUrl;
                    qrImageElement.style.display = 'block';
                }
            };
            tempImg.onerror = function() {
                if (qrLoader) {
                    qrLoader.style.display = 'inline-flex';
                    qrLoader.textContent = "Failed to load QR code.";
                }
            };
            tempImg.src = qrApiUrl;
        }

        function confirmRevert(id, amount) {
            Swal.fire({
                title: 'Revert Collection?',
                text: `Reverting this collection will subtract ${amount.toFixed(2)} from the target bank account balance and delete the corresponding ledger entries. This action cannot be undone!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'var(--text-secondary)',
                confirmButtonText: 'Yes, revert payment',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `quick-collect.php?delete=${id}`;
                }
            });
        }
    </script>
</body>
</html>
