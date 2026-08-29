<?php
// Lent Loans (Given Loans) Management Module for Income & Expense Management System (IEMS)
require_once 'config.php';
require_role(['super_admin', 'admin']);

$active_page = 'loans_given';
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

if (!function_exists('get_interest_collected_so_far_php')) {
    function get_interest_collected_so_far_php($l) {
        $principal = (float)$l['principal'];
        $rate = (float)$l['interest_rate'];
        $tenure = (int)$l['tenure_months'];
        $emi = (float)$l['emi_amount'];
        $paidCount = (int)$l['emi_paid'];

        if ($principal <= 0 || $rate <= 0 || $tenure <= 0 || $emi <= 0) return 0.00;

        $balance = $principal;
        $monthlyRate = ($rate / 100) / 12;
        $interestCollected = 0.00;

        for ($m = 1; $m <= min($paidCount, $tenure); $m++) {
            $interest = $balance * $monthlyRate;
            $principalPortion = $emi - $interest;
            $balance = $balance - $principalPortion;
            $interestCollected += $interest;
        }
        return $interestCollected;
    }
}

$currency_symbol = getSetting('currency_symbol', '₹');
$system_upi_id = getSetting('upi_id', '');
$system_upi_name = getSetting('upi_name', '');
$system_static_qr = getSetting('static_qr', '');

// Fetch active bank accounts
$accounts = $pdo->query("SELECT id, account_name, bank_name, current_balance FROM bank_accounts WHERE status = 'active' ORDER BY account_name ASC")->fetchAll();

// Handle Delete Loan Given
if (isset($_GET['delete_loan'])) {
    $delete_id = (int)$_GET['delete_loan'];
    try {
        $pdo->beginTransaction();
        
        // Check if payments exist
        $pmts = $pdo->prepare("SELECT COUNT(*) FROM loan_given_payments WHERE loan_given_id = ?");
        $pmts->execute([$delete_id]);
        if ($pmts->fetchColumn() > 0) {
            throw new Exception("Cannot delete loan. Please delete all recovery payment logs associated with this loan first.");
        }
        
        $stmt = $pdo->prepare("DELETE FROM loans_given WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        log_activity("Deleted Lent Loan ID {$delete_id}");
        $pdo->commit();
        set_flash_message('success', 'Lent loan record deleted successfully.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash_message('error', $e->getMessage());
    }
    header("Location: loans-given.php");
    exit;
}

// Handle Delete Payment
if (isset($_GET['delete_payment'])) {
    $payment_id = (int)$_GET['delete_payment'];
    try {
        $pdo->beginTransaction();
        
        // Get payment details
        $stmt = $pdo->prepare("SELECT * FROM loan_given_payments WHERE id = ? FOR UPDATE");
        $stmt->execute([$payment_id]);
        $pmt = $stmt->fetch();
        
        if ($pmt) {
            $loan_given_id = (int)$pmt['loan_given_id'];
            $amount = (float)$pmt['amount'];
            $acc_id = (int)$pmt['account_id'];
            
            // 1. Delete payment entry
            $del_stmt = $pdo->prepare("DELETE FROM loan_given_payments WHERE id = ?");
            $del_stmt->execute([$payment_id]);
            
            // 2. Adjust bank account balance (Since we collected repayment, deleting it reverses the credit, i.e., subtracts balance)
            $upd_acc = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
            $upd_acc->execute([$amount, $acc_id]);
            
            // 3. Update loan stats
            $upd_loan = $pdo->prepare("UPDATE loans_given SET total_paid = total_paid - ?, emi_paid = GREATEST(0, emi_paid - 1), status = 'active' WHERE id = ?");
            $upd_loan->execute([$amount, $loan_given_id]);
            
            log_activity("Deleted Lent Loan Recovery ID {$payment_id}: Subtracted " . format_currency($amount) . " from Acc ID {$acc_id}");
            $pdo->commit();
            set_flash_message('success', 'Recovery payment deleted and account balance adjusted.');
        } else {
            $pdo->rollBack();
            set_flash_message('error', 'Recovery payment log not found.');
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash_message('error', 'Failed to delete payment: ' . $e->getMessage());
    }
    header("Location: loans-given.php");
    exit;
}

// Handle Form Submission: Add Lent Loan (Given Loan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_loan') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $debtor_name = clean($_POST['debtor_name'] ?? '');
        $debtor_address = clean($_POST['debtor_address'] ?? '');
        $debtor_email = clean($_POST['debtor_email'] ?? '');
        $debtor_phone = clean($_POST['debtor_phone'] ?? '');
        $principal = (float)($_POST['principal'] ?? 0.00);
        $interest_rate = (float)($_POST['interest_rate'] ?? 0.00);
        $tenure_months = (int)($_POST['tenure_months'] ?? 0);
        $start_date = $_POST['start_date'] ?? '';
        $emi_day = (int)($_POST['emi_day'] ?? 5);
        $repayment_account_id = (int)($_POST['repayment_account_id'] ?? 0);
        $repayment_upi = clean($_POST['repayment_upi'] ?? '');
        $disburse_account_id = (int)($_POST['disburse_account_id'] ?? 0);
        $created_by = $_SESSION['user_id'];
        
        // Handle file upload
        $document_path = null;
        if (isset($_FILES['loan_document']) && $_FILES['loan_document']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['loan_document'];
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $upload_dir = __DIR__ . '/uploads/documents/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $filename = 'doc_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $document_path = 'uploads/documents/' . $filename;
                }
            }
        }
        
        // Calculate monthly EMI
        $monthly_rate = ($interest_rate / 12) / 100;
        if ($monthly_rate > 0) {
            $emi_amount = ($principal * $monthly_rate * pow(1 + $monthly_rate, $tenure_months)) / (pow(1 + $monthly_rate, $tenure_months) - 1);
        } else {
            $emi_amount = $principal / $tenure_months;
        }

        if (empty($debtor_name) || empty($debtor_phone) || $principal <= 0 || $interest_rate <= 0 || $tenure_months <= 0 || empty($start_date) || $repayment_account_id <= 0) {
            $error = 'Please fill out all required fields.';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO loans_given (debtor_name, debtor_address, debtor_email, debtor_phone, principal, interest_rate, tenure_months, emi_amount, start_date, emi_day, repayment_account_id, repayment_upi, total_paid, emi_paid, status, created_by, document_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0, 'active', ?, ?)");
                $stmt->execute([
                    $debtor_name,
                    $debtor_address,
                    $debtor_email ?: null,
                    $debtor_phone,
                    $principal,
                    $interest_rate,
                    $tenure_months,
                    $emi_amount,
                    $start_date,
                    $emi_day,
                    $repayment_account_id,
                    $repayment_upi ?: null,
                    $created_by,
                    $document_path
                ]);

                // Subtract from disburse account if selected
                if ($disburse_account_id > 0) {
                    $upd_acc = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                    $upd_acc->execute([$principal, $disburse_account_id]);
                }
                
                $pdo->commit();
                log_activity("Lent Money / Created Given Loan for {$debtor_name}: Principal " . format_currency($principal));
                set_flash_message('success', 'Given Loan recorded successfully.');
                header("Location: loans-given.php");
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to save given loan: ' . $e->getMessage();
            }
        }
    }
}

// Handle Form Submission: Record EMI Payment Recovery
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_payment') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $loan_given_id = (int)($_POST['loan_given_id'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0.00);
        $account_id = (int)($_POST['account_id'] ?? 0);
        $payment_method = clean($_POST['payment_method'] ?? 'cash');
        $note = clean($_POST['note'] ?? '');
        $recorded_by = $_SESSION['user_id'];
        
        if ($loan_given_id <= 0 || empty($payment_date) || $amount <= 0 || $account_id <= 0) {
            $error = 'All fields are required and amount must be positive.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get loan info
                $stmt = $pdo->prepare("SELECT * FROM loans_given WHERE id = ? FOR UPDATE");
                $stmt->execute([$loan_given_id]);
                $loan = $stmt->fetch();
                
                if (!$loan) {
                    throw new Exception("Lent loan record not found.");
                }

                // 1. Insert payment recovery entry
                $stmt_pmt = $pdo->prepare("INSERT INTO loan_given_payments (loan_given_id, amount, payment_date, payment_method, note, account_id, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_pmt->execute([$loan_given_id, $amount, $payment_date, $payment_method, $note, $account_id, $recorded_by]);
                
                // 2. Add to bank account balance (credit log as income)
                $upd_acc = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $upd_acc->execute([$amount, $account_id]);

                // Create income record
                $inc_stmt = $pdo->prepare("INSERT INTO income (account_id, title, category, amount, payment_method, reference_no, description, income_date, created_by) VALUES (?, ?, 'Loan Recovery', ?, ?, ?, ?, ?, ?)");
                $inc_stmt->execute([
                    $account_id,
                    "Lent Loan Recovery: " . $loan['debtor_name'],
                    $amount,
                    $payment_method,
                    "LREC-" . time(),
                    "EMI Collection recovery log. Note: " . $note,
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

                $upd_loan = $pdo->prepare("UPDATE loans_given SET total_paid = ?, emi_paid = ?, status = ? WHERE id = ?");
                $upd_loan->execute([$new_total_paid, $new_emi_paid, $status, $loan_given_id]);
                
                log_activity("Collected EMI Recovery payment of " . format_currency($amount) . " from Debtor {$loan['debtor_name']}");
                $pdo->commit();
                set_flash_message('success', 'Recovery repayment recorded successfully.');
                header("Location: loans-given.php");
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
    FROM loans_given l
    JOIN bank_accounts a ON l.repayment_account_id = a.id
    ORDER BY l.created_at DESC
")->fetchAll();

$kpi = [
    'total_lent' => 0.00,
    'total_recovered' => 0.00,
    'outstanding' => 0.00,
    'monthly_recovery' => 0.00,
    'total_interest_earned' => 0.00,
    'total_interest_expected' => 0.00,
    'loan_count' => count($loans),
    'active_count' => 0
];

foreach ($loans as $l) {
    $total_payable = (float)$l['emi_amount'] * (int)$l['tenure_months'];
    $kpi['total_lent'] += (float)$l['principal'];
    $kpi['total_recovered'] += (float)$l['total_paid'];
    
    $kpi['total_interest_expected'] += ($total_payable - (float)$l['principal']);
    $kpi['total_interest_earned'] += get_interest_collected_so_far_php($l);
    
    if ($l['status'] === 'active') {
        $kpi['active_count']++;
        $kpi['outstanding'] += max(0, $total_payable - (float)$l['total_paid']);
        $kpi['monthly_recovery'] += (float)$l['emi_amount'];
    }
}

// Fetch recovery payments log
$payments = $pdo->query("
    SELECT p.*, l.debtor_name, a.account_name, u.full_name as recorder_name 
    FROM loan_given_payments p
    JOIN loans_given l ON p.loan_given_id = l.id
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
    <title>Lent Loans Tracker - IEMS ERP</title>
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
            grid-template-columns: repeat(5, 1fr);
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
        .loan-stat-card.blue { background: linear-gradient(135deg, #1e3a8a, #0284c7); }
        .loan-stat-card.green { background: linear-gradient(135deg, #064e3b, #10b981); }
        .loan-stat-card.indigo { background: linear-gradient(135deg, #3730a3, #6366f1); }
        .loan-stat-card.red { background: linear-gradient(135deg, #78350f, #d97706); }
        .loan-stat-card.amber { background: linear-gradient(135deg, #581c87, #8b5cf6); }
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
        .loan-progress-bar-fill { height: 100%; border-radius: 6px; background: #8b5cf6; }
        .loan-detail-row { display: flex; justify-content: space-between; font-size: 0.82rem; padding: 5px 0; border-bottom: 1px solid var(--border-color); }
        .loan-detail-row:last-child { border-bottom: none; }
        .loan-detail-label { color: var(--text-secondary); display: flex; align-items: center; gap: 7px; }
        .loan-detail-value { font-weight: 600; color: var(--text-light); }
        
        .emi-result-box {
            background: rgba(139, 92, 246, 0.08);
            border: 1px solid rgba(139, 92, 246, 0.25); border-radius: 14px;
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

        .qr-display-card {
            background: var(--bg-primary);
            border: 1px dashed var(--border-color);
            border-radius: 12px; padding: 15px;
            text-align: center; margin-top: 15px;
        }
        .qr-holder {
            width: 150px; height: 150px; background: #1e293b;
            border-radius: 8px; border: 1px solid var(--border-color);
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--text-secondary); font-size: 0.8rem; margin: 10px auto;
        }
        .qr-img {
            width: 150px; height: 150px; border-radius: 8px;
            border: 3px solid white; display: block; margin: 10px auto;
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
                <div class="page-title"><i class="fa-solid fa-hand-holding-hand" style="margin-right: 8px; color: #a855f7;"></i>Lent Loans (Given) Manager</div>
                <div class="nav-actions">
                    <button class="btn-primary" onclick="openAddLoanModal()"><i class="fa-solid fa-plus"></i> Record Given Loan</button>
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
                        <div class="loan-stat-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                        <div class="loan-stat-label">Total Principal Lent</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['total_lent']) ?></div>
                        <div class="loan-stat-sub"><?= $kpi['loan_count'] ?> loans active</div>
                    </div>
                    <div class="loan-stat-card green">
                        <div class="loan-stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="loan-stat-label">Total Recovered</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['total_recovered']) ?></div>
                        <div class="loan-stat-sub"><?= $kpi['total_lent'] > 0 ? round(($kpi['total_recovered'] / ($kpi['total_lent'] * 1.15)) * 100, 1) : 0 ?>% recovered</div>
                    </div>
                    <div class="loan-stat-card indigo">
                        <div class="loan-stat-icon"><i class="fa-solid fa-coins"></i></div>
                        <div class="loan-stat-label">Interest Earned</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['total_interest_earned']) ?></div>
                        <div class="loan-stat-sub">Expected: <?= format_currency($kpi['total_interest_expected']) ?></div>
                    </div>
                    <div class="loan-stat-card red">
                        <div class="loan-stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div class="loan-stat-label">Total Outstanding Recovery</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['outstanding']) ?></div>
                        <div class="loan-stat-sub"><?= $kpi['active_count'] ?> active borrowers</div>
                    </div>
                    <div class="loan-stat-card amber">
                        <div class="loan-stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                        <div class="loan-stat-label">Monthly Expected EMI</div>
                        <div class="loan-stat-value"><?= format_currency($kpi['monthly_recovery']) ?></div>
                        <div class="loan-stat-sub">combined recovery inflow</div>
                    </div>
                </div>

                <div class="header-title-section" style="margin-bottom: 20px;">
                    <h2>Active Receivables (Given Loans)</h2>
                    <p>Borrowers lists and EMI payments progress.</p>
                </div>

                <div class="loans-grid">
                    <?php if (empty($loans)): ?>
                        <div class="table-card" style="grid-column: 1/-1; padding: 40px; text-align: center;">
                            <i class="fa-solid fa-circle-info" style="font-size: 2.5rem; color: var(--text-secondary); margin-bottom: 12px;"></i>
                            <h3>No Lent Loans Recorded</h3>
                            <p style="font-size: 0.88rem; color: var(--text-secondary);">Click Record Given Loan above to register money lent to borrowers.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($loans as $l): 
                            $total_payable = (float)$l['emi_amount'] * (int)$l['tenure_months'];
                            $paid_pct = min(100, round(($l['total_paid'] / $total_payable) * 100));
                        ?>
                            <div class="loan-card" style="opacity: <?= $l['status'] === 'closed' ? '0.7' : '1' ?>;">
                                <div class="loan-card-header">
                                    <div>
                                        <h3 style="margin:0; font-size:1.1rem; color:white; font-weight:700;"><?= clean($l['debtor_name']) ?></h3>
                                        <span style="font-size:0.75rem; color:var(--text-secondary);">Contact: +91 <?= clean($l['debtor_phone']) ?></span>
                                    </div>
                                    <span class="loan-type-badge" style="background: <?= $l['status'] === 'closed' ? 'rgba(16,185,129,0.1)' : 'rgba(139,92,246,0.1)' ?>; color: <?= $l['status'] === 'closed' ? '#10b981' : '#8b5cf6' ?>; border: 1px solid <?= $l['status'] === 'closed' ? 'rgba(16,185,129,0.3)' : 'rgba(139,92,246,0.3)' ?>;">
                                        <?= strtoupper($l['status']) ?>
                                    </span>
                                </div>

                                <div>
                                    <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-secondary); margin-bottom:4px;">
                                        <span>Recovery Progress</span>
                                        <span><?= $paid_pct ?>%</span>
                                    </div>
                                    <div class="loan-progress-bar-bg">
                                        <div class="loan-progress-bar-fill" style="width: <?= $paid_pct ?>%; background: <?= $l['status'] === 'closed' ? '#10b981' : '#8b5cf6' ?>;"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-wallet"></i> Principal Lent</span>
                                        <span class="loan-detail-value"><?= format_currency($l['principal']) ?></span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-percent"></i> Interest Rate</span>
                                        <span class="loan-detail-value"><?= clean($l['interest_rate']) ?>% p.a.</span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-calendar"></i> Repayment Tenure</span>
                                        <span class="loan-detail-value"><?= clean($l['tenure_months']) ?> months</span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-sack-dollar"></i> Monthly EMI</span>
                                        <span class="loan-detail-value" style="color: #8b5cf6;"><?= format_currency($l['emi_amount']) ?></span>
                                    </div>
                                    <?php 
                                    $exp_int = ((float)$l['emi_amount'] * (int)$l['tenure_months']) - (float)$l['principal'];
                                    $rec_int = get_interest_collected_so_far_php($l);
                                    ?>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-coins"></i> Expected Interest</span>
                                        <span class="loan-detail-value" style="color: #f59e0b;"><?= format_currency($exp_int) ?></span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-money-bill-trend-up"></i> Interest Recovered</span>
                                        <span class="loan-detail-value" style="color: #10b981;"><?= format_currency($rec_int) ?></span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-money-bill-transfer"></i> Total Recovered</span>
                                        <span class="loan-detail-value" style="color: var(--success);"><?= format_currency($l['total_paid']) ?> (<?= $l['emi_paid'] ?> EMIs)</span>
                                    </div>
                                    <div class="loan-detail-row">
                                        <span class="loan-detail-label"><i class="fa-solid fa-building-columns"></i> Deposit Account</span>
                                        <span class="loan-detail-value" style="font-size:0.75rem;"><?= clean($l['account_name']) ?></span>
                                    </div>
                                    <?php if (!empty($l['document_path'])): ?>
                                    <div class="loan-detail-row" style="margin-top: 6px; border-top: 1px dashed var(--border-color); padding-top: 6px;">
                                        <span class="loan-detail-label" style="color: var(--primary);"><i class="fa-solid fa-file-pdf"></i> Agreement Doc</span>
                                        <span class="loan-detail-value"><a href="<?= clean($l['document_path']) ?>" target="_blank" style="color: var(--primary); text-decoration: none; font-weight: 700;"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Doc</a></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div style="display:flex; flex-direction:column; gap:8px; margin-top:5px;">
                                    <div style="display:flex; gap:10px;">
                                        <?php if ($l['status'] === 'active'): ?>
                                            <button class="btn-primary" style="flex:1; justify-content:center; padding:8px 12px; font-size:0.82rem; background: #8b5cf6; border-color: #8b5cf6; margin:0;" onclick="openPaymentModal(<?= $l['id'] ?>, '<?= clean($l['debtor_name']) ?>', <?= $l['emi_amount'] ?>, <?= $total_payable - $l['total_paid'] ?>, '<?= clean($l['repayment_upi']) ?>')">
                                                <i class="fa-solid fa-qrcode"></i> Collect EMI
                                            </button>
                                        <?php endif; ?>
                                        <a href="print-loan.php?type=given&id=<?= $l['id'] ?>" target="_blank" class="btn-secondary" style="flex:1; justify-content:center; padding:8px 12px; font-size:0.82rem; display:inline-flex; align-items:center; gap:6px; text-decoration:none; margin:0; border-color:#3b82f6; color:#3b82f6; background:transparent;">
                                            <i class="fa-solid fa-print"></i> Print Schedule
                                        </a>
                                    </div>
                                    <button class="btn-secondary" style="border: 1px solid rgba(239,68,68,0.2); color:#ef4444; background:transparent; padding:8px 12px; font-size:0.82rem; width:100%; margin:0;" onclick="confirmDeleteLoan(<?= $l['id'] ?>, '<?= clean($l['debtor_name']) ?>')">
                                        <i class="fa-solid fa-trash-can"></i> Delete Loan
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="table-card" style="margin-top: 20px;">
                    <div class="header-title-section" style="margin-bottom: 20px;">
                        <h2>Recovery Ledger</h2>
                        <p>EMI payments recovered from debtors.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="custom-table" id="paymentsTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Debtor / Borrower</th>
                                    <th>Method</th>
                                    <th>Received In</th>
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
                                        <td style="font-weight:600; color:white;"><?= clean($pmt['debtor_name']) ?></td>
                                        <td><span class="badge" style="background: rgba(139,92,246,0.1); color:#8b5cf6; border: 1px solid rgba(139,92,246,0.2);"><?= strtoupper(clean($pmt['payment_method'])) ?></span></td>
                                        <td><?= clean($pmt['account_name']) ?></td>
                                        <td><span style="font-size:0.82rem; color:var(--text-secondary);"><?= clean($pmt['note'] ?: '-') ?></span></td>
                                        <td style="font-weight:700; color:var(--success);"><?= format_currency($pmt['amount']) ?></td>
                                        <td><span style="font-size:0.82rem; color:var(--text-secondary);"><?= clean($pmt['recorder_name']) ?></span></td>
                                        <td style="display:flex; gap:6px; align-items:center;">
                                            <a href="print-repayment.php?type=given&id=<?= $pmt['id'] ?>" target="_blank" class="action-btn view-btn" style="background:rgba(59,130,246,0.15); color:#3b82f6; border:1px solid rgba(59,130,246,0.3); padding:5px 8px; border-radius:6px; font-size:0.85rem;" title="Print Receipt">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <button class="action-btn delete-btn" onclick="confirmDeletePayment(<?= $pmt['id'] ?>, <?= $pmt['amount'] ?>)" title="Revert Payment" style="margin:0;">
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
    </div>    <!-- Loan Add Modal -->
    <div id="loanModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.85); z-index:99999; align-items:flex-start; justify-content:center; overflow-y:auto; padding:16px; margin:0 !important; box-sizing:border-box;">
        <div class="login-card" style="width:90%; max-width:540px; margin:40px auto; overflow:hidden;">
            <div class="login-header" style="background: linear-gradient(135deg, #8b5cf6, #6366f1); padding: 24px 30px; margin: -40px -40px 24px -40px; border-top-left-radius: 16px; border-top-right-radius: 16px; position: relative;">
                <h2 style="color: white; margin: 0; font-size: 1.35rem; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-hand-holding-hand" style="color: white;"></i> Record New Given Loan
                </h2>
                <p style="color: rgba(255,255,255,0.85); margin: 6px 0 0 0; font-size: 0.8rem; font-weight: 500;">Disburse capital, compute EMI, collect repayments</p>
                <button type="button" onclick="closeLoanModal()" style="position: absolute; top: 22px; right: 22px; background: rgba(255,255,255,0.15); border: none; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; color: white; cursor: pointer; transition: background 0.2s;"><i class="fa-solid fa-xmark" style="font-size: 0.95rem;"></i></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="save_loan">
                
                <div class="modal-grid-responsive" style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                    <!-- Debtor Name -->
                    <div class="form-group" style="grid-column: 1/-1;">
                        <label class="form-label">DEBTOR NAME *</label>
                        <input type="text" name="debtor_name" id="debtor_name" class="form-control" placeholder="e.g. Ramesh Kumar" required>
                    </div>
                    <!-- Whatsapp/Phone and Email ID -->
                    <div class="form-group">
                        <label class="form-label">WHATSAPP / PHONE *</label>
                        <input type="text" name="debtor_phone" id="debtor_phone" class="form-control" placeholder="e.g. 9876543210" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">EMAIL ID (OPTIONAL)</label>
                        <input type="email" name="debtor_email" id="debtor_email" class="form-control" placeholder="e.g. ramesh@gmail.com">
                    </div>
                    <!-- Debtor Address -->
                    <div class="form-group" style="grid-column: 1/-1;">
                        <label class="form-label">DEBTOR ADDRESS *</label>
                        <input type="text" name="debtor_address" id="debtor_address" class="form-control" placeholder="e.g. Flat 102, Sector 15, Noida" required>
                    </div>

                    <!-- Divider: Interest & Calculations -->
                    <div style="grid-column: 1/-1; margin-top: 10px; margin-bottom: 5px; border-bottom: 1px solid var(--border-color); padding-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-calculator" style="color: #a855f7; font-size: 0.9rem;"></i>
                        <span style="font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; color: #a855f7;">Interest & Calculations</span>
                    </div>

                    <!-- Principal and Interest Rate -->
                    <div class="form-group">
                        <label class="form-label">PRINCIPAL AMOUNT *</label>
                        <input type="number" step="0.01" name="principal" id="principal" class="form-control" placeholder="e.g. 100000" oninput="calcEMI()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">INTEREST RATE (% P.A.) *</label>
                        <input type="number" step="0.01" name="interest_rate" id="interest_rate" class="form-control" placeholder="e.g. 12" oninput="calcEMI()" required>
                    </div>
                    <!-- Tenure and Disbursed Date -->
                    <div class="form-group">
                        <label class="form-label">TENURE (MONTHS) *</label>
                        <input type="number" name="tenure_months" id="tenure_months" class="form-control" placeholder="e.g. 12" oninput="calcEMI()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">DISBURSED DATE *</label>
                        <input type="date" name="start_date" id="start_date" class="form-control" required>
                    </div>
                    <!-- EMI Day and Disburse From Account -->
                    <div class="form-group">
                        <label class="form-label">EMI COLLECTION DAY (1-28) *</label>
                        <input type="number" name="emi_day" id="emi_day" class="form-control" value="5" min="1" max="28" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">DISBURSE FROM BANK ACCOUNT *</label>
                        <select name="disburse_account_id" id="disburse_account_id" class="form-control" required>
                            <option value="">Select Bank Account</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= clean($acc['account_name']) ?> (<?= format_currency($acc['current_balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Divider: Repayment Instructions -->
                    <div style="grid-column: 1/-1; margin-top: 10px; margin-bottom: 5px; border-bottom: 1px solid var(--border-color); padding-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-building-columns" style="color: #a855f7; font-size: 0.9rem;"></i>
                        <span style="font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; color: #a855f7;">Repayment Instructions (To Debtor)</span>
                    </div>

                    <!-- Repayment Account and Repayment UPI ID -->
                    <div class="form-group">
                        <label class="form-label">REPAYMENT BANK ACCOUNT *</label>
                        <select name="repayment_account_id" id="repayment_account_id" class="form-control" required>
                            <option value="">Select Bank Account</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= clean($acc['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">REPAYMENT UPI ID *</label>
                        <input type="text" name="repayment_upi" id="repayment_upi" class="form-control" placeholder="e.g. corporate@upi" required>
                    </div>

                    <!-- File Upload -->
                    <div class="form-group" style="grid-column: 1/-1;">
                        <label class="form-label">LOAN AGREEMENT / DOCUMENT (PDF / IMAGE)</label>
                        <input type="file" name="loan_document" id="loan_document" class="form-control" accept="image/*,application/pdf">
                    </div>
                </div>

                <div id="emiPreview" style="margin-top: 15px; display: none;">
                    <div class="emi-result-box">
                        <div class="emi-result-item"><label>EMI</label><span id="previewEMI">-</span></div>
                        <div class="emi-result-item"><label>Interest</label><span id="previewInterest">-</span></div>
                        <div class="emi-result-item"><label>Total Payable</label><span id="previewTotal">-</span></div>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 24px; margin-bottom: 8px;">
                    <button type="button" class="btn-secondary" style="flex: 1; justify-content: center; margin: 0; display: flex; align-items: center; gap: 8px;" onclick="closeLoanModal()">
                        <i class="fa-solid fa-xmark"></i> Close
                    </button>
                    <button type="submit" class="btn-primary" style="flex: 1.5; justify-content: center; margin: 0; display: flex; align-items: center; gap: 8px; background: #8b5cf6; border-color: #8b5cf6;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Loan Contract
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mark Repayment Modal -->
    <div id="paymentModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.85); z-index:99999; align-items:flex-start; justify-content:center; overflow-y:auto; padding:16px; margin:0 !important; box-sizing:border-box;">
        <div class="login-card" style="width:90%; max-width:440px; margin:40px auto;">
            <div class="login-header">
                <h2>Record EMI Recovery</h2>
                <p id="paymentLoanLabel">Borrower: -</p>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="save_payment">
                <input type="hidden" name="loan_given_id" id="paymentLoanId">
                
                <div class="form-group">
                    <label class="form-label">Collection Date *</label>
                    <input type="date" name="payment_date" id="paymentDate" class="form-control" required>
                </div>

                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label class="form-label">Amount *</label>
                        <a href="javascript:void(0);" id="settleFullLink" style="font-size:0.75rem; color:var(--primary); font-weight:600; text-decoration:none;" onclick="setToFullSettlement()">Settle Full</a>
                    </div>
                    <input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" placeholder="0.00" oninput="updateQrCode()" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Receive Into Account *</label>
                    <select name="account_id" id="paymentAccount" class="form-control" required>
                        <option value="">-- Choose Account --</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= clean($acc['account_name']) ?> (<?= format_currency($acc['current_balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <select name="payment_method" id="paymentMethod" class="form-control" onchange="togglePaymentMethod(this.value)" required>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI / QR Code</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Note / Remark</label>
                    <input type="text" name="note" id="paymentNote" class="form-control" placeholder="e.g. Month 1 EMI Recovery">
                </div>

                <!-- UPI QR Display -->
                <div class="qr-display-card" id="upiQrCodeContainer" style="display:none;">
                    <div style="font-size:0.82rem; font-weight:700; color:white; margin-bottom:5px;">Scan QR to Collect EMI</div>
                    <div id="qrStatus text-secondary">
                        <?php if (empty($system_upi_id)): ?>
                            <?php if (!empty($system_static_qr)): ?>
                                <img src="<?= clean($system_static_qr) ?>" class="qr-img" alt="Static UPI QR">
                                <div style="font-size: 0.72rem; color: var(--text-secondary); margin-top: 5px;">Static Backup QR Code</div>
                            <?php else: ?>
                                <div class="alert alert-warning" style="font-size:0.78rem; padding:8px; margin:5px 0;">
                                    Configure system UPI VPA inside Settings -> Payments to generate Dynamic QR.
                                </div>
                                <div class="qr-holder"><i class="fa-solid fa-qrcode" style="font-size:32px;margin-bottom:8px;"></i><br>UPI Not Setup</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="qr-holder" id="qrLoader">Enter amount...</div>
                            <img id="qrImgElement" class="qr-img" style="display:none;" alt="Scan to pay">
                            <div style="font-size: 0.72rem; color: var(--text-secondary); margin-top: 5px;">UPI ID: <?= clean($system_upi_id) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn-secondary" style="flex: 1; justify-content: center; margin: 0;" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="flex: 1; justify-content: center; margin: 0;">Save Recovery</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const SYSTEM_UPI_ID = '<?= clean($system_upi_id) ?>';
        const SYSTEM_UPI_NAME = '<?= clean($system_upi_name) ?>';

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
        let debtorUpi = '';
        let activeDebtorName = '';

        function openPaymentModal(loanId, debtorName, emiAmt, outstanding, upiVpa) {
            document.getElementById('paymentLoanId').value = loanId;
            document.getElementById('paymentLoanLabel').textContent = "Borrower: " + debtorName;
            document.getElementById('paymentAmount').value = emiAmt.toFixed(2);
            document.getElementById('paymentNote').value = "EMI Collection";
            currentOutstanding = outstanding;
            debtorUpi = upiVpa;
            activeDebtorName = debtorName;

            // Reset payment method
            document.getElementById('paymentMethod').value = 'cash';
            document.getElementById('upiQrCodeContainer').style.display = 'none';
            
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
                text: 'Settlement amount set to total outstanding recovery.',
                confirmButtonColor: 'var(--primary)',
                timer: 1500
            });
            updateQrCode();
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        function togglePaymentMethod(val) {
            const qrBox = document.getElementById('upiQrCodeContainer');
            if (val === 'upi') {
                qrBox.style.display = 'block';
                updateQrCode();
            } else {
                qrBox.style.display = 'none';
            }
        }

        function updateQrCode() {
            if (!SYSTEM_UPI_ID) return;
            const amt = parseFloat(document.getElementById('paymentAmount').value) || 0;
            const loader = document.getElementById('qrLoader');
            const img = document.getElementById('qrImgElement');

            if (amt <= 0) {
                if (loader) {
                    loader.style.display = 'inline-flex';
                    loader.textContent = "Enter amount...";
                }
                if (img) img.style.display = 'none';
                return;
            }

            if (loader) {
                loader.style.display = 'inline-flex';
                loader.textContent = "Compiling QR...";
            }
            if (img) img.style.display = 'none';

            const encodedName = encodeURIComponent(SYSTEM_UPI_NAME);
            const encodedPurpose = encodeURIComponent("EMI Repayment from " + activeDebtorName);
            const upiString = `upi://pay?pa=${SYSTEM_UPI_ID}&pn=${encodedName}&am=${amt.toFixed(2)}&tn=${encodedPurpose}&cu=INR`;
            
            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(upiString)}`;

            const temp = new Image();
            temp.onload = function() {
                if (loader) loader.style.display = 'none';
                if (img) {
                    img.src = qrUrl;
                    img.style.display = 'block';
                }
            };
            temp.src = qrUrl;
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

        function confirmDeleteLoan(id, name) {
            Swal.fire({
                title: 'Delete Receivable?',
                text: `Are you sure you want to delete given loan of "${name}"? This will only succeed if there are no logged repayment transactions.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'var(--text-secondary)',
                confirmButtonText: 'Yes, delete receivable'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `loans-given.php?delete_loan=${id}`;
                }
            });
        }

        function confirmDeletePayment(id, amt) {
            Swal.fire({
                title: 'Delete Payment?',
                text: `Delete recovery transaction of ${amt.toFixed(2)}? This will subtract funds from target account balance and re-calculate loan parameters.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: 'var(--text-secondary)',
                confirmButtonText: 'Yes, delete recovery'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `loans-given.php?delete_payment=${id}`;
                }
            });
        }
    </script>
</body>
</html>
