<?php
// Reporting and Statement module for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

$active_page = 'reports';

// Get filter inputs or set defaults
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // default to 1st of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t');      // default to last day of current month
$filter_type = $_GET['type'] ?? 'all';               // 'all', 'income', 'expense', 'transfer'
$filter_account = $_GET['account_id'] ?? 'all';       // account filter for general report
$statement_acc_id = $_GET['stmt_account_id'] ?? '';   // separate account select for account statement

// Fetch active accounts for filter dropdowns
$accounts_list = $pdo->query("SELECT id, account_name, bank_name, opening_balance FROM bank_accounts ORDER BY account_name ASC")->fetchAll();

// ==========================================================================
// EXPORT TO CSV LOGIC
// ==========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Clear output buffers
    if (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=IEMS_Financial_Report_' . $start_date . '_to_' . $end_date . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Header Row
    fputcsv($output, ['Date', 'Transaction Type', 'Title', 'Category', 'Account', 'Bank', 'Payment Method', 'Ref No', 'Amount (INR)', 'Description']);
    
    // Build parameters and query
    $params = [$start_date, $end_date];
    
    $income_q = "SELECT 'Income' as type, i.income_date as txn_date, i.title, i.category, a.account_name, a.bank_name, i.payment_method, i.reference_no, i.amount, i.description, i.created_at FROM income i JOIN bank_accounts a ON i.account_id = a.id WHERE i.income_date BETWEEN ? AND ?";
    if ($filter_account !== 'all') {
        $income_q .= " AND i.account_id = ?";
    }
    
    $expense_q = "SELECT 'Expense' as type, e.expense_date as txn_date, e.title, e.category, a.account_name, a.bank_name, e.payment_method, e.reference_no, e.amount, e.description, e.created_at FROM expenses e JOIN bank_accounts a ON e.account_id = a.id WHERE e.expense_date BETWEEN ? AND ?";
    if ($filter_account !== 'all') {
        $expense_q .= " AND e.account_id = ?";
    }
    
    $transfer_q = "SELECT 'Transfer' as type, t.transfer_date as txn_date, CONCAT('Transfer to ', a_to.account_name) as title, 'Transfer' as category, a_from.account_name, a_from.bank_name, 'Bank' as payment_method, '' as reference_no, t.amount, t.remarks as description, t.created_at FROM transfers t JOIN bank_accounts a_from ON t.from_account = a_from.id JOIN bank_accounts a_to ON t.to_account = a_to.id WHERE t.transfer_date BETWEEN ? AND ?";
    if ($filter_account !== 'all') {
        $transfer_q .= " AND (t.from_account = ? OR t.to_account = ?)";
    }
    
    $queries = [];
    $income_params = $params;
    $expense_params = $params;
    $transfer_params = $params;
    
    if ($filter_account !== 'all') {
        $income_params[] = $filter_account;
        $expense_params[] = $filter_account;
        $transfer_params[] = $filter_account;
        $transfer_params[] = $filter_account;
    }
    
    if ($filter_type === 'all' || $filter_type === 'income') {
        $queries[] = ["sql" => $income_q, "params" => $income_params];
    }
    if ($filter_type === 'all' || $filter_type === 'expense') {
        $queries[] = ["sql" => $expense_q, "params" => $expense_params];
    }
    if ($filter_type === 'all' || $filter_type === 'transfer') {
        $queries[] = ["sql" => $transfer_q, "params" => $transfer_params];
    }
    
    // Combine SQLs using UNION ALL
    $sql_parts = [];
    $final_params = [];
    foreach ($queries as $q) {
        $sql_parts[] = "(" . $q['sql'] . ")";
        foreach ($q['params'] as $p) {
            $final_params[] = $p;
        }
    }
    
    $union_sql = implode(" UNION ALL ", $sql_parts) . " ORDER BY txn_date DESC, created_at DESC";
    
    $stmt = $pdo->prepare($union_sql);
    $stmt->execute($final_params);
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['txn_date'],
            $row['type'],
            $row['title'],
            $row['category'],
            $row['account_name'],
            $row['bank_name'],
            $row['payment_method'],
            $row['reference_no'],
            $row['amount'],
            $row['description']
        ]);
    }
    
    fclose($output);
    exit;
}

// ==========================================================================
// P&L AND AGGREGATE CALCULATIONS FOR CURRENT FILTER
// ==========================================================================
// Total Income Filtered
$inc_sql = "SELECT SUM(amount) FROM income WHERE income_date BETWEEN ? AND ?";
$inc_params = [$start_date, $end_date];
if ($filter_account !== 'all') {
    $inc_sql .= " AND account_id = ?";
    $inc_params[] = $filter_account;
}
$stmt = $pdo->prepare($inc_sql);
$stmt->execute($inc_params);
$total_income = (float)$stmt->fetchColumn();

// Total Expense Filtered
$exp_sql = "SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ?";
$exp_params = [$start_date, $end_date];
if ($filter_account !== 'all') {
    $exp_sql .= " AND account_id = ?";
    $exp_params[] = $filter_account;
}
$stmt = $pdo->prepare($exp_sql);
$stmt->execute($exp_params);
$total_expense = (float)$stmt->fetchColumn();

// Total Transfers Filtered
$tf_sql = "SELECT SUM(amount) FROM transfers WHERE transfer_date BETWEEN ? AND ?";
$tf_params = [$start_date, $end_date];
if ($filter_account !== 'all') {
    $tf_sql .= " AND (from_account = ? OR to_account = ?)";
    $tf_params[] = $filter_account;
    $tf_params[] = $filter_account;
}
$stmt = $pdo->prepare($tf_sql);
$stmt->execute($tf_params);
$total_transfers = (float)$stmt->fetchColumn();

$net_balance = $total_income - $total_expense;

// Category Wise Breakdown
$cat_inc_sql = "SELECT category, SUM(amount) as total, COUNT(*) as count FROM income WHERE income_date BETWEEN ? AND ?";
$cat_inc_params = [$start_date, $end_date];
if ($filter_account !== 'all') {
    $cat_inc_sql .= " AND account_id = ?";
    $cat_inc_params[] = $filter_account;
}
$cat_inc_sql .= " GROUP BY category ORDER BY total DESC";
$stmt = $pdo->prepare($cat_inc_sql);
$stmt->execute($cat_inc_params);
$income_categories = $stmt->fetchAll();

$cat_exp_sql = "SELECT category, SUM(amount) as total, COUNT(*) as count FROM expenses WHERE expense_date BETWEEN ? AND ?";
$cat_exp_params = [$start_date, $end_date];
if ($filter_account !== 'all') {
    $cat_exp_sql .= " AND account_id = ?";
    $cat_exp_params[] = $filter_account;
}
$cat_exp_sql .= " GROUP BY category ORDER BY total DESC";
$stmt = $pdo->prepare($cat_exp_sql);
$stmt->execute($cat_exp_params);
$expense_categories = $stmt->fetchAll();


// ==========================================================================
// ACCOUNT RUNNING STATEMENT CALCULATIONS
// ==========================================================================
$statement_data = [];
$opening_balance = 0.00;
$stmt_account = null;

if (!empty($statement_acc_id)) {
    // Fetch details of selected account
    $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ?");
    $stmt->execute([$statement_acc_id]);
    $stmt_account = $stmt->fetch();
    
    if ($stmt_account) {
        $acc_fixed_opening = (float)$stmt_account['opening_balance'];
        
        // 1. Calculate opening balance before $start_date
        // Incomes before start_date
        $s = $pdo->prepare("SELECT SUM(amount) FROM income WHERE account_id = ? AND income_date < ?");
        $s->execute([$statement_acc_id, $start_date]);
        $inc_before = (float)$s->fetchColumn();
        
        // Expenses before start_date
        $s = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE account_id = ? AND expense_date < ?");
        $s->execute([$statement_acc_id, $start_date]);
        $exp_before = (float)$s->fetchColumn();
        
        // Transfers FROM before start_date
        $s = $pdo->prepare("SELECT SUM(amount) FROM transfers WHERE from_account = ? AND transfer_date < ?");
        $s->execute([$statement_acc_id, $start_date]);
        $tf_from_before = (float)$s->fetchColumn();
        
        // Transfers TO before start_date
        $s = $pdo->prepare("SELECT SUM(amount) FROM transfers WHERE to_account = ? AND transfer_date < ?");
        $s->execute([$statement_acc_id, $start_date]);
        $tf_to_before = (float)$s->fetchColumn();
        
        $opening_balance = $acc_fixed_opening + $inc_before - $exp_before - $tf_from_before + $tf_to_before;
        
        // 2. Query transactions between $start_date and $end_date
        $stmt_query = "
            (SELECT 'income' as type, title, amount, income_date as txn_date, created_at, reference_no, 'Income' as detail 
             FROM income 
             WHERE account_id = ? AND income_date BETWEEN ? AND ?)
            UNION ALL
            (SELECT 'expense' as type, title, -amount as amount, expense_date as txn_date, created_at, reference_no, 'Expense' as detail 
             FROM expenses 
             WHERE account_id = ? AND expense_date BETWEEN ? AND ?)
            UNION ALL
            (SELECT 'transfer_from' as type, CONCAT('Transfer to ', (SELECT account_name FROM bank_accounts WHERE id = to_account)) as title, -amount as amount, transfer_date as txn_date, created_at, '' as reference_no, 'Debit Transfer' as detail 
             FROM transfers 
             WHERE from_account = ? AND transfer_date BETWEEN ? AND ?)
            UNION ALL
            (SELECT 'transfer_to' as type, CONCAT('Transfer from ', (SELECT account_name FROM bank_accounts WHERE id = from_account)) as title, amount, transfer_date as txn_date, created_at, '' as reference_no, 'Credit Transfer' as detail 
             FROM transfers 
             WHERE to_account = ? AND transfer_date BETWEEN ? AND ?)
            ORDER BY txn_date ASC, created_at ASC
        ";
        
        $s = $pdo->prepare($stmt_query);
        $s->execute([
            $statement_acc_id, $start_date, $end_date,
            $statement_acc_id, $start_date, $end_date,
            $statement_acc_id, $start_date, $end_date,
            $statement_acc_id, $start_date, $end_date
        ]);
        $statement_data = $s->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Statements - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.dataTables.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <!-- jsPDF and jsPDF-AutoTable CDNs -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <style>
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        .tab-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 600;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 6px;
            transition: all var(--transition-speed);
        }
        .tab-btn:hover {
            color: var(--text-light);
            background-color: var(--bg-secondary);
        }
        .tab-btn.active {
            color: white;
            background-color: var(--primary);
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .statement-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            margin-top: 20px;
        }
    </style>
</head>
<body>

    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Panel -->
        <div class="main-content">
            <!-- Mobile Menu -->
            <?php include 'mobile-menu.php'; ?>
            
            <!-- Navbar -->
            <div class="navbar">
                <div class="page-title">Reports & Financial Statements</div>
                <div class="nav-actions">
                    <a href="?toggle_theme=1" class="nav-btn" title="Toggle Theme">
                        <i class="fa-solid <?= ($_SESSION['theme'] === 'light') ? 'fa-moon' : 'fa-sun' ?>"></i>
                    </a>
                </div>
            </div>
            
            <!-- Content Body -->
            <div class="content-body">
                <!-- Date Filters Box -->
                <div class="filter-card">
                    <form action="reports.php" method="GET">
                        <!-- Keep active tab query parameter -->
                        <input type="hidden" name="tab" id="activeTabParam" value="<?= clean($_GET['tab'] ?? 'ledger') ?>">
                        
                        <div class="filter-grid">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" style="padding-left:15px;" value="<?= clean($start_date) ?>">
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" style="padding-left:15px;" value="<?= clean($end_date) ?>">
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Account Filter</label>
                                <select name="account_id" class="form-control" style="padding-left:15px;">
                                    <option value="all" <?= ($filter_account === 'all') ? 'selected' : '' ?>>All Accounts</option>
                                    <?php foreach ($accounts_list as $a): ?>
                                        <option value="<?= $a['id'] ?>" <?= ($filter_account == $a['id']) ? 'selected' : '' ?>><?= clean($a['account_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Transaction Type</label>
                                <select name="type" class="form-control" style="padding-left:15px;">
                                    <option value="all" <?= ($filter_type === 'all') ? 'selected' : '' ?>>All Types</option>
                                    <option value="income" <?= ($filter_type === 'income') ? 'selected' : '' ?>>Income only</option>
                                    <option value="expense" <?= ($filter_type === 'expense') ? 'selected' : '' ?>>Expenses only</option>
                                    <option value="transfer" <?= ($filter_type === 'transfer') ? 'selected' : '' ?>>Transfers only</option>
                                </select>
                            </div>
                            
                            <div>
                                <button type="submit" class="btn-primary btn-filter">
                                    <i class="fa-solid fa-filter"></i> Apply Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- KPI cards showing current filtered totals -->
                <div class="kpi-grid">
                    <div class="kpi-card kpi-income">
                        <div class="kpi-details">
                            <h3>Filtered Income</h3>
                            <div class="kpi-value"><?= format_currency($total_income) ?></div>
                        </div>
                        <div class="kpi-icon"><i class="fa-solid fa-circle-arrow-down"></i></div>
                    </div>
                    <div class="kpi-card kpi-expense">
                        <div class="kpi-details">
                            <h3>Filtered Expense</h3>
                            <div class="kpi-value"><?= format_currency($total_expense) ?></div>
                        </div>
                        <div class="kpi-icon"><i class="fa-solid fa-circle-arrow-up"></i></div>
                    </div>
                    <div class="kpi-card kpi-profit">
                        <div class="kpi-details">
                            <h3>Net Margin</h3>
                            <div class="kpi-value" style="color: <?= ($net_balance >= 0) ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= format_currency($net_balance) ?>
                            </div>
                        </div>
                        <div class="kpi-icon" style="background-color: <?= ($net_balance >= 0) ? 'var(--success-light)' : 'var(--danger-light)' ?>; color: <?= ($net_balance >= 0) ? 'var(--success)' : 'var(--danger)' ?>;"><i class="fa-solid fa-wallet"></i></div>
                    </div>
                </div>
                
                <!-- Menu tabs for reports -->
                <div class="tabs">
                    <button class="tab-btn" onclick="switchTab('ledger')" id="tabBtn-ledger">General Ledger</button>
                    <button class="tab-btn" onclick="switchTab('pandl')" id="tabBtn-pandl">Profit & Loss Breakdown</button>
                    <button class="tab-btn" onclick="switchTab('statements')" id="tabBtn-statements">Account Statement</button>
                </div>
                
                <!-- TAB 1: GENERAL LEDGER -->
                <div class="tab-content" id="tabContent-ledger">
                    <div class="table-card">
                        <div class="dashboard-card-header" style="border:none; margin-bottom:10px;">
                            <h2>Consolidated General Ledger</h2>
                            <div style="display:flex; gap:8px;">
                                <a href="?export=csv&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&account_id=<?= $filter_account ?>&type=<?= $filter_type ?>" class="btn-primary" style="padding: 8px 16px; font-size: 0.85rem; margin:0;">
                                    <i class="fa-solid fa-file-csv"></i> Export CSV
                                </a>
                                <button type="button" onclick="exportToPDF()" class="btn-primary" style="padding: 8px 16px; font-size: 0.85rem; margin:0; background:#ef4444; border-color:#ef4444;">
                                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="custom-table" id="generalLedgerTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Account</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // General union query for display
                                    $params = [$start_date, $end_date];
                                    
                                    $income_q = "SELECT 'income' as type, i.income_date as txn_date, i.title, i.category, a.account_name, i.payment_method, i.amount, i.created_at FROM income i JOIN bank_accounts a ON i.account_id = a.id WHERE i.income_date BETWEEN ? AND ?";
                                    if ($filter_account !== 'all') { $income_q .= " AND i.account_id = ?"; }
                                    
                                    $expense_q = "SELECT 'expense' as type, e.expense_date as txn_date, e.title, e.category, a.account_name, e.payment_method, e.amount, e.created_at FROM expenses e JOIN bank_accounts a ON e.account_id = a.id WHERE e.expense_date BETWEEN ? AND ?";
                                    if ($filter_account !== 'all') { $expense_q .= " AND e.account_id = ?"; }
                                    
                                    $transfer_q = "SELECT 'transfer' as type, t.transfer_date as txn_date, CONCAT('Transfer to ', a_to.account_name) as title, 'Transfer' as category, a_from.account_name, 'Bank' as payment_method, t.amount, t.created_at FROM transfers t JOIN bank_accounts a_from ON t.from_account = a_from.id JOIN bank_accounts a_to ON t.to_account = a_to.id WHERE t.transfer_date BETWEEN ? AND ?";
                                    if ($filter_account !== 'all') { $transfer_q .= " AND (t.from_account = ? OR t.to_account = ?)"; }
                                    
                                    $queries = [];
                                    $inc_p = $params; $exp_p = $params; $tf_p = $params;
                                    if ($filter_account !== 'all') {
                                        $inc_p[] = $filter_account;
                                        $exp_p[] = $filter_account;
                                        $tf_p[] = $filter_account;
                                        $tf_p[] = $filter_account;
                                    }
                                    
                                    if ($filter_type === 'all' || $filter_type === 'income') { $queries[] = ["sql" => $income_q, "params" => $inc_p]; }
                                    if ($filter_type === 'all' || $filter_type === 'expense') { $queries[] = ["sql" => $expense_q, "params" => $exp_p]; }
                                    if ($filter_type === 'all' || $filter_type === 'transfer') { $queries[] = ["sql" => $transfer_q, "params" => $tf_p]; }
                                    
                                    if (count($queries) > 0) {
                                        $union_parts = [];
                                        $union_params = [];
                                        foreach ($queries as $q) {
                                            $union_parts[] = "(" . $q['sql'] . ")";
                                            foreach ($q['params'] as $p) { $union_params[] = $p; }
                                        }
                                        $union_sql = implode(" UNION ALL ", $union_parts) . " ORDER BY txn_date DESC, created_at DESC";
                                        
                                        $stmt = $pdo->prepare($union_sql);
                                        $stmt->execute($union_params);
                                        $ledger_entries = $stmt->fetchAll();
                                        
                                        foreach ($ledger_entries as $row) {
                                            $badgeClass = 'badge-success';
                                            $amtClass = 'color: var(--success);';
                                            $prefix = '+';
                                            $badgeStyle = "background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25);";
                                            $amtClass = 'color: var(--success);';
                                            $prefix = '+';
                                            if ($row['type'] === 'expense') {
                                                $badgeStyle = "background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25);";
                                                $amtClass = 'color: var(--danger);';
                                                $prefix = '-';
                                            } elseif ($row['type'] === 'transfer') {
                                                $badgeStyle = "background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.25);";
                                                $amtClass = 'color: var(--info);';
                                                $prefix = '';
                                            }
                                            echo "<tr>
                                                    <td>" . clean(date('d M Y', strtotime($row['txn_date']))) . "</td>
                                                    <td><span class='badge' style='{$badgeStyle}'>" . strtoupper(clean($row['type'])) . "</span></td>
                                                    <td style='font-weight:600; color:var(--text-light);'>" . clean($row['title']) . "</td>
                                                    <td>" . get_category_badge($row['category']) . "</td>
                                                    <td>" . clean($row['account_name']) . "</td>
                                                    <td><span style='text-transform:uppercase; font-size:0.8rem;'>" . clean($row['payment_method']) . "</span></td>
                                                    <td style='font-weight:700; {$amtClass}'>{$prefix}" . format_currency($row['amount']) . "</td>
                                                  </tr>";
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- TAB 2: PROFIT & LOSS BREAKDOWN -->
                <div class="tab-content" id="tabContent-pandl">
                    <div class="module-grid">
                        <!-- Category Wise Income -->
                        <div class="table-card">
                            <div class="form-card-title">Income Categories Aggregate</div>
                            <div class="table-responsive">
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Logs Count</th>
                                            <th>Total Revenue</th>
                                            <th>% Shares</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($income_categories) === 0): ?>
                                            <tr><td colspan="4" style="text-align:center;">No income entries logged for dates.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($income_categories as $c): ?>
                                                <?php $pct = ($total_income > 0) ? ($c['total'] / $total_income) * 100 : 0; ?>
                                                <tr>
                                                    <td style="font-weight:600; color:var(--text-light);"><?= clean($c['category']) ?></td>
                                                    <td><?= $c['count'] ?></td>
                                                    <td style="font-weight:700; color:var(--success);"><?= format_currency($c['total']) ?></td>
                                                    <td><?= number_format($pct, 1) ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Category Wise Expense -->
                        <div class="table-card">
                            <div class="form-card-title">Expense Categories Aggregate</div>
                            <div class="table-responsive">
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Logs Count</th>
                                            <th>Total Cost</th>
                                            <th>% Shares</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($expense_categories) === 0): ?>
                                            <tr><td colspan="4" style="text-align:center;">No expense entries logged for dates.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($expense_categories as $c): ?>
                                                <?php $pct = ($total_expense > 0) ? ($c['total'] / $total_expense) * 100 : 0; ?>
                                                <tr>
                                                    <td style="font-weight:600; color:var(--text-light);"><?= clean($c['category']) ?></td>
                                                    <td><?= $c['count'] ?></td>
                                                    <td style="font-weight:700; color:var(--danger);"><?= format_currency($c['total']) ?></td>
                                                    <td><?= number_format($pct, 1) ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- TAB 3: ACCOUNT RUNNING STATEMENT -->
                <div class="tab-content" id="tabContent-statements">
                    <div class="table-card">
                        <div class="form-card-title" style="border:none; margin-bottom: 10px;">
                            <span>Generate Account Statement</span>
                        </div>
                        
                        <!-- Statement Select Account Form -->
                        <form action="reports.php" method="GET" style="margin-bottom: 30px;">
                            <!-- Pass active tab and dates -->
                            <input type="hidden" name="tab" value="statements">
                            <input type="hidden" name="start_date" value="<?= clean($start_date) ?>">
                            <input type="hidden" name="end_date" value="<?= clean($end_date) ?>">
                            
                            <div style="display:flex; gap: 15px; align-items: flex-end;">
                                <div class="form-group" style="margin-bottom:0; flex-grow: 1;">
                                    <label class="form-label">Select Bank Account</label>
                                    <select name="stmt_account_id" class="form-control" style="padding-left:15px;" required>
                                        <option value="" disabled selected>-- Choose Account --</option>
                                        <?php foreach ($accounts_list as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= ($statement_acc_id == $acc['id']) ? 'selected' : '' ?>>
                                                <?= clean($acc['account_name']) ?> (<?= clean($acc['bank_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary" style="width: auto; padding: 12px 24px;">
                                    <i class="fa-solid fa-receipt"></i> Generate Statement
                                </button>
                            </div>
                        </form>
                        
                        <!-- Print statement sheet -->
                        <?php if ($stmt_account): ?>
                            <div class="statement-card" id="printableStatement">
                                <div style="display:flex; justify-content:space-between; margin-bottom: 25px; border-bottom:1px solid var(--border-color); padding-bottom:15px;">
                                    <div>
                                        <h3 style="color:var(--text-light);"><?= clean($stmt_account['account_name']) ?></h3>
                                        <p style="color:var(--text-secondary); font-size:0.85rem;">
                                            <?= clean($stmt_account['bank_name']) ?> • Acc No: <?= clean($stmt_account['account_number']) ?>
                                            <?php if (!empty($stmt_account['branch_name'])): ?> • Branch: <?= clean($stmt_account['branch_name']) ?><?php endif; ?>
                                            <?php if (!empty($stmt_account['ifsc_code'])): ?> • IFSC: <?= clean($stmt_account['ifsc_code']) ?><?php endif; ?>
                                        </p>
                                    </div>
                                    <div style="text-align:right;">
                                        <h4 style="color:var(--text-light);">Account Statement</h4>
                                        <p style="color:var(--text-secondary); font-size:0.8rem;"><?= clean(date('d M Y', strtotime($start_date))) ?> to <?= clean(date('d M Y', strtotime($end_date))) ?></p>
                                    </div>
                                </div>
                                
                                <div style="display:flex; justify-content:space-between; margin-bottom: 20px; font-size: 0.9rem;">
                                    <span>Opening Balance (as of <?= clean(date('d M Y', strtotime($start_date))) ?>):</span>
                                    <strong style="color: var(--text-light);"><?= format_currency($opening_balance) ?></strong>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="custom-table" style="margin-top: 10px;">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Ref/Method</th>
                                                <th>Particulars / Description</th>
                                                <th>Type</th>
                                                <th style="text-align:right;">Amount</th>
                                                <th style="text-align:right;">Running Balance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $running_bal = $opening_balance;
                                            if (count($statement_data) === 0): 
                                            ?>
                                                <tr><td colspan="6" style="text-align:center;">No ledger logs recorded for dates.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($statement_data as $row): ?>
                                                    <?php 
                                                    $running_bal += (float)$row['amount'];
                                                    $is_credit = ($row['type'] === 'income' || $row['type'] === 'transfer_to');
                                                    $badgeStyle = $is_credit 
                                                        ? "background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25);" 
                                                        : "background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25);";
                                                    $amt_class = 'color: var(--success); text-align:right; font-weight:700;';
                                                    $sign = '+';
                                                    
                                                    if ($row['type'] === 'expense' || $row['type'] === 'transfer_from') {
                                                        $amt_class = 'color: var(--danger); text-align:right; font-weight:700;';
                                                        $sign = '-';
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?= clean(date('d M Y', strtotime($row['txn_date']))) ?></td>
                                                        <td>
                                                            <div style="font-size:0.85rem; text-transform:uppercase;"><?= clean($row['reference_no'] ?: 'Direct') ?></div>
                                                        </td>
                                                        <td>
                                                            <div style="font-weight:600; color:var(--text-light);"><?= clean($row['title']) ?></div>
                                                            <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($row['detail']) ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge" style="<?= $badgeStyle ?>">
                                                                <?= $is_credit ? 'CREDIT' : 'DEBIT' ?>
                                                            </span>
                                                        </td>
                                                        <td style="<?= $amt_class ?>"><?= $sign . format_currency(abs($row['amount'])) ?></td>
                                                        <td style="text-align:right; font-weight:700; color:var(--text-light);"><?= format_currency($running_bal) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div style="display:flex; justify-content:space-between; margin-top:20px; font-size:1.1rem; border-top:1px solid var(--border-color); padding-top:15px;">
                                    <span>Closing Balance (as of <?= clean(date('d M Y', strtotime($end_date))) ?>):</span>
                                    <strong style="color:var(--text-light);"><?= format_currency($running_bal) ?></strong>
                                </div>
                                
                                <div style="text-align:right; margin-top:30px;">
                                    <button class="btn-primary" onclick="window.print()" style="width:auto; padding: 10px 20px;">
                                        <i class="fa-solid fa-print"></i> Print Statement
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Tab switching engine
    function switchTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        
        document.getElementById('tabBtn-' + tabId).classList.add('active');
        document.getElementById('tabContent-' + tabId).classList.add('active');
        
        document.getElementById('activeTabParam').value = tabId;
        
        // Push tab status in URL
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);
    }
    
    function exportToPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'pt', 'a4');
        
        doc.setFont("helvetica", "bold");
        doc.setFontSize(20);
        doc.setTextColor(99, 102, 241);
        doc.text("IEMS ERP - Consolidated General Ledger", 40, 50);
        
        doc.setFont("helvetica", "normal");
        doc.setFontSize(9);
        doc.setTextColor(100, 116, 139);
        doc.text("Generated on: " + new Date().toLocaleString(), 40, 68);
        doc.text("Period: <?= date('d M Y', strtotime($start_date)) ?> to <?= date('d M Y', strtotime($end_date)) ?>", 40, 80);
        
        doc.autoTable({
            html: '#generalLedgerTable',
            startY: 100,
            theme: 'grid',
            headStyles: { fillColor: [99, 102, 241], fontStyle: 'bold' },
            styles: { fontSize: 8, cellPadding: 6 },
            columns: [0, 1, 2, 3, 4, 5, 6],
            didParseCell: function(data) {
                if (data.cell && data.cell.text) {
                    for (let i = 0; i < data.cell.text.length; i++) {
                        data.cell.text[i] = data.cell.text[i].replace(/₹/g, 'Rs. ');
                    }
                }
            }
        });
        
        doc.save("IEMS_Ledger_Report_" + new Date().toISOString().slice(0,10) + ".pdf");
    }

    $(document).ready(function() {
        // Load active tab on page load
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab') || 'ledger';
        switchTab(activeTab);
        
        $('#generalLedgerTable').DataTable({
            order: [[0, 'desc']],
            responsive: true
        });
    });
    </script>
</body>
</html>
