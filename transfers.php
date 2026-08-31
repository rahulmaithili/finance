<?php
// Bank Transfers management module for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

// Role guards: Viewer cannot access this page. Staff cannot delete transfers.
if ($_SESSION['user_role'] === 'viewer') {
    set_flash_message('error', 'Viewer accounts are restricted to reports and read-only actions.');
    header("Location: reports.php");
    exit;
}

if ($_SESSION['user_role'] === 'staff') {
    if (isset($_GET['delete']) || isset($_GET['edit'])) {
        set_flash_message('error', 'Access denied. Entry staff members cannot edit or delete transactions.');
        header("Location: transfers.php");
        exit;
    }
}

$active_page = 'transfers';
$error = '';
$success = '';

// Fetch active bank accounts
$accounts = $pdo->query("SELECT id, account_name, bank_name, current_balance FROM bank_accounts WHERE status = 'active' ORDER BY account_name ASC")->fetchAll();

// Edit Mode detection
$edit_mode = false;
$edit_transfer = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? LIMIT 1");
    $stmt->execute([$edit_id]);
    $edit_transfer = $stmt->fetch();
    if ($edit_transfer) {
        $edit_mode = true;
    }
}

// Delete Transfer Action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. Get transfer details
        $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? FOR UPDATE");
        $stmt->execute([$delete_id]);
        $tf = $stmt->fetch();
        
        if ($tf) {
            $from_acc = (int)$tf['from_account'];
            $to_acc = (int)$tf['to_account'];
            $amount = (float)$tf['amount'];
            
            // 2. Reverse the balances: add to source, subtract from destination
            $stmt_add = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $stmt_add->execute([$amount, $from_acc]);
            
            $stmt_sub = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
            $stmt_sub->execute([$amount, $to_acc]);
            
            // 3. Delete transfer entry
            $delete_stmt = $pdo->prepare("DELETE FROM transfers WHERE id = ?");
            $delete_stmt->execute([$delete_id]);
            
            log_activity("Deleted Transfer ID {$delete_id}: Reversed " . format_currency($amount) . " from Acc {$to_acc} back to Acc {$from_acc}");
            $pdo->commit();
            set_flash_message('success', 'Transfer deleted and account balances reversed successfully.');
        } else {
            $pdo->rollBack();
            set_flash_message('error', 'Transfer entry not found.');
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash_message('error', 'Failed to delete transfer: ' . $e->getMessage());
    }
    header("Location: transfers.php");
    exit;
}

// Form Submission handling (Add or Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $from_account = (int)($_POST['from_account'] ?? 0);
        $to_account = (int)($_POST['to_account'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0.00);
        $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
        $remarks = clean($_POST['remarks'] ?? '');
        $created_by = $_SESSION['user_id'];
        
        if ($from_account <= 0 || $to_account <= 0 || $amount <= 0 || empty($transfer_date)) {
            $error = 'Please fill out all required fields and ensure amount is positive.';
        } elseif ($from_account === $to_account) {
            $error = 'Source account and destination account cannot be the same.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get source account balance
                $stmt = $pdo->prepare("SELECT current_balance, account_name FROM bank_accounts WHERE id = ? FOR UPDATE");
                $stmt->execute([$from_account]);
                $src_account = $stmt->fetch();
                $src_balance = (float)($src_account['current_balance'] ?? 0);
                
                if (isset($_POST['action']) && $_POST['action'] === 'update') {
                    if ($_SESSION['user_role'] === 'staff') {
                        throw new Exception("Access denied. Entry staff members cannot modify transactions.");
                    }
                    // UPDATE MODE
                    $tf_id = (int)$_POST['transfer_id'];
                    
                    // Fetch original transfer details
                    $old_stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? FOR UPDATE");
                    $old_stmt->execute([$tf_id]);
                    $old_tf = $old_stmt->fetch();
                    
                    if ($old_tf) {
                        // 1. First reverse the original balances
                        $rev_from_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                        $rev_from_stmt->execute([(float)$old_tf['amount'], (int)$old_tf['from_account']]);
                        
                        $rev_to_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                        $rev_to_stmt->execute([(float)$old_tf['amount'], (int)$old_tf['to_account']]);
                        
                        // Check if adjusted source balance is sufficient for new transfer
                        // Fetch the source balance again as it was modified
                        $stmt_adj = $pdo->prepare("SELECT current_balance FROM bank_accounts WHERE id = ? FOR UPDATE");
                        $stmt_adj->execute([$from_account]);
                        $adj_balance = (float)$stmt_adj->fetchColumn();
                        
                        if ($adj_balance < $amount) {
                            $pdo->rollBack();
                            $error = "Insufficient funds in source account. Adjusted balance is " . format_currency($adj_balance);
                        } else {
                            // 2. Perform the update
                            $update_stmt = $pdo->prepare("
                                UPDATE transfers 
                                SET from_account = ?, to_account = ?, amount = ?, transfer_date = ?, remarks = ? 
                                WHERE id = ?
                            ");
                            $update_stmt->execute([$from_account, $to_account, $amount, $transfer_date, $remarks, $tf_id]);
                            
                            // 3. Apply new balances
                            $deduct_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                            $deduct_stmt->execute([$amount, $from_account]);
                            
                            $add_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                            $add_stmt->execute([$amount, $to_account]);
                            
                            log_activity("Updated Transfer ID {$tf_id}: Transfered " . format_currency($amount) . " from Acc {$from_account} to Acc {$to_account}");
                            $pdo->commit();
                            set_flash_message('success', 'Transfer updated successfully.');
                            header("Location: transfers.php");
                            exit;
                        }
                    } else {
                        $pdo->rollBack();
                        $error = 'Original transfer entry not found.';
                    }
                } else {
                    // CREATE MODE
                    if ($src_balance < $amount) {
                        $pdo->rollBack();
                        $error = "Insufficient balance in source account. Available: " . format_currency($src_balance);
                    } else {
                        // 1. Insert transfer
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO transfers (from_account, to_account, amount, transfer_date, remarks, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $insert_stmt->execute([$from_account, $to_account, $amount, $transfer_date, $remarks, $created_by]);
                        
                        // 2. Deduct from source account
                        $deduct_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                        $deduct_stmt->execute([$amount, $from_account]);
                        
                        // 3. Add to target account
                        $add_stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                        $add_stmt->execute([$amount, $to_account]);
                        
                        log_activity("Logged Transfer: " . format_currency($amount) . " from Acc {$from_account} to Acc {$to_account}");
                        $pdo->commit();
                        set_flash_message('success', 'Funds transferred successfully.');
                        header("Location: transfers.php");
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Database transaction failed: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all transfers with accounts details and recorder name
$transfers = $pdo->query("
    SELECT t.*, 
           a_from.account_name as from_account_name, a_from.bank_name as from_bank_name,
           a_to.account_name as to_account_name, a_to.bank_name as to_bank_name,
           u.full_name as recorder_name
    FROM transfers t
    JOIN bank_accounts a_from ON t.from_account = a_from.id
    JOIN bank_accounts a_to ON t.to_account = a_to.id
    JOIN users u ON t.created_by = u.id
    ORDER BY t.transfer_date DESC, t.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Transfers - IEMS ERP</title>
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
                <div class="page-title">Inter-Account Transfers</div>
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
                    <!-- Left: Transfers Table -->
                    <div class="table-card">
                        <div class="header-title-section" style="margin-bottom: 20px;">
                            <h2>Fund Transfer Ledger</h2>
                            <p>History of internal fund routing and balances balancing.</p>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="custom-table" id="transfersTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Source Account</th>
                                        <th></th>
                                        <th>Destination Account</th>
                                        <th>Amount</th>
                                        <th>Remarks</th>
                                        <th>Recorded By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transfers as $tf): ?>
                                        <tr>
                                            <td><?= clean(date('d M Y', strtotime($tf['transfer_date']))) ?></td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--text-light);"><?= clean($tf['from_account_name']) ?></div>
                                                <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($tf['from_bank_name']) ?></span>
                                            </td>
                                            <td style="text-align: center;"><i class="fa-solid fa-circle-arrow-right" style="color: var(--info); font-size: 1.1rem;"></i></td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--text-light);"><?= clean($tf['to_account_name']) ?></div>
                                                <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($tf['to_bank_name']) ?></span>
                                            </td>
                                            <td style="font-weight: 700; color: var(--info);"><?= format_currency($tf['amount']) ?></td>
                                            <td><?= clean($tf['remarks']) ?></td>
                                            <td><?= clean($tf['recorder_name']) ?></td>
                                            <td class="actions-cell">
                                                <div style="display:flex; gap:6px; align-items:center;">
                                                    <button class="btn-icon btn-view" title="View Details"
                                                        onclick="viewTransfer(<?= $tf['id'] ?>, '<?= addslashes(date('d M Y', strtotime($tf['transfer_date']))) ?>', '<?= addslashes($tf['from_account_name']) ?>', '<?= addslashes($tf['to_account_name']) ?>', '<?= addslashes($tf['amount']) ?>', '<?= addslashes($tf['remarks'] ?? '') ?>', '<?= addslashes($tf['recorder_name'] ?? 'System') ?>')">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                    <?php if ($_SESSION['user_role'] !== 'staff'): ?>
                                                    <a href="?edit=<?= $tf['id'] ?>" class="btn-icon btn-edit" title="Edit">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </a>
                                                    <button class="btn-icon btn-delete" title="Delete"
                                                        onclick="confirmDelete(<?= $tf['id'] ?>)">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Right: Add / Edit Transfer Form -->
                    <div class="form-card">
                        <div class="form-card-title">
                            <span><?= $edit_mode ? 'Edit Transfer' : 'Transfer Funds' ?></span>
                            <?php if ($edit_mode): ?>
                                <a href="transfers.php" class="btn-icon" style="border: none;" title="Cancel Edit">
                                    <i class="fa-solid fa-xmark"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (count($accounts) < 2): ?>
                            <div class="alert alert-warning" style="font-size: 0.85rem;">
                                <i class="fa-solid fa-exclamation-triangle"></i>
                                <span>You must have at least <a href="accounts.php" style="text-decoration: underline; font-weight:600;">two active bank accounts</a> to make internal transfers.</span>
                            </div>
                        <?php else: ?>
                            <form action="transfers.php" method="POST" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <?php if ($edit_mode): ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="transfer_id" value="<?= $edit_transfer['id'] ?>">
                                <?php endif; ?>
                                
                                <div class="form-group">
                                    <label class="form-label" for="from_account">From Account (Source)</label>
                                    <select id="from_account" name="from_account" class="form-control" style="padding-left: 15px;" required>
                                        <option value="" disabled selected>Select Source Account</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= ($edit_mode && $edit_transfer['from_account'] == $acc['id']) ? 'selected' : '' ?>>
                                                <?= clean($acc['account_name']) ?> (Bal: <?= format_currency($acc['current_balance']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="to_account">To Account (Destination)</label>
                                    <select id="to_account" name="to_account" class="form-control" style="padding-left: 15px;" required>
                                        <option value="" disabled selected>Select Destination Account</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= ($edit_mode && $edit_transfer['to_account'] == $acc['id']) ? 'selected' : '' ?>>
                                                <?= clean($acc['account_name']) ?> (Bal: <?= format_currency($acc['current_balance']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="amount">Amount to Transfer (₹)</label>
                                    <div class="input-icon-wrapper">
                                        <input type="number" id="amount" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required value="<?= $edit_mode ? clean($edit_transfer['amount']) : '' ?>">
                                        <i class="fa-solid fa-indian-rupee-sign" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="transfer_date">Transfer Date</label>
                                    <div class="input-icon-wrapper">
                                        <input type="date" id="transfer_date" name="transfer_date" class="form-control" required value="<?= $edit_mode ? clean($edit_transfer['transfer_date']) : date('Y-m-d') ?>">
                                        <i class="fa-solid fa-calendar-days" style="left: 14px;"></i>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="remarks">Remarks / Description</label>
                                    <textarea id="remarks" name="remarks" class="form-control" style="height: 100px; padding-left: 15px; resize: none;" placeholder="e.g. Loan repayment, liquidity adjustment..."><?= $edit_mode ? clean($edit_transfer['remarks']) : '' ?></textarea>
                                </div>
                                
                                <button type="submit" class="btn-primary" style="margin-top: 10px;">
                                    <i class="fa-solid fa-right-left"></i>
                                    <span><?= $edit_mode ? 'Update Transfer' : 'Execute Transfer' ?></span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#transfersTable').DataTable({
            order: [[0, 'desc']],
            responsive: true
        });
    });
    </script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- View Transfer Modal -->
    <div id="viewModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.82); align-items:center; justify-content:center; padding:16px;">
        <div style="background:#1a1d2e; border-radius:20px; max-width:480px; width:100%; max-height:90vh; overflow:hidden; position:relative; box-shadow:0 30px 80px rgba(0,0,0,0.7); border:1px solid rgba(255,255,255,0.08); display:flex; flex-direction:column;">
            <div style="background:linear-gradient(135deg,#3730a3,#6366f1); padding:20px 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; background:rgba(255,255,255,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                        <i class="fa-solid fa-right-left" style="color:#fff; font-size:1.1rem;"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; color:#fff; font-size:1.05rem; font-weight:700;">Transfer Details</h3>
                        <p style="margin:0; color:rgba(255,255,255,0.65); font-size:0.75rem;">Fund movement record</p>
                    </div>
                </div>
                <button onclick="closeViewModal()" style="width:34px; height:34px; background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.2); color:#fff; border-radius:8px; cursor:pointer; font-size:1rem; display:flex; align-items:center; justify-content:center;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div id="viewModalBody" style="padding:20px; display:flex; flex-direction:column; gap:10px; overflow-y:auto;"></div>
        </div>
    </div>

    <script>
    function row(icon, label, value, color, accent) {
        return `<div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:rgba(255,255,255,0.04); border-radius:12px; border:1px solid rgba(255,255,255,0.07); gap:12px;">
            <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                <div style="width:32px; height:32px; background:${accent || 'rgba(99,102,241,0.15)'}; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i class="fa-solid fa-${icon}" style="font-size:0.85rem; color:${accent ? '#fff' : '#6366f1'};"></i>
                </div>
                <span style="color:rgba(255,255,255,0.55); font-size:0.8rem; font-weight:500;">${label}</span>
            </div>
            <span style="font-weight:700; color:${color || '#fff'}; font-size:0.92rem; text-align:right;">${value}</span>
        </div>`;
    }

    function viewTransfer(id, date, fromAcc, toAcc, amount, remarks, recorder) {
        const fmt = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(amount);
        document.getElementById('viewModalBody').innerHTML =
            row('indian-rupee', 'Amount', fmt, '#6366f1', 'rgba(99,102,241,0.3)') +
            row('calendar-days', 'Transfer Date', date) +
            row('arrow-right-from-bracket', 'From Account (Debit)', fromAcc, '#f87171', 'rgba(239,68,68,0.25)') +
            row('arrow-right-to-bracket', 'To Account (Credit)', toAcc, '#34d399', 'rgba(16,185,129,0.25)') +
            (remarks ? row('note-sticky', 'Remarks', remarks) : '') +
            row('user', 'Recorded By', recorder) +
            `<div style="padding:10px 16px; background:rgba(255,255,255,0.03); border-radius:10px; border:1px solid rgba(255,255,255,0.06); text-align:center;">
                <span style="color:rgba(255,255,255,0.35); font-size:0.7rem; font-family:monospace;">ID: ${id}</span>
            </div>`;

        const modal = document.getElementById('viewModal');
        modal.style.display = 'flex';
        modal.addEventListener('click', function(e) { if (e.target === modal) closeViewModal(); }, { once: true });
    }

    function closeViewModal() {
        document.getElementById('viewModal').style.display = 'none';
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'Delete Transfer?',
            html: 'This transfer record will be <strong>permanently deleted</strong> and account balances will be <strong>reversed</strong> back to their original values.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#6b7280',
            confirmButtonText: '<i class="fa-solid fa-trash"></i> Yes, Delete & Reverse',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (result.isConfirmed) {
                window.location.href = '?delete=' + id;
            }
        });
    }
    </script>
</body>
</html>
