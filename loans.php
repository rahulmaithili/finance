<?php
// Borrowed Loans Management Module for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

$active_page = 'loans';
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

$currency_symbol = getSetting('currency_symbol', '₹');

// Fetch active bank accounts
$accounts = $pdo->query("SELECT id, account_name, bank_name, current_balance FROM bank_accounts WHERE status = 'active' ORDER BY account_name ASC")->fetchAll();

// Handle Delete Loan
if (isset($_GET['delete_loan'])) {
    $delete_id = (int)$_GET['delete_loan'];
    try {
        $pdo->beginTransaction();
        
        // Check if payments exist
        $pmts = $pdo->prepare("SELECT COUNT(*) FROM loan_payments WHERE loan_id = ?");
        $pmts->execute([$delete_id]);
        if ($pmts->fetchColumn() > 0) {
            throw new Exception("Cannot delete loan. Please delete all payment logs associated with this loan first.");
        }
        
        $stmt = $pdo->prepare("DELETE FROM loans WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        log_activity("Deleted Borrowed Loan ID {$delete_id}");
        $pdo->commit();
        set_flash_message('success', 'Loan deleted successfully.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash_message('error', $e->getMessage());
    }
    header("Location: loans.php");
    exit;
}

// Handle Delete Payment
if (isset($_GET['delete_payment'])) {
    $payment_id = (int)$_GET['delete_payment'];
    try {
        $pdo->beginTransaction();
        
        // Get payment details
        $stmt = $pdo->prepare("SELECT * FROM loan_payments WHERE id = ? FOR UPDATE");
        $stmt->execute([$payment_id]);
        $pmt = $stmt->fetch();
        
        if ($pmt) {
            $loan_id = (int)$pmt['loan_id'];
            $amount = (float)$pmt['amount'];
            $acc_id = (int)$pmt['account_id'];
            
            // 1. Delete payment entry
            $del_stmt = $pdo->prepare("DELETE FROM loan_payments WHERE id = ?");
            $del_stmt->execute([$payment_id]);
            
            // 2. Adjust bank account balance (Since we repaid the loan, deleting repayment reverses the debit, i.e., adds balance back)
            $upd_acc = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $upd_acc->execute([$amount, $acc_id]);
            
            // 3. Update loan stats
            $upd_loan = $pdo->prepare("UPDATE loans SET total_paid = total_paid - ?, emi_paid = GREATEST(0, emi_paid - 1), status = 'active' WHERE id = ?");
            $upd_loan->execute([$amount, $loan_id]);
            
            log_activity("Deleted Loan Payment ID {$payment_id}: Reversed " . format_currency($amount) . " to Acc ID {$acc_id}");
            $pdo->commit();
            set_flash_message('success', 'Payment log deleted and account balance adjusted.');
        } else {
            $pdo->rollBack();
            set_flash_message('error', 'Payment log not found.');
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash_message('error', 'Failed to delete payment: ' . $e->getMessage());
    }
    header("Location: loans.php");
    exit;
}

// Handle Form Submission: Add/Edit Loan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_loan') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $lender_name = clean($_POST['lender_name'] ?? '');
        $loan_type = clean($_POST['loan_type'] ?? 'Other');
        $principal = (float)($_POST['principal'] ?? 0.00);
        $interest_rate = (float)($_POST['interest_rate'] ?? 0.00);
        $tenure_months = (int)($_POST['tenure_months'] ?? 0);
        $start_date = $_POST['start_date'] ?? '';
        $emi_day = (int)($_POST['emi_day'] ?? 5);
        $notes = clean($_POST['notes'] ?? '');
        $repayment_account_id = (int)($_POST['repayment_account_id'] ?? 0);
        $created_by = $_SESSION['user_id'];
        
        // Calculate monthly EMI
        $monthly_rate = ($interest_rate / 12) / 100;
        if ($monthly_rate > 0) {
            $emi_amount = ($principal * $monthly_rate * pow(1 + $monthly_rate, $tenure_months)) / (pow(1 + $monthly_rate, $tenure_months) - 1);
        } else {
            $emi_amount = $principal / $tenure_months;
        }

        if (empty($lender_name) || $principal <= 0 || $interest_rate <= 0 || $tenure_months <= 0 || empty($start_date) || $repayment_account_id <= 0) {
            $error = 'Please fill out all required fields.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO loans (lender_name, principal, interest_rate, tenure_months, emi_amount, start_date, emi_day, repayment_account_id, attachment, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $lender_name,
                    $principal,
                    $interest_rate,
                    $tenure_months,
                    $emi_amount,
                    $start_date,
                    $emi_day,
                    $repayment_account_id,
                    $notes ?: null,
                    $created_by
                ]);
                log_activity("Added Borrowed Loan from {$lender_name}: Principal " . format_currency($principal));
                set_flash_message('success', 'Loan added successfully.');
                header("Location: loans.php");
                exit;
            } catch (PDOException $e) {
                $error = 'Failed to save loan: ' . $e->getMessage();
            }
        }
    }
}

// Handle Form Submission: Record EMI Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_payment') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0.00);
        $account_id = (int)($_POST['account_id'] ?? 0);
        $payment_method = clean($_POST['payment_method'] ?? 'cash');
        $note = clean($_POST['note'] ?? '');
        $recorded_by = $_SESSION['user_id'];
        
        if ($loan_id <= 0 || empty($payment_date) || $amount <= 0 || $account_id <= 0) {
            $error = 'All fields are required and amount must be positive.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get loan info
                $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = ? FOR UPDATE");
                $stmt->execute([$loan_id]);
                $loan = $stmt->fetch();
                
                if (!$loan) {
                    throw new Exception("Loan record not found.");
                }

                // Check account balance
                $stmt_acc = $pdo->prepare("SELECT current_balance FROM bank_accounts WHERE id = ? FOR UPDATE");
                $stmt_acc->execute([$account_id]);
                $bal = (float)$stmt_acc->fetchColumn();
                
                if ($bal < $amount) {
                    throw new Exception("Insufficient account balance. Available: " . format_currency($bal));
                }

                // 1. Insert payment entry
                $stmt_pmt = $pdo->prepare("INSERT INTO loan_payments (loan_id, amount, payment_date, payment_method, note, account_id, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_pmt->execute([$loan_id, $amount, $payment_date, $payment_method, $note, $account_id, $recorded_by]);
                
                // 2. Deduct from bank account balance (debit log as expense)
                $upd_acc = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $upd_acc->execute([$amount, $account_id]);

                // Create expense record
                $exp_stmt = $pdo->prepare("INSERT INTO expenses (account_id, title, category, amount, payment_method, reference_no, description, expense_date, created_by) VALUES (?, ?, 'Loan EMI', ?, ?, ?, ?, ?, ?)");
                $exp_stmt->execute([
                    $account_id,
                    "Loan Payment: " . $loan['lender_name'],
                    $amount,
                    $payment_method,
                    "LPMT-" . time(),
                    "EMI Payment log. Note: " . $note,
                    $payment_date,
                    $recorded_by
                ]);

                // 3. Update loan total paid and check closure status
                $new_total_paid = (float)$loan['total_paid'] + $amount;
                $new_emi_paid = (int)$loan['emi_paid'] + 1;
                
                // Foreclosure / full settlement detection
                $total_payable = (float)$loan['emi_amount'] * (int)$loan['tenure_months'];
                $status = ($new_total_paid >= $total_payable || stripos($note, 'foreclosure') !== false || stripos($note, 'full prepayment') !== false) ? 'closed' : 'active';
                
                if ($status === 'closed') {
                    $new_emi_paid = (int)$loan['tenure_months'];
                }

                $upd_loan = $pdo->prepare("UPDATE loans SET total_paid = ?, emi_paid = ?, status = ? WHERE id = ?");
                $upd_loan->execute([$new_total_paid, $new_emi_paid, $status, $loan_id]);
                
                log_activity("Recorded EMI Payment of " . format_currency($amount) . " for Loan ID {$loan_id}");
                $pdo->commit();
                set_flash_message('success', 'EMI repayment recorded successfully.');
                header("Location: loans.php");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// Fetch stats and lists
$loans = $pdo->query("
    SELECT l.*, a.account_name, a.bank_name 
    FROM loans l
    JOIN bank_accounts a ON l.repayment_account_id = a.id
    ORDER BY l.created_at DESC
")->fetchAll();

$kpi = [
    'total_loans' => 0.00,
    'total_paid' => 0.00,
    'outstanding' => 0.00,
    'monthly_emi' => 0.00,
    'loan_count' => count($loans),
    'active_count' => 0
];

foreach ($loans as $l) {
    $total_payable = (float)$l['emi_amount'] * (int)$l['tenure_months'];
    $kpi['total_loans'] += (float)$l['principal'];
    $kpi['total_paid'] += (float)$l['total_paid'];
    
    if ($l['status'] === 'active') {
        $kpi['active_count']++;
        $kpi['outstanding'] += max(0, $total_payable - (float)$l['total_paid']);
        $kpi['monthly_emi'] += (float)$l['emi_amount'];
    }
}

// Fetch payments log
$payments = $pdo->query("
    SELECT p.*, l.lender_name, a.account_name, u.full_name as recorder_name 
    FROM loan_payments p
    JOIN loans l ON p.loan_id = l.id
    JOIN bank_accounts a ON p.account_id = a.id
    JOIN users u ON p.recorded_by = u.id
    ORDER BY p.payment_date DESC LIMIT 100
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrowed Loans Tracker - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.dataTables.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .loan-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        @media (max-width: 900px) {
            .loan-stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .loan-stats-grid { grid-template-columns: 1fr 1fr; }
        }
        .loan-stat-card {
            border-radius: 18px; padding: 22px 20px;
            display: flex; flex-direction: column; gap: 10px;
            position: relative; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .loan-stat-card.blue { background: linear-gradient(135deg, #1e3a8a, #3b82f6); }
        .loan-stat-card.green { background: linear-gradient(135deg, #064e3b, #10b981); }
        .loan-stat-card.red { background: linear-gradient(135deg, #7f1d1d, #ef4444); }
        .loan-stat-card.amber { background: linear-gradient(135deg, #78350f, #f59e0b); }
        .loan-stat-icon { font-size: 1.5rem; opacity: 0.9; }
        .loan-stat-label { font-size: 0.72rem; color: rgba(255,255,255,0.75); text-transform: uppercase; letter-spacing: 0.07em; font-weight: 600; }
        .loan-stat-value { font-size: 1.55rem; font-weight: 900; color: #ffffff; letter-spacing: -0.02em; }
        .loan-stat-sub { font-size: 0.73rem; color: rgba(255,255,255,0.65); }

        .loans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .loan-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px; padding: 22px;
            display: flex; flex-direction: column; gap: 14px;
        }
        .loan-card-header { display: flex; justify-content: space-between; align-items: flex-start; }
        .loan-type-badge { font-size: 0.7rem; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; }
        .loan-progress-bar-bg { background: var(--bg-primary); border-radius: 6px; height: 10px; overflow: hidden; }
        .loan-progress-bar-fill { height: 100%; border-radius: 6px; background: var(--primary); }
        .loan-detail-row { display: flex; justify-content: space-between; font-size: 0.82rem; padding: 5px 0; border-bottom: 1px solid var(--border-color); }
        .loan-detail-row:last-child { border-bottom: none; }
        .loan-detail-label { color: var(--text-secondary); display: flex; align-items: center; gap: 7px; }
        .loan-detail-value { font-weight: 600; color: var(--text-light); }
        
        .emi-result-box {
            background: rgba(99,102,241,0.08);
            border: 1px solid rgba(99,102,241,0.25); border-radius: 14px;
            padding: 18px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; text-align: center;
        }
        .emi-result-item label { display: block; font-size: 0.72rem; color: var(--text-secondary); margin-bottom: 6px; }
        .emi-result-item span { font-weight: 800; font-size: 1rem; color: var(--text-light); }
        
        @media (max-width: 768px) {
            .loans-grid {
                grid-template-columns: 1fr !important;
            }
            .emi-result-box {
                grid-template-columns: 1fr !important;
                gap: 15px;
            }
            .modal-grid-responsive {
                grid-template-columns: 1fr !important;
            }
        }
        .swal2-container { z-index: 99999 !important; }
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
                <div class="page-title"><i class="fa-solid fa-hand-holding-dollar" style="margin-right: 8px; color: #f59e0b;"></i>Borrowed Loans Manager</div>
                <div class="nav-actions">
                    <button class="btn-primary" onclick="openAddLoanModal()"><i class="fa-solid fa-plus"></i> Add Loan</button>
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

                <!-- KPI stats dashboard -->
                <div class="loan-stats-grid">
                    <div class="loan-stat-card blue">
                        <div class="loan-stat-icon"><i class="fa-solid fa-landmark"></i></div>
                        <div class="loan-stat-label">Total Loans Taken</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['total_loans']) ?></div>
                        <div class="loan-stat-sub"><?= $kpi['loan_count'] ?> loans recorded</div>
                    </div>
                    <div class="loan-stat-card green">
                        <div class="loan-stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="loan-stat-label">Total Repaid</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['total_paid']) ?></div>
                        <div class="loan-stat-sub"><?= $kpi['total_loans'] > 0 ? round(($kpi['total_paid'] / ($kpi['total_loans'] * 1.15)) * 100, 1) : 0 ?>% repaid</div>
                    </div>
                    <div class="loan-stat-card red">
                        <div class="loan-stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div class="loan-stat-label">Outstanding Payable</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['outstanding']) ?></div>
                        <div class="loan-stat-sub"><?= $kpi['active_count'] ?> active loans</div>
                    </div>
                    <div class="loan-stat-card amber">
                        <div class="loan-stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                        <div class="loan-stat-label">Monthly EMI Burden</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['monthly_emi']) ?></div>
                        <div class="loan-stat-sub">combined active burden</div>
                    </div>
                </div>

                <div class="header-title-section" style="margin-bottom: 20px;">
                    <h2>Active Loans</h2>
                    <p>Lenders and payment progress schedules.</p>
                </div>

                <div class="loans-grid">
                    <?php if (empty($loans)): ?>
                        <div class="table-card" style="grid-column: 1/-1; padding: 40px; text-align: center;">
                            <i class="fa-solid fa-circle-info" style="font-size: 2.5rem; color: var(--text-secondary); margin-bottom: 12px;"></i>
                            <h3>No Loans Recorded</h3>
                            <p style="font-size: 0.88rem; color: var(--text-secondary);">Click Add Loan above to track borrowed loans.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($loans as $l): 
                            $total_payable = (float)$l['emi_amount'] * (int)$l['tenure_months'];
                            $paid_pct = min(100, round(($l['total_paid'] / $total_payable) * 100));
                        ?>
                            <div class="loan-card" style="opacity: <?= $l['status'] === 'closed' ? '0.7' : '1' ?>;">
                                <div class="loan-card-header">
                                    <div>
                                        <h3 style="margin:0; font-size:1.1rem; color:white; font-weight:700;"><?= clean($l['lender_name']) ?></h3>
                                        <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($l['start_date']) ?></span>
                                    </div>
                                    <span class="loan-type-badge" style="background: <?= $l['status'] === 'closed' ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)' ?>; color: <?= $l['status'] === 'closed' ? '#10b981' : '#f59e0b' ?>; border: 1px solid <?= $l['status'] === 'closed' ? 'rgba(16,185,129,0.3)' : 'rgba(245,158,11,0.3)' ?>;">
                                        <?= strtoupper($l['status']) ?>
                                    </span>
                                </div>

                                <div>
                                    <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-secondary); margin-bottom:4px;">
                                        <span>Repayment Progress</span>
                                        <span><?= $paid_pct ?>%</span>
                                    </div>
                                    <div class="loan-progress-bar-bg">
                                        <div class="loan-progress-bar-fill" style="width: <?= $paid_pct ?>%; background: <?= $l['status'] === 'closed' ? '#10b981' : 'var(--primary)' ?>;"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-wallet"></i> Principal Amount</span>
                                        <span class="loan-detail-value"><?= format_currency($l['principal']) ?></span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-percent"></i> Annual Interest Rate</span>
                                        <span class="loan-detail-value"><?= clean($l['interest_rate']) ?>%</span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-calendar"></i> Tenure Periods</span>
                                        <span class="loan-detail-value"><?= clean($l['tenure_months']) ?> months</span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-sack-dollar"></i> Monthly EMI</span>
                                        <span class="loan-detail-value" style="color: #6366f1;"><?= format_currency($l['emi_amount']) ?></span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-money-bill-transfer"></i> Total Paid</span>
                                        <span class="loan-detail-value" style="color: var(--success);"><?= format_currency($l['total_paid']) ?> (<?= $l['emi_paid'] ?> EMIs)</span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-building-columns"></i> Autodebit Source</span>
                                        <span class="loan-detail-value" style="font-size:0.75rem;"><?= clean($l['account_name']) ?></span>
                                    </div>
                                </div>

                                <div style="display:flex; gap:10px; margin-top:5px;">
                                    <?php if ($l['status'] === 'active'): ?>
                                        <button class="btn-primary" style="flex:1; justify-content:center; padding:8px 12px; font-size:0.82rem;" onclick="openPaymentModal(<?= $l['id'] ?>, '<?= clean($l['lender_name']) ?>', <?= $l['emi_amount'] ?>, <?= $total_payable - $l['total_paid'] ?>)">
                                            <i class="fa-solid fa-receipt"></i> Mark Paid
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn-secondary" style="border: 1px solid rgba(239,68,68,0.2); color:#ef4444; background:transparent; padding:8px 12px; font-size:0.82rem;" onclick="confirmDeleteLoan(<?= $l['id'] ?>, '<?= clean($l['lender_name']) ?>')">
                                        <i class="fa-solid fa-trash-can"></i> Delete
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="table-card" style="margin-top: 20px;">
                    <div class="header-title-section" style="margin-bottom: 20px;">
                        <h2>Repayment Ledger</h2>
                        <p>EMI payments cleared and logged.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="custom-table" id="paymentsTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Loan Lenders</th>
                                    <th>Method</th>
                                    <th>Repaid From</th>
                                    <th>Notes/Remark</th>
                                    <th>Amount</th>
                                    <th>Recorded By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $pmt): ?>
                                    <tr>
                                        <td><?= clean(date('d M Y', strtotime($pmt['payment_date']))) ?></td>
                                        <td style="font-weight:600; color:white;"><?= clean($pmt['lender_name']) ?></td>
                                        <td><span class="badge" style="background: rgba(16,185,129,0.1); color:#10b981; border: 1px solid rgba(16,185,129,0.2);"><?= strtoupper(clean($pmt['payment_method'])) ?></span></td>
                                        <td><?= clean($pmt['account_name']) ?></td>
                                        <td><span style="font-size:0.82rem; color:var(--text-secondary);"><?= clean($pmt['note'] ?: '-') ?></span></td>
                                        <td style="font-weight:700; color:var(--danger);"><?= format_currency($pmt['amount']) ?></td>
                                        <td><span style="font-size:0.82rem; color:var(--text-secondary);"><?= clean($pmt['recorder_name']) ?></span></td>
                                        <td>
                                            <button class="action-btn delete-btn" onclick="confirmDeletePayment(<?= $pmt['id'] ?>, <?= $pmt['amount'] ?>)" title="Revert Payment">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Loan Add Modal -->
    <div id="loanModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.85); z-index:99999; align-items:flex-start; justify-content:center; overflow-y:auto; padding:16px; margin:0 !important; box-sizing:border-box;">
        <div class="login-card" style="width:90%; max-width:540px; margin:40px auto;">
            <div class="login-header">
                <h2>Add Borrowed Loan</h2>
                <p>Register a liability loan from bank or institution.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="save_loan">
                
                <div class="modal-grid-responsive" style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                    <div class="form-group" style="grid-column: 1/-1;">
                        <label class="form-label">Lender Name *</label>
                        <input type="text" name="lender_name" id="lender_name" class="form-control" placeholder="e.g. ICICI Bank" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Principal Amount *</label>
                        <input type="number" step="0.01" name="principal" id="principal" class="form-control" placeholder="0.00" oninput="calcEMI()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Annual Interest Rate (%) *</label>
                        <input type="number" step="0.01" name="interest_rate" id="interest_rate" class="form-control" placeholder="e.g. 9.5" oninput="calcEMI()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tenure (Months) *</label>
                        <input type="number" name="tenure_months" id="tenure_months" class="form-control" placeholder="e.g. 24" oninput="calcEMI()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Start Date *</label>
                        <input type="date" name="start_date" id="start_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">EMI Due Day *</label>
                        <input type="number" name="emi_day" id="emi_day" class="form-control" value="5" min="1" max="28" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Repayment Account *</label>
                        <select name="repayment_account_id" id="repayment_account_id" class="form-control" required>
                            <option value="">-- Choose Account --</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= clean($acc['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1/-1;">
                        <label class="form-label">Notes (Optional)</label>
                        <input type="text" name="notes" id="notes" class="form-control" placeholder="e.g. Personal Use / Office Loan">
                    </div>
                </div>

                <div id="emiPreview" style="margin-top: 15px; display: none;">
                    <div class="emi-result-box">
                        <div class="emi-result-item"><label>EMI</label><span id="previewEMI">-</span></div>
                        <div class="emi-result-item"><label>Interest</label><span id="previewInterest">-</span></div>
                        <div class="emi-result-item"><label>Total Payable</label><span id="previewTotal">-</span></div>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top:20px; width:100%; justify-content:center;">Save Loan</button>
                <button type="button" class="btn-secondary" style="margin-top:10px; width:100%;" onclick="closeLoanModal()">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Mark Repayment Modal -->
    <div id="paymentModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.85); z-index:99999; align-items:flex-start; justify-content:center; overflow-y:auto; padding:16px; margin:0 !important; box-sizing:border-box;">
        <div class="login-card" style="width:90%; max-width:440px; margin:40px auto;">
            <div class="login-header">
                <h2>Record Loan Repayment</h2>
                <p id="paymentLoanLabel">Lender: -</p>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="save_payment">
                <input type="hidden" name="loan_id" id="paymentLoanId">
                
                <div class="form-group">
                    <label class="form-label">Payment Date *</label>
                    <input type="date" name="payment_date" id="paymentDate" class="form-control" required>
                </div>

                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label class="form-label">Amount *</label>
                        <a href="javascript:void(0);" id="settleFullLink" style="font-size:0.75rem; color:var(--primary); font-weight:600; text-decoration:none;" onclick="setToFullSettlement()">Settle Full</a>
                    </div>
                    <input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Pay From Account *</label>
                    <select name="account_id" id="paymentAccount" class="form-control" required>
                        <option value="">-- Choose Account --</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= clean($acc['account_name']) ?> (<?= format_currency($acc['current_balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Note / Remark</label>
                    <input type="text" name="note" id="paymentNote" class="form-control" placeholder="e.g. Month 1 EMI">
                </div>

                <button type="submit" class="btn-primary" style="margin-top:20px; width:100%; justify-content:center;">Save Payment</button>
                <button type="button" class="btn-secondary" style="margin-top:10px; width:100%;" onclick="closePaymentModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            $('#paymentsTable').DataTable({
                responsive: true,
                order: [[0, 'desc']],
                pageLength: 10
            });
            document.getElementById('paymentDate').value = new Date().toISOString().substring(0, 10);
            document.getElementById('start_date').value = new Date().toISOString().substring(0, 10);
        });

        function openAddLoanModal() {
            document.getElementById('loanModal').style.display = 'flex';
        }
        function closeLoanModal() {
            document.getElementById('loanModal').style.display = 'none';
        }

        let currentOutstanding = 0;
        function openPaymentModal(loanId, lenderName, emiAmt, outstanding) {
            document.getElementById('paymentLoanId').value = loanId;
            document.getElementById('paymentLoanLabel').textContent = "Lender: " + lenderName;
            document.getElementById('paymentAmount').value = emiAmt.toFixed(2);
            document.getElementById('paymentNote').value = "EMI Payment";
            currentOutstanding = outstanding;
            
            // Update settle full link text
            document.getElementById('settleFullLink').textContent = `Settle Full (${currentOutstanding.toFixed(2)})`;
            
            document.getElementById('paymentModal').style.display = 'flex';
        }
        
        function setToFullSettlement() {
            document.getElementById('paymentAmount').value = currentOutstanding.toFixed(2);
            document.getElementById('paymentNote').value = "Full Prepayment / Foreclosure Settlement";
            Swal.fire({
                icon: 'info',
                title: 'Foreclosure Auto-Fill',
                text: 'Settlement amount set to total outstanding payable.',
                confirmButtonColor: 'var(--primary)',
                timer: 1500
            });
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        function calcEMI() {
            const P = parseFloat(document.getElementById('principal').value) || 0;
            const r = (parseFloat(document.getElementById('interest_rate').value) || 0) / 12 / 100;
            const N = parseInt(document.getElementById('tenure_months').value) || 0;

            const emiPreview = document.getElementById('emiPreview');
            if (P <= 0 || r <= 0 || N <= 0) {
                emiPreview.style.display = 'none';
                return;
            }

            let emi = 0;
            if (r > 0) {
                emi = (P * r * Math.pow(1 + r, N)) / (Math.pow(1 + r, N) - 1);
            } else {
                emi = P / N;
            }

            const totalPayable = emi * N;
            const interest = totalPayable - P;

            document.getElementById('previewEMI').textContent = emi.toFixed(2);
            document.getElementById('previewInterest').textContent = interest.toFixed(2);
            document.getElementById('previewTotal').textContent = totalPayable.toFixed(2);
            emiPreview.style.display = 'block';
        }

        function confirmDeleteLoan(id, lender) {
            Swal.fire({
                title: 'Delete Loan?',
                text: `Are you sure you want to delete borrowed loan from "${lender}"? This will only work if there are no logged payment transactions!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'var(--text-secondary)',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `loans.php?delete_loan=${id}`;
                }
            });
        }

        function confirmDeletePayment(id, amt) {
            Swal.fire({
                title: 'Delete Payment?',
                text: `Delete payment log of ${amt.toFixed(2)}? This will add the funds back to your account and re-calculate loan metrics.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'var(--text-secondary)',
                confirmButtonText: 'Yes, delete payment'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `loans.php?delete_payment=${id}`;
                }
            });
        }
    </script>
</body>
</html>
