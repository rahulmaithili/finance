<?php
// Bank Accounts management module for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

$active_page = 'accounts';
$error = '';
$success = '';

// Edit Mode detection
$edit_mode = false;
$edit_account = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ? LIMIT 1");
    $stmt->execute([$edit_id]);
    $edit_account = $stmt->fetch();
    if ($edit_account) {
        $edit_mode = true;
    }
}

// Delete Account Action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    try {
        // Check for dependencies
        $income_check = $pdo->prepare("SELECT COUNT(*) FROM income WHERE account_id = ?");
        $income_check->execute([$delete_id]);
        
        $expense_check = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE account_id = ?");
        $expense_check->execute([$delete_id]);
        
        $transfer_check = $pdo->prepare("SELECT COUNT(*) FROM transfers WHERE from_account = ? OR to_account = ?");
        $transfer_check->execute([$delete_id, $delete_id]);
        
        $has_deps = ($income_check->fetchColumn() > 0) || ($expense_check->fetchColumn() > 0) || ($transfer_check->fetchColumn() > 0);
        
        if ($has_deps) {
            set_flash_message('error', 'Cannot delete this account. It has associated transactions.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?");
            $stmt->execute([$delete_id]);
            log_activity("Deleted Bank Account: ID {$delete_id}");
            set_flash_message('success', 'Bank Account deleted successfully.');
        }
    } catch (PDOException $e) {
        set_flash_message('error', 'A database constraint error occurred.');
    }
    header("Location: accounts.php");
    exit;
}

// Form Submission handling (Add or Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $account_name = clean($_POST['account_name'] ?? '');
        $account_number = clean($_POST['account_number'] ?? '');
        $bank_name = clean($_POST['bank_name'] ?? '');
        $ifsc_code = clean($_POST['ifsc_code'] ?? '');
        $branch_name = clean($_POST['branch_name'] ?? '');
        $opening_balance = (float)($_POST['opening_balance'] ?? 0.00);
        $currency = clean($_POST['currency'] ?? 'INR');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($account_name) || empty($account_number) || empty($bank_name)) {
            $error = 'All fields (Account Name, Number, Bank Name) are required.';
        } else {
            try {
                if (isset($_POST['action']) && $_POST['action'] === 'update') {
                    // Update Mode
                    $acc_id = (int)$_POST['account_id'];
                    // Get current balance and opening balance to adjust
                    $stmt = $pdo->prepare("SELECT opening_balance, current_balance FROM bank_accounts WHERE id = ?");
                    $stmt->execute([$acc_id]);
                    $old_data = $stmt->fetch();
                    
                    if ($old_data) {
                        $bal_diff = $opening_balance - (float)$old_data['opening_balance'];
                        $new_current_balance = (float)$old_data['current_balance'] + $bal_diff;
                        
                        $stmt = $pdo->prepare("UPDATE bank_accounts SET account_name = ?, account_number = ?, bank_name = ?, ifsc_code = ?, branch_name = ?, opening_balance = ?, current_balance = ?, status = ?, currency = ? WHERE id = ?");
                        $stmt->execute([$account_name, $account_number, $bank_name, $ifsc_code, $branch_name, $opening_balance, $new_current_balance, $status, $currency, $acc_id]);
                        
                        log_activity("Updated Bank Account: {$account_name} (ID: {$acc_id})");
                        set_flash_message('success', 'Bank Account updated successfully.');
                        header("Location: accounts.php");
                        exit;
                    }
                } else {
                    // Create Mode
                    $stmt = $pdo->prepare("INSERT INTO bank_accounts (account_name, account_number, bank_name, ifsc_code, branch_name, opening_balance, current_balance, status, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$account_name, $account_number, $bank_name, $ifsc_code, $branch_name, $opening_balance, $opening_balance, $status, $currency]);
                    
                    log_activity("Created Bank Account: {$account_name}");
                    set_flash_message('success', 'New Bank Account created successfully.');
                    header("Location: accounts.php");
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all bank accounts
$accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Accounts - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
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
                <div class="page-title">Manage Bank Accounts</div>
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
                    <div class="alert alert-danger">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span><?= clean($error) ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="module-grid">
                    <!-- Left: Accounts Tiles List -->
                    <div>
                        <div class="header-title-section" style="margin-bottom: 20px;">
                            <h2>All Accounts Overview</h2>
                            <p>Overview of balances and details for your registered cash holdings and banks.</p>
                        </div>
                        
                        <div class="accounts-grid">
                            <?php if (count($accounts) === 0): ?>
                                <div class="table-card" style="grid-column: 1 / -1; text-align: center; color: var(--text-secondary); padding: 50px 0;">
                                    <i class="fa-solid fa-building-columns" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                                    <h3>No accounts registered yet.</h3>
                                    <p style="font-size: 0.85rem;">Use the form to register your first bank account or cash fund.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($accounts as $acc): ?>
                                    <div class="bank-card <?= ($acc['status'] === 'inactive') ? 'card-inactive' : '' ?>">
                                        <div class="bank-header">
                                            <div>
                                                <div class="bank-name-label"><?= clean($acc['bank_name']) ?></div>
                                                <div style="font-weight: 700; font-size: 1.15rem;"><?= clean($acc['account_name']) ?></div>
                                            </div>
                                            <div class="bank-actions">
                                                <a href="?edit=<?= $acc['id'] ?>" class="bank-btn" title="Edit Account">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a href="?delete=<?= $acc['id'] ?>" class="bank-btn" title="Delete Account" onclick="return confirm('Are you sure you want to delete this bank account? All transactions will be verified.');">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <div class="bank-number">
                                            <?php 
                                            // Format card number to look like XXXX XXXX XXXX 1234
                                            $clean_num = str_replace(' ', '', $acc['account_number']);
                                            $len = strlen($clean_num);
                                            if ($len > 4) {
                                                echo '•••• •••• •••• ' . substr($clean_num, -4);
                                            } else {
                                                echo $clean_num;
                                            }
                                            ?>
                                        </div>
                                        
                                        <div style="display:flex; justify-content:space-between; font-size:0.75rem; opacity:0.8; margin-top:-5px; margin-bottom:5px;">
                                            <span><i class="fa-solid fa-code-branch" style="margin-right:2px;"></i> <?= !empty($acc['branch_name']) ? clean($acc['branch_name']) : 'N/A' ?></span>
                                            <span><i class="fa-solid fa-money-bill-1" style="margin-right:2px;"></i> <?= strtoupper(clean($acc['currency'])) ?></span>
                                        </div>
                                        
                                        <div class="bank-balance-wrapper">
                                            <div>
                                                <div class="bank-balance-label">Current Balance</div>
                                                <div class="bank-balance"><?= format_currency($acc['current_balance'], $acc['currency']) ?></div>
                                            </div>
                                            <div>
                                                <span class="badge <?= ($acc['status'] === 'active') ? 'badge-success' : 'badge-danger' ?>" style="font-size: 0.65rem;">
                                                    <?= clean($acc['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Right: Add / Edit Account Form -->
                    <div>
                        <div class="form-card">
                            <div class="form-card-title">
                                <span><?= $edit_mode ? 'Edit Account' : 'Add Bank Account' ?></span>
                                <?php if ($edit_mode): ?>
                                    <a href="accounts.php" class="btn-icon" style="border: none;" title="Cancel Edit">
                                        <i class="fa-solid fa-xmark"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <form action="accounts.php" method="POST" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <?php if ($edit_mode): ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="account_id" value="<?= $edit_account['id'] ?>">
                                <?php endif; ?>
                                
                                <div class="form-group">
                                    <label class="form-label" for="account_name">Account Nickname</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="account_name" name="account_name" class="form-control" placeholder="e.g. Primary Savings / Petty Cash" required value="<?= $edit_mode ? clean($edit_account['account_name']) : '' ?>">
                                        <i class="fa-solid fa-wallet" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="bank_name">Bank / Institution Name</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="bank_name" name="bank_name" class="form-control" placeholder="e.g. HDFC Bank / Cash" required value="<?= $edit_mode ? clean($edit_account['bank_name']) : '' ?>">
                                        <i class="fa-solid fa-university" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="account_number">Account Number</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="account_number" name="account_number" class="form-control" placeholder="e.g. 501002342345" required value="<?= $edit_mode ? clean($edit_account['account_number']) : '' ?>">
                                        <i class="fa-solid fa-hashtag" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="ifsc_code">IFSC Code (Optional)</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="ifsc_code" name="ifsc_code" class="form-control" placeholder="e.g. SBIN0001234" value="<?= $edit_mode ? clean($edit_account['ifsc_code']) : '' ?>">
                                        <i class="fa-solid fa-barcode" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="branch_name">Branch Name (Optional)</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="branch_name" name="branch_name" class="form-control" placeholder="e.g. Connaught Place Branch" value="<?= $edit_mode ? clean($edit_account['branch_name']) : '' ?>">
                                        <i class="fa-solid fa-code-branch" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="currency">Currency</label>
                                    <select id="currency" name="currency" class="form-control" style="padding-left: 15px;" required>
                                        <option value="INR" <?= ($edit_mode && $edit_account['currency'] === 'INR') ? 'selected' : '' ?>>INR (₹)</option>
                                        <option value="USD" <?= ($edit_mode && $edit_account['currency'] === 'USD') ? 'selected' : '' ?>>USD ($)</option>
                                        <option value="EUR" <?= ($edit_mode && $edit_account['currency'] === 'EUR') ? 'selected' : '' ?>>EUR (€)</option>
                                        <option value="GBP" <?= ($edit_mode && $edit_account['currency'] === 'GBP') ? 'selected' : '' ?>>GBP (£)</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="opening_balance">Opening Balance</label>
                                    <div class="input-icon-wrapper">
                                        <input type="number" id="opening_balance" name="opening_balance" class="form-control" placeholder="0.00" step="0.01" min="0" required value="<?= $edit_mode ? clean($edit_account['opening_balance']) : '0.00' ?>">
                                        <i class="fa-solid fa-coins" style="left: 14px;"></i>
                                    </div>
                                    <?php if ($edit_mode): ?>
                                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:5px;">
                                            Updating opening balance will automatically adjust current balance.
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="status">Account Status</label>
                                    <select id="status" name="status" class="form-control" style="padding-left: 15px;" required>
                                        <option value="active" <?= ($edit_mode && $edit_account['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= ($edit_mode && $edit_account['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                
                                <button type="submit" class="btn-primary" style="margin-top: 10px;">
                                    <i class="fa-solid <?= $edit_mode ? 'fa-floppy-disk' : 'fa-plus' ?>"></i>
                                    <span><?= $edit_mode ? 'Save Changes' : 'Create Account' ?></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Mobile FAB Button (Add Account) -->
    <button class="mobile-fab fab-account" id="mobileAccountBtn" title="Add Bank Account" aria-label="Add Account">
        <i class="fa-solid fa-plus"></i>
    </button>

    <!-- ✅ Mobile Bottom Sheet Overlay -->
    <div class="bottom-sheet-overlay" id="sheetOverlay"></div>

    <!-- ✅ Mobile Bottom Sheet Panel -->
    <div class="bottom-sheet" id="bottomSheet">
        <div class="sheet-stripe-account"></div>
        <div class="bottom-sheet-handle"></div>
        <div class="bottom-sheet-header">
            <h3><i class="fa-solid fa-building-columns" style="color:#6366f1; margin-right:8px;"></i><?= $edit_mode ? 'Edit Account' : 'Add Bank Account' ?></h3>
            <button class="bottom-sheet-close" id="sheetCloseBtn"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="bottom-sheet-body">
            <form action="accounts.php" method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="account_id" value="<?= $edit_account['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Account Nickname</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="account_name" class="form-control" placeholder="e.g. Primary Savings / Petty Cash" required value="<?= $edit_mode ? clean($edit_account['account_name']) : '' ?>">
                        <i class="fa-solid fa-wallet" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Bank / Institution Name</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="bank_name" class="form-control" placeholder="e.g. HDFC Bank / Cash" required value="<?= $edit_mode ? clean($edit_account['bank_name']) : '' ?>">
                        <i class="fa-solid fa-university" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Account Number</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="account_number" class="form-control" placeholder="e.g. 501002342345" required value="<?= $edit_mode ? clean($edit_account['account_number']) : '' ?>">
                        <i class="fa-solid fa-hashtag" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">IFSC Code (Optional)</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="ifsc_code" class="form-control" placeholder="e.g. SBIN0001234" value="<?= $edit_mode ? clean($edit_account['ifsc_code']) : '' ?>">
                        <i class="fa-solid fa-barcode" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Branch Name (Optional)</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="branch_name" class="form-control" placeholder="e.g. Connaught Place Branch" value="<?= $edit_mode ? clean($edit_account['branch_name']) : '' ?>">
                        <i class="fa-solid fa-code-branch" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-control" style="padding-left:14px;" required>
                        <option value="INR" <?= ($edit_mode && $edit_account['currency'] === 'INR') ? 'selected' : '' ?>>INR (₹)</option>
                        <option value="USD" <?= ($edit_mode && $edit_account['currency'] === 'USD') ? 'selected' : '' ?>>USD ($)</option>
                        <option value="EUR" <?= ($edit_mode && $edit_account['currency'] === 'EUR') ? 'selected' : '' ?>>EUR (€)</option>
                        <option value="GBP" <?= ($edit_mode && $edit_account['currency'] === 'GBP') ? 'selected' : '' ?>>GBP (£)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Opening Balance</label>
                    <div class="input-icon-wrapper">
                        <input type="number" name="opening_balance" class="form-control" placeholder="0.00" step="0.01" min="0" required value="<?= $edit_mode ? clean($edit_account['opening_balance']) : '0.00' ?>">
                        <i class="fa-solid fa-coins" style="left:14px;"></i>
                    </div>
                    <?php if ($edit_mode): ?>
                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:4px;">Updating balance will auto-adjust current balance.</div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Account Status</label>
                    <select name="status" class="form-control" style="padding-left:14px;" required>
                        <option value="active" <?= ($edit_mode && $edit_account['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($edit_mode && $edit_account['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary" style="margin-top:10px;">
                    <i class="fa-solid <?= $edit_mode ? 'fa-floppy-disk' : 'fa-plus' ?>"></i>
                    <?= $edit_mode ? 'Save Changes' : 'Create Account' ?>
                </button>
            </form>
        </div>
    </div>

    <script>
    // ===== Bottom Sheet Controls for Accounts =====
    const sheetOverlay    = document.getElementById('sheetOverlay');
    const bottomSheet     = document.getElementById('bottomSheet');
    const mobileAccountBtn= document.getElementById('mobileAccountBtn');
    const sheetCloseBtn   = document.getElementById('sheetCloseBtn');

    function openSheet() {
        sheetOverlay.classList.add('open');
        bottomSheet.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeSheet() {
        sheetOverlay.classList.remove('open');
        bottomSheet.classList.remove('open');
        document.body.style.overflow = '';
    }

    if (mobileAccountBtn) mobileAccountBtn.addEventListener('click', openSheet);
    if (sheetCloseBtn)    sheetCloseBtn.addEventListener('click', closeSheet);
    if (sheetOverlay)     sheetOverlay.addEventListener('click', closeSheet);

    <?php if ($edit_mode): ?>
    if (window.innerWidth <= 768) { openSheet(); }
    <?php endif; ?>
    </script>

</body>
</html>
