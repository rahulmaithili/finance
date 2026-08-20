<?php
// Expense entry module for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

$active_page = 'expense';
$error = '';
$success = '';

// Fetch active bank accounts for select list
$accounts = $pdo->query("SELECT id, account_name, bank_name, current_balance FROM bank_accounts WHERE status = 'active' ORDER BY account_name ASC")->fetchAll();

// Edit Mode detection
$edit_mode = false;
$edit_expense = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? LIMIT 1");
    $stmt->execute([$edit_id]);
    $edit_expense = $stmt->fetch();
    if ($edit_expense) {
        $edit_mode = true;
    }
}

// Delete Expense Action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. Get transaction info
        $stmt = $pdo->prepare("SELECT account_id, amount, title, attachment FROM expenses WHERE id = ? FOR UPDATE");
        $stmt->execute([$delete_id]);
        $expense_data = $stmt->fetch();
        
        if ($expense_data) {
            $account_id = $expense_data['account_id'];
            $amount = (float)$expense_data['amount'];
            $title = $expense_data['title'];
            $attachment = $expense_data['attachment'];
            
            // 2. Add back to account current_balance (refund)
            $update_acc = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $update_acc->execute([$amount, $account_id]);
            
            // 3. Delete expense record
            $delete_stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
            $delete_stmt->execute([$delete_id]);
            
            // Delete physical attachment if exists
            if ($attachment && file_exists('uploads/' . $attachment)) {
                @unlink('uploads/' . $attachment);
            }
            
            log_activity("Deleted Expense: '{$title}' - added back " . format_currency($amount) . " to account ID {$account_id}");
            $pdo->commit();
            set_flash_message('success', 'Expense entry deleted and account balance restored successfully.');
        } else {
            $pdo->rollBack();
            set_flash_message('error', 'Expense entry not found.');
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash_message('error', 'Failed to delete expense: ' . $e->getMessage());
    }
    header("Location: expense.php");
    exit;
}

// Form Submission handling (Add or Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $title = clean($_POST['title'] ?? '');
        $category = clean($_POST['category'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0.00);
        $account_id = (int)($_POST['account_id'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_no = clean($_POST['reference_no'] ?? '');
        $description = clean($_POST['description'] ?? '');
        $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
        $created_by = $_SESSION['user_id'];
        
        if (empty($title) || empty($category) || $amount <= 0 || $account_id <= 0 || empty($expense_date)) {
            $error = 'Please fill out all required fields and ensure amount is positive.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get the current balance of the account to check if it has sufficient funds
                $bal_stmt = $pdo->prepare("SELECT current_balance, account_name FROM bank_accounts WHERE id = ? FOR UPDATE");
                $bal_stmt->execute([$account_id]);
                $account = $bal_stmt->fetch();
                
                $current_balance = (float)($account['current_balance'] ?? 0);
                
                if (isset($_POST['action']) && $_POST['action'] === 'update') {
                    // UPDATE MODE
                    $exp_id = (int)$_POST['expense_id'];
                    
                    // Get old details
                    $old_stmt = $pdo->prepare("SELECT account_id, amount, attachment FROM expenses WHERE id = ? FOR UPDATE");
                    $old_stmt->execute([$exp_id]);
                    $old_expense = $old_stmt->fetch();
                    
                    if ($old_expense) {
                        // Restore old balance to old account
                        $restore_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                        $restore_stmt->execute([(float)$old_expense['amount'], (int)$old_expense['account_id']]);
                        
                        // Check if new account has sufficient balance (accounting for the restored balance if it's the same account)
                        $adjusted_balance = $current_balance;
                        if ($old_expense['account_id'] == $account_id) {
                            $adjusted_balance += (float)$old_expense['amount'];
                        }
                        
                        if ($adjusted_balance < $amount) {
                            // Show warning but still allow transaction
                            set_flash_message('warning', 'Notice: Account balance has gone below zero.');
                        }
                        
                        // Handle attachment upload
                        $attachment = handle_attachment_upload($old_expense['attachment']);
                        
                        // Update expense entry
                        $update_stmt = $pdo->prepare("
                            UPDATE expenses 
                            SET account_id = ?, title = ?, category = ?, amount = ?, payment_method = ?, reference_no = ?, description = ?, attachment = ?, expense_date = ? 
                            WHERE id = ?
                        ");
                        $update_stmt->execute([$account_id, $title, $category, $amount, $payment_method, $reference_no, $description, $attachment, $expense_date, $exp_id]);
                        
                        // Deduct from current balance of the target account
                        $deduct_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                        $deduct_stmt->execute([$amount, $account_id]);
                        
                        log_activity("Updated Expense Entry: '{$title}' (ID: {$exp_id}) - adjusted balances");
                        $pdo->commit();
                        set_flash_message('success', 'Expense entry updated successfully.');
                        header("Location: expense.php");
                        exit;
                    } else {
                        $pdo->rollBack();
                        $error = 'Original expense entry not found.';
                    }
                } else {
                    // CREATE MODE
                    if ($current_balance < $amount) {
                        set_flash_message('warning', 'Notice: Account balance has gone below zero.');
                    }
                    
                    // Handle attachment upload
                    $attachment = handle_attachment_upload();
                    
                    // 1. Insert expense entry
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO expenses (account_id, title, category, amount, payment_method, reference_no, description, attachment, expense_date, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert_stmt->execute([$account_id, $title, $category, $amount, $payment_method, $reference_no, $description, $attachment, $expense_date, $created_by]);
                    
                    // 2. Deduct from account current_balance
                    $update_acc = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                    $update_acc->execute([$amount, $account_id]);
                    
                    log_activity("Created Expense Entry: '{$title}' - deducted " . format_currency($amount) . " from account ID {$account_id}");
                    $pdo->commit();
                    set_flash_message('success', 'New Expense entry logged successfully.');
                    header("Location: expense.php");
                    exit;
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Database transaction failed: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all expenses with account and user details
$expenses = $pdo->query("
    SELECT e.*, a.account_name, a.bank_name, u.full_name as recorder_name 
    FROM expenses e
    JOIN bank_accounts a ON e.account_id = a.id
    JOIN users u ON e.created_by = u.id
    ORDER BY e.expense_date DESC, e.created_at DESC
")->fetchAll();

// Pre-defined Categories for Expenses
$categories = ['Office Rent', 'Utilities (Electricity/Water)', 'Internet / Subscriptions', 'Salaries / Wages', 'Tax / License Fees', 'Marketing / Ads', 'Inventory / Stock', 'Office Stationery', 'Travel / Fuel', 'Meals / Entertainment', 'Repairs / Maintenance', 'Other'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Entries - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.dataTables.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
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
                <div class="page-title">Expense Management</div>
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
                    <!-- Left: Expenses Table -->
                    <div class="table-card">
                        <div class="header-title-section" style="margin-bottom: 20px;">
                            <h2>All Expense Transactions</h2>
                            <p>Complete list of expenditures logged across all payment channels.</p>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="custom-table" id="expenseTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Account</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Recorded By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expenses as $exp): ?>
                                        <tr>
                                            <td><?= clean(date('d M Y', strtotime($exp['expense_date']))) ?></td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--text-light);"><?= clean($exp['title']) ?></div>
                                                <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($exp['reference_no']) ?></span>
                                            </td>
                                            <td>
                                                <?= get_category_badge($exp['category']) ?>
                                            </td>
                                            <td><?= clean($exp['account_name']) ?> <span style="font-size:0.75rem; color:var(--text-secondary);">(<?= clean($exp['bank_name']) ?>)</span></td>
                                            <td><span style="text-transform:uppercase; font-size:0.8rem;"><?= clean($exp['payment_method']) ?></span></td>
                                            <td style="font-weight: 700; color: var(--danger);"><?= format_currency($exp['amount']) ?></td>
                                            <td><?= clean($exp['recorder_name']) ?></td>
                                            <td class="actions-cell">
                                                <?php if (!empty($exp['attachment'])): ?>
                                                    <button type="button" class="btn-icon btn-view" title="Preview Attachment" onclick="previewAttachment('uploads/<?= $exp['attachment'] ?>', '<?= clean($exp['title']) ?>')">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="invoice.php?type=expense&id=<?= $exp['id'] ?>" class="btn-icon btn-view" title="Print Invoice" target="_blank">
                                                    <i class="fa-solid fa-print"></i>
                                                </a>
                                                <a href="?edit=<?= $exp['id'] ?>" class="btn-icon btn-edit" title="Edit">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a href="?delete=<?= $exp['id'] ?>" class="btn-icon btn-delete" title="Delete" onclick="return confirm('Are you sure you want to delete this expense? The amount will be refunded back to the bank account.');">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Right: Add / Edit Expense Form -->
                    <div class="form-card">
                        <div class="form-card-title">
                            <span><?= $edit_mode ? 'Edit Expense' : 'Log Expense' ?></span>
                            <?php if ($edit_mode): ?>
                                <a href="expense.php" class="btn-icon" style="border: none;" title="Cancel Edit">
                                    <i class="fa-solid fa-xmark"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (count($accounts) === 0): ?>
                            <div class="alert alert-warning" style="font-size: 0.85rem;">
                                <i class="fa-solid fa-exclamation-triangle"></i>
                                <span>Please register an <a href="accounts.php" style="text-decoration: underline; font-weight:600;">Active Bank Account</a> before logging expenses.</span>
                            </div>
                        <?php else: ?>
                            <form action="expense.php" method="POST" autocomplete="off" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <?php if ($edit_mode): ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="expense_id" value="<?= $edit_expense['id'] ?>">
                                <?php endif; ?>
                                
                                <div class="form-group">
                                    <label class="form-label" for="title">Title / Purpose</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="title" name="title" class="form-control" placeholder="e.g. Electricity Bill Payment" required value="<?= $edit_mode ? clean($edit_expense['title']) : '' ?>">
                                        <i class="fa-solid fa-file-invoice-dollar" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="category">Category</label>
                                    <select id="category" name="category" class="form-control" style="padding-left: 15px;" required>
                                        <option value="" disabled selected>Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat ?>" <?= ($edit_mode && $edit_expense['category'] === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="amount">Amount (₹)</label>
                                    <div class="input-icon-wrapper">
                                        <input type="number" id="amount" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required value="<?= $edit_mode ? clean($edit_expense['amount']) : '' ?>">
                                        <i class="fa-solid fa-indian-rupee-sign" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="account_id">Source Account</label>
                                    <select id="account_id" name="account_id" class="form-control" style="padding-left: 15px;" required>
                                        <option value="" disabled selected>Select Bank Account</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= ($edit_mode && $edit_expense['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                                                <?= clean($acc['account_name']) ?> (Bal: <?= format_currency($acc['current_balance']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="payment_method">Payment Method</label>
                                    <select id="payment_method" name="payment_method" class="form-control" style="padding-left: 15px;" required>
                                        <option value="cash" <?= ($edit_mode && $edit_expense['payment_method'] === 'cash') ? 'selected' : '' ?>>Cash</option>
                                        <option value="bank" <?= ($edit_mode && $edit_expense['payment_method'] === 'bank') ? 'selected' : '' ?>>Bank Transfer</option>
                                        <option value="upi" <?= ($edit_mode && $edit_expense['payment_method'] === 'upi') ? 'selected' : '' ?>>UPI / QR Code</option>
                                        <option value="card" <?= ($edit_mode && $edit_expense['payment_method'] === 'card') ? 'selected' : '' ?>>Card</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="reference_no">Reference / Tx ID (Optional)</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="reference_no" name="reference_no" class="form-control" placeholder="e.g. Transaction ID, Check Num" value="<?= $edit_mode ? clean($edit_expense['reference_no']) : '' ?>">
                                        <i class="fa-solid fa-signature" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="expense_date">Expense Date</label>
                                    <div class="input-icon-wrapper">
                                        <input type="date" id="expense_date" name="expense_date" class="form-control" required value="<?= $edit_mode ? clean($edit_expense['expense_date']) : date('Y-m-d') ?>">
                                        <i class="fa-solid fa-calendar-days" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                     <label class="form-label" for="description">Description / Remarks</label>
                                     <textarea id="description" name="description" class="form-control" style="height: 80px; padding-left: 15px; resize: none;" placeholder="Provide brief remarks..."><?= $edit_mode ? clean($edit_expense['description']) : '' ?></textarea>
                                 </div>
                                 
                                 <div class="form-group">
                                     <label class="form-label" for="attachment">Upload Receipt (PDF or Image)</label>
                                     <input type="file" id="attachment" name="attachment" class="form-control" accept="image/*,application/pdf" style="padding-left:15px;">
                                     
                                     <input type="hidden" name="camera_photo" id="cameraPhotoInput">
                                     <button type="button" class="btn-secondary" id="startCameraBtn" style="margin-top: 8px; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px; font-weight:600;">
                                         <i class="fa-solid fa-camera"></i> Live Photo Capture
                                     </button>
                                     <div id="cameraPreviewContainer" style="display:none; margin-top:10px; text-align:center;">
                                         <div style="font-size:0.75rem; color:var(--success); font-weight:600; margin-bottom:5px;"><i class="fa-solid fa-circle-check"></i> Photo Captured!</div>
                                         <img id="capturedPreviewImg" src="" style="max-width:100%; max-height:120px; border-radius:6px; border:1px solid var(--border-color);">
                                     </div>
                                 </div>
                                 
                                 <?php if ($edit_mode && !empty($edit_expense['attachment'])): ?>
                                     <div class="form-group" style="background:var(--bg-primary); padding:12px; border-radius:8px; border:1px solid var(--border-color); margin-bottom: 20px;">
                                         <div style="font-size:0.8rem; font-weight:600; color:var(--text-light); margin-bottom:5px;">Current Attachment:</div>
                                         <div style="display:flex; justify-content:space-between; align-items:center;">
                                             <a href="javascript:void(0);" onclick="previewAttachment('uploads/<?= $edit_expense['attachment'] ?>', '<?= clean($edit_expense['title']) ?>')" style="color:var(--primary); font-size:0.8rem; font-weight:600; text-decoration:underline;">
                                                 <i class="fa-solid fa-paperclip"></i> View File
                                             </a>
                                             <label style="font-size:0.8rem; color:var(--danger); display:flex; align-items:center; gap:5px; cursor:pointer;">
                                                 <input type="checkbox" name="remove_attachment" value="1"> Remove
                                             </label>
                                         </div>
                                     </div>
                                 <?php endif; ?>
                                 
                                 <button type="submit" class="btn-primary" style="margin-top: 10px;">
                                    <i class="fa-solid <?= $edit_mode ? 'fa-floppy-disk' : 'fa-plus' ?>"></i>
                                    <span><?= $edit_mode ? 'Save Changes' : 'Save Expense' ?></span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Mobile FAB Button (Expense) -->
    <button class="mobile-fab fab-expense" id="mobileExpenseBtn" title="Log Expense" aria-label="Add Expense">
        <i class="fa-solid fa-plus"></i>
    </button>

    <!-- ✅ Mobile Bottom Sheet Overlay -->
    <div class="bottom-sheet-overlay" id="sheetOverlay"></div>

    <!-- ✅ Mobile Bottom Sheet Panel -->
    <div class="bottom-sheet" id="bottomSheet">
        <div class="sheet-stripe-expense"></div>
        <div class="bottom-sheet-handle"></div>
        <div class="bottom-sheet-header">
            <h3><i class="fa-solid fa-circle-minus" style="color:#e11d48; margin-right:8px;"></i><?= $edit_mode ? 'Edit Expense' : 'Log Expense' ?></h3>
            <button class="bottom-sheet-close" id="sheetCloseBtn"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="bottom-sheet-body">
            <?php if (count($accounts) === 0): ?>
                <div class="alert alert-warning" style="font-size:0.85rem;">
                    <i class="fa-solid fa-exclamation-triangle"></i>
                    <span>Please register an <a href="accounts.php" style="text-decoration:underline;font-weight:600;">Active Bank Account</a> first.</span>
                </div>
            <?php else: ?>
            <form action="expense.php" method="POST" autocomplete="off" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="expense_id" value="<?= $edit_expense['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Title / Purpose</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="title" class="form-control" placeholder="e.g. Electricity Bill" required value="<?= $edit_mode ? clean($edit_expense['title']) : '' ?>">
                        <i class="fa-solid fa-file-invoice-dollar" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control" style="padding-left:14px;" required>
                        <option value="" disabled selected>Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>" <?= ($edit_mode && $edit_expense['category'] === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Amount (₹)</label>
                    <div class="input-icon-wrapper">
                        <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required value="<?= $edit_mode ? clean($edit_expense['amount']) : '' ?>">
                        <i class="fa-solid fa-indian-rupee-sign" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Source Account</label>
                    <select name="account_id" class="form-control" style="padding-left:14px;" required>
                        <option value="" disabled selected>Select Bank Account</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= ($edit_mode && $edit_expense['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                                <?= clean($acc['account_name']) ?> (<?= format_currency($acc['current_balance']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control" style="padding-left:14px;" required>
                        <option value="cash" <?= ($edit_mode && $edit_expense['payment_method'] === 'cash') ? 'selected' : '' ?>>Cash</option>
                        <option value="bank" <?= ($edit_mode && $edit_expense['payment_method'] === 'bank') ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="upi" <?= ($edit_mode && $edit_expense['payment_method'] === 'upi') ? 'selected' : '' ?>>UPI / QR Code</option>
                        <option value="card" <?= ($edit_mode && $edit_expense['payment_method'] === 'card') ? 'selected' : '' ?>>Card</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Expense Date</label>
                    <div class="input-icon-wrapper">
                        <input type="date" name="expense_date" class="form-control" required value="<?= $edit_mode ? clean($edit_expense['expense_date']) : date('Y-m-d') ?>">
                        <i class="fa-solid fa-calendar-days" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Reference / Tx ID (Optional)</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="reference_no" class="form-control" placeholder="e.g. Transaction ID" value="<?= $edit_mode ? clean($edit_expense['reference_no']) : '' ?>">
                        <i class="fa-solid fa-signature" style="left:14px;"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Description / Remarks</label>
                    <textarea name="description" class="form-control" style="height:70px; padding-left:14px; resize:none;" placeholder="Brief remarks..."><?= $edit_mode ? clean($edit_expense['description']) : '' ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Receipt (PDF / Image)</label>
                    <input type="file" name="attachment" class="form-control" accept="image/*,application/pdf" style="padding-left:14px; height:auto; padding-top:10px; padding-bottom:10px;">
                    <input type="hidden" name="camera_photo" id="sheetCameraPhotoInput">
                    <button type="button" id="sheetCameraBtn" class="btn-secondary" style="margin-top:6px;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;font-weight:600;">
                        <i class="fa-solid fa-camera"></i> Live Photo Capture
                    </button>
                    <div id="sheetCameraPreview" style="display:none;margin-top:8px;text-align:center;">
                        <div style="font-size:0.75rem;color:var(--success);font-weight:600;"><i class="fa-solid fa-circle-check"></i> Photo Captured!</div>
                        <img id="sheetCapturedImg" src="" style="max-width:100%;max-height:100px;border-radius:6px;border:1px solid var(--border-color);margin-top:4px;">
                    </div>
                </div>

                <?php if ($edit_mode && !empty($edit_expense['attachment'])): ?>
                <div class="form-group" style="background:var(--bg-primary);padding:10px;border-radius:8px;border:1px solid var(--border-color);">
                    <div style="font-size:0.78rem;font-weight:600;color:var(--text-light);margin-bottom:4px;">Current Attachment:</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <a href="javascript:void(0);" onclick="previewAttachment('uploads/<?= $edit_expense['attachment'] ?>', '<?= clean($edit_expense['title']) ?>')" style="color:var(--primary);font-size:0.8rem;font-weight:600;text-decoration:underline;">
                            <i class="fa-solid fa-paperclip"></i> View File
                        </a>
                        <label style="font-size:0.8rem;color:var(--danger);display:flex;align-items:center;gap:5px;cursor:pointer;">
                            <input type="checkbox" name="remove_attachment" value="1"> Remove
                        </label>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary" style="margin-top:10px; background:linear-gradient(135deg,#e11d48,#be123c);">
                    <i class="fa-solid <?= $edit_mode ? 'fa-floppy-disk' : 'fa-minus' ?>"></i>
                    <?= $edit_mode ? 'Save Changes' : 'Save Expense' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Camera Capture Modal -->
    <div id="cameraModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.85); z-index:10000; justify-content:center; align-items:center; flex-direction:column; padding:20px;">
        <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:20px; width:100%; max-width:500px; text-align:center; position:relative;">
            <h3 style="color:var(--text-light); margin-bottom:15px; font-weight:600;"><i class="fa-solid fa-camera"></i> Capture Receipt</h3>
            <video id="cameraVideo" autoplay playsinline style="width:100%; border-radius:8px; background:#000; margin-bottom:15px;"></video>
            <canvas id="cameraCanvas" style="display:none;"></canvas>
            <div style="display:flex; gap:10px; justify-content:center;">
                <button type="button" class="btn-primary" id="captureBtn" style="width:auto; padding:10px 20px;"><i class="fa-solid fa-circle-dot"></i> Capture</button>
                <button type="button" class="btn-secondary" id="closeCameraBtn" style="width:auto; padding:10px 20px;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Attachment Preview Modal -->
    <div id="previewModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.8); z-index:9999; justify-content:center; align-items:center; padding:20px;">
        <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:20px; width:100%; max-width:700px; max-height:90vh; display:flex; flex-direction:column; position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:15px;">
                <h3 style="color:var(--text-light);" id="previewTitle">Attachment Preview</h3>
                <button type="button" class="btn-icon" style="border:none;" onclick="closePreviewModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="previewContent" style="flex-grow:1; display:flex; justify-content:center; align-items:center; overflow:auto;">
                <!-- Preview image or frame iframe -->
            </div>
        </div>
    </div>

    <script>
    let cameraStream = null;

    $(document).ready(function() {
        $('#expenseTable').DataTable({
            order: [[0, 'desc']],
            responsive: {
                details: { type: 'column', target: 'tr' }
            },
            columnDefs: [
                { responsivePriority: 1, targets: 0 },   // Date - always show
                { responsivePriority: 2, targets: 5 },   // Amount - always show
                { responsivePriority: 3, targets: 1 },   // Title
                { responsivePriority: 4, targets: 7 },   // Actions
                { responsivePriority: 5, targets: 2 },   // Category - collapse 1st
                { responsivePriority: 6, targets: 3 },   // Account - collapse 2nd
                { responsivePriority: 7, targets: 4 },   // Method - collapse 3rd
                { responsivePriority: 8, targets: 6 }    // Recorded By - collapse last
            ]
        });

        // Live Photo Capture triggers
        const startCameraBtn = document.getElementById("startCameraBtn");
        const cameraModal = document.getElementById("cameraModal");
        const cameraVideo = document.getElementById("cameraVideo");
        const cameraCanvas = document.getElementById("cameraCanvas");
        const captureBtn = document.getElementById("captureBtn");
        const closeCameraBtn = document.getElementById("closeCameraBtn");
        const cameraPhotoInput = document.getElementById("cameraPhotoInput");
        const cameraPreviewContainer = document.getElementById("cameraPreviewContainer");
        const capturedPreviewImg = document.getElementById("capturedPreviewImg");

        startCameraBtn.addEventListener("click", async function() {
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: "environment" }, // Rear camera on mobile
                    audio: false 
                });
                cameraVideo.srcObject = cameraStream;
                cameraModal.style.display = "flex";
            } catch (err) {
                alert("Camera access denied or not supported by your browser: " + err.message);
            }
        });

        captureBtn.addEventListener("click", function() {
            if (cameraStream) {
                cameraCanvas.width = cameraVideo.videoWidth;
                cameraCanvas.height = cameraVideo.videoHeight;
                const ctx = cameraCanvas.getContext("2d");
                ctx.drawImage(cameraVideo, 0, 0, cameraCanvas.width, cameraCanvas.height);
                
                const dataUrl = cameraCanvas.toDataURL("image/jpeg", 0.9);
                cameraPhotoInput.value = dataUrl;
                
                capturedPreviewImg.src = dataUrl;
                cameraPreviewContainer.style.display = "block";
                
                stopCamera();
            }
        });

        closeCameraBtn.addEventListener("click", stopCamera);

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            cameraVideo.srcObject = null;
            cameraModal.style.display = "none";
        }
    });

    // Preview Overlay scripts
    function previewAttachment(fileUrl, title) {
        const content = document.getElementById("previewContent");
        document.getElementById("previewTitle").innerText = "Receipt Preview: " + title;
        
        const ext = fileUrl.split('.').pop().toLowerCase();
        let previewHtml = '';
        
        if (ext === 'pdf') {
            previewHtml = `<iframe src="${fileUrl}" style="width:100%; height:400px; border:none; border-radius:8px; margin-bottom:15px;"></iframe>`;
        } else {
            previewHtml = `<img src="${fileUrl}" style="max-width:100%; max-height:400px; border-radius:8px; object-fit:contain; margin-bottom:15px;" />`;
        }
        
        previewHtml += `<div style="text-align:center; width:100%;">
            <a href="${fileUrl}" target="_blank" class="btn-primary" style="display:inline-flex; width:auto; padding:10px 20px; align-items:center; gap:8px; text-decoration:none; font-size:0.9rem; font-weight:600; border-radius:6px;">
                <i class="fa-solid fa-up-right-from-square"></i> Open in New Tab / Download
            </a>
        </div>`;
        
        content.innerHTML = previewHtml;
        document.getElementById("previewModal").style.display = "flex";
    }

    function closePreviewModal() {
        document.getElementById("previewModal").style.display = "none";
        document.getElementById("previewContent").innerHTML = "";
    }

    // ===== Bottom Sheet Controls =====
    const sheetOverlay    = document.getElementById('sheetOverlay');
    const bottomSheet     = document.getElementById('bottomSheet');
    const mobileExpenseBtn= document.getElementById('mobileExpenseBtn');
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

    if (mobileExpenseBtn) mobileExpenseBtn.addEventListener('click', openSheet);
    if (sheetCloseBtn)    sheetCloseBtn.addEventListener('click', closeSheet);
    if (sheetOverlay)     sheetOverlay.addEventListener('click', closeSheet);

    <?php if ($edit_mode): ?>
    if (window.innerWidth <= 768) { openSheet(); }
    <?php endif; ?>

    // Sheet camera button for expense
    const sheetCameraBtn = document.getElementById('sheetCameraBtn');
    if (sheetCameraBtn) {
        sheetCameraBtn.addEventListener('click', async function() {
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
                document.getElementById('cameraVideo').srcObject = cameraStream;
                document.getElementById('cameraModal').style.display = 'flex';
                document.getElementById('captureBtn').onclick = function() {
                    if (cameraStream) {
                        const canvas = document.getElementById('cameraCanvas');
                        const video  = document.getElementById('cameraVideo');
                        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
                        canvas.getContext('2d').drawImage(video, 0, 0);
                        const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
                        document.getElementById('sheetCameraPhotoInput').value = dataUrl;
                        document.getElementById('sheetCapturedImg').src = dataUrl;
                        document.getElementById('sheetCameraPreview').style.display = 'block';
                        cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null;
                        document.getElementById('cameraVideo').srcObject = null;
                        document.getElementById('cameraModal').style.display = 'none';
                    }
                };
            } catch(err) { alert('Camera error: ' + err.message); }
        });
    }
    </script>
</body>
</html>
