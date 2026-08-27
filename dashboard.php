<?php
// Dashboard page for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

$active_page = 'dashboard';

// Fetch Current Month Income
$current_month = date('Y-m');
$stmt = $pdo->prepare("
    SELECT i.amount, a.currency 
    FROM income i
    JOIN bank_accounts a ON i.account_id = a.id
    WHERE DATE_FORMAT(i.income_date, '%Y-%m') = ?
");
$stmt->execute([$current_month]);
$total_income_this_month = 0.00;
while ($row = $stmt->fetch()) {
    $total_income_this_month += convert_currency($row['amount'], $row['currency'], 'INR');
}

// Fetch Current Month Expense
$stmt = $pdo->prepare("
    SELECT e.amount, a.currency 
    FROM expenses e
    JOIN bank_accounts a ON e.account_id = a.id
    WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = ?
");
$stmt->execute([$current_month]);
$total_expense_this_month = 0.00;
while ($row = $stmt->fetch()) {
    $total_expense_this_month += convert_currency($row['amount'], $row['currency'], 'INR');
}

// Net Profit / Loss
$net_profit = $total_income_this_month - $total_expense_this_month;

// Total Bank Balance
$stmt = $pdo->query("SELECT current_balance, currency FROM bank_accounts WHERE status = 'active'");
$total_bank_balance = 0.00;
while ($row = $stmt->fetch()) {
    $total_bank_balance += convert_currency($row['current_balance'], $row['currency'], 'INR');
}

// Fetch total outstanding borrowed loans (Liability)
$total_loans_outstanding = 0.00;
try {
    $stmt = $pdo->query("SELECT emi_amount, tenure_months, total_paid, status FROM loans");
    while ($row = $stmt->fetch()) {
        if ($row['status'] === 'active') {
            $total_payable = (float)$row['emi_amount'] * (int)$row['tenure_months'];
            $total_loans_outstanding += max(0, $total_payable - (float)$row['total_paid']);
        }
    }
} catch (PDOException $e) {
    $total_loans_outstanding = 0.00;
}

// Fetch total outstanding given/lent loans (Asset)
$total_given_outstanding = 0.00;
try {
    $stmt = $pdo->query("SELECT emi_amount, tenure_months, total_paid, status FROM loans_given");
    while ($row = $stmt->fetch()) {
        if ($row['status'] === 'active') {
            $total_payable = (float)$row['emi_amount'] * (int)$row['tenure_months'];
            $total_given_outstanding += max(0, $total_payable - (float)$row['total_paid']);
        }
    }
} catch (PDOException $e) {
    $total_given_outstanding = 0.00;
}

// Recent Transactions (Last 10)
$recent_tx_query = "
    (SELECT 'income' as type, id, title, amount, category, payment_method, reference_no, income_date as txn_date, created_at 
     FROM income)
    UNION ALL
    (SELECT 'expense' as type, id, title, amount, category, payment_method, reference_no, expense_date as txn_date, created_at 
     FROM expenses)
    UNION ALL
    (SELECT 'transfer' as type, id, CONCAT('Transfer to ', (SELECT account_name FROM bank_accounts WHERE id = to_account)) as title, amount, 'Transfer' as category, 'bank' as payment_method, '' as reference_no, transfer_date as txn_date, created_at 
     FROM transfers)
    ORDER BY txn_date DESC, created_at DESC
    LIMIT 10
";
$recent_transactions = $pdo->query($recent_tx_query)->fetchAll();

// Chart Data: Last 6 Months Income vs Expense
$chart_months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chart_months[$m] = [
        'label' => date('M Y', strtotime("-$i months")),
        'income' => 0,
        'expense' => 0
    ];
}

// Fetch 6-month income summary
$stmt = $pdo->query("
    SELECT DATE_FORMAT(i.income_date, '%Y-%m') as month_str, i.amount, a.currency 
    FROM income i
    JOIN bank_accounts a ON i.account_id = a.id
    WHERE i.income_date >= DATE_SUB(LAST_DAY(NOW()), INTERVAL 6 MONTH)
");
while ($row = $stmt->fetch()) {
    $m = $row['month_str'];
    if (isset($chart_months[$m])) {
        $chart_months[$m]['income'] += convert_currency($row['amount'], $row['currency'], 'INR');
    }
}

// Fetch 6-month expense summary
$stmt = $pdo->query("
    SELECT DATE_FORMAT(e.expense_date, '%Y-%m') as month_str, e.amount, a.currency 
    FROM expenses e
    JOIN bank_accounts a ON e.account_id = a.id
    WHERE e.expense_date >= DATE_SUB(LAST_DAY(NOW()), INTERVAL 6 MONTH)
");
while ($row = $stmt->fetch()) {
    $m = $row['month_str'];
    if (isset($chart_months[$m])) {
        $chart_months[$m]['expense'] += convert_currency($row['amount'], $row['currency'], 'INR');
    }
}

// Prepare JSON arrays for chart script
$labels = [];
$income_data = [];
$expense_data = [];
foreach ($chart_months as $m => $data) {
    $labels[] = $data['label'];
    $income_data[] = $data['income'];
    $expense_data[] = $data['expense'];
}

// 1. Daily Income/Expense for current month
$current_year_month = date('Y-m');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'));
$daily_labels = [];
$daily_income = array_fill(1, $days_in_month, 0);
$daily_expense = array_fill(1, $days_in_month, 0);

for ($d = 1; $d <= $days_in_month; $d++) {
    $daily_labels[] = sprintf('%02d %s', $d, date('M'));
}

// Fetch daily income totals
$stmt = $pdo->prepare("
    SELECT DAY(i.income_date) as day_num, i.amount, a.currency 
    FROM income i
    JOIN bank_accounts a ON i.account_id = a.id
    WHERE DATE_FORMAT(i.income_date, '%Y-%m') = ?
");
$stmt->execute([$current_year_month]);
while ($row = $stmt->fetch()) {
    $daily_income[(int)$row['day_num']] += convert_currency($row['amount'], $row['currency'], 'INR');
}

// Fetch daily expense totals
$stmt = $pdo->prepare("
    SELECT DAY(e.expense_date) as day_num, e.amount, a.currency 
    FROM expenses e
    JOIN bank_accounts a ON e.account_id = a.id
    WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = ?
");
$stmt->execute([$current_year_month]);
while ($row = $stmt->fetch()) {
    $daily_expense[(int)$row['day_num']] += convert_currency($row['amount'], $row['currency'], 'INR');
}

$daily_income_data = array_values($daily_income);
$daily_expense_data = array_values($daily_expense);

// 2. Given Loans Collection progress
$loan_given_collected = 0.00;
$loan_given_outstanding = 0.00;
try {
    $stmt = $pdo->query("SELECT emi_amount, tenure_months, total_paid, status FROM loans_given");
    while ($row = $stmt->fetch()) {
        $total_payable = (float)$row['emi_amount'] * (int)$row['tenure_months'];
        $paid = (float)$row['total_paid'];
        $loan_given_collected += $paid;
        if ($row['status'] === 'active') {
            $loan_given_outstanding += max(0, $total_payable - $paid);
        }
    }
} catch (PDOException $e) {}

$total_collection_target = $loan_given_collected + $loan_given_outstanding;
$collection_pct = $total_collection_target > 0 ? round(($loan_given_collected / $total_collection_target) * 100) : 0;

// 3. Top Expense Categories
$expense_categories = [];
$stmt = $pdo->query("
    SELECT e.category, e.amount, a.currency 
    FROM expenses e
    JOIN bank_accounts a ON e.account_id = a.id
");
$cat_map = [];
while ($row = $stmt->fetch()) {
    $cat = $row['category'];
    $amt = convert_currency($row['amount'], $row['currency'], 'INR');
    if (!isset($cat_map[$cat])) {
        $cat_map[$cat] = 0.00;
    }
    $cat_map[$cat] += $amt;
}
arsort($cat_map);
$cat_map = array_slice($cat_map, 0, 5, true);
$cat_labels = array_keys($cat_map);
$cat_totals = array_values($cat_map);

// 4. Recent Activity Logs (Last 6)
$recent_activities = [];
try {
    $stmt = $pdo->query("
        SELECT log_text, ip_address, created_at 
        FROM activity_logs 
        ORDER BY created_at DESC 
        LIMIT 6
    ");
    $recent_activities = $stmt->fetchAll();
} catch (PDOException $e) {}

// Relative time elapsed helper function
if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'min',
            's' => 'sec',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}

$active_accounts_count = 0;
try {
    $active_accounts_count = (int)$pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE status = 'active'")->fetchColumn();
} catch (PDOException $e) {
    $active_accounts_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        .dashboard-analytics-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .dashboard-analytics-row-3col {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 1024px) {
            .dashboard-analytics-row, .dashboard-analytics-row-3col {
                grid-template-columns: 1fr !important;
            }
        }
        .chart-stats-panel {
            width: 160px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            border-left: 1px solid var(--border-color);
            padding-left: 20px;
            flex-shrink: 0;
        }
        .chart-stat-item label {
            display: block;
            font-size: 0.72rem;
            color: var(--text-secondary);
            margin-bottom: 4px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .chart-stat-item .val {
            font-size: 1.15rem;
            font-weight: 800;
        }
    </style>
</head>
<body>

    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Panel Content -->
        <div class="main-content">
            <!-- Mobile Menu Topbar -->
            <?php include 'mobile-menu.php'; ?>
            
            <!-- Desktop Header Navbar -->
            <div class="navbar">
                <div class="page-title">Financial Dashboard</div>
                <div class="nav-actions">
                    <a href="?toggle_theme=1" class="nav-btn" title="Toggle Theme">
                        <i class="fa-solid <?= ($_SESSION['theme'] === 'light') ? 'fa-moon' : 'fa-sun' ?>"></i>
                    </a>
                    <span style="font-size: 0.9rem; color: var(--text-secondary);">
                        Session: <strong><?= clean($_SESSION['user_role']) ?></strong>
                    </span>
                </div>
            </div>
            
            <!-- Page Content Body -->
            <div class="content-body">
                <!-- Flash Messages -->
                <?php display_flash_message(); ?>
                
                <!-- KPI Summary cards grid -->
                <div class="kpi-grid">
                    <div class="kpi-card kpi-income">
                        <div class="kpi-details">
                            <h3>Income (This Month)</h3>
                            <div class="kpi-value"><?= format_currency($total_income_this_month) ?></div>
                        </div>
                        <div class="kpi-icon">
                            <i class="fa-solid fa-circle-arrow-down"></i>
                        </div>
                    </div>
                    
                    <div class="kpi-card kpi-expense">
                        <div class="kpi-details">
                            <h3>Expense (This Month)</h3>
                            <div class="kpi-value"><?= format_currency($total_expense_this_month) ?></div>
                        </div>
                        <div class="kpi-icon">
                            <i class="fa-solid fa-circle-arrow-up"></i>
                        </div>
                    </div>
                    
                    <div class="kpi-card kpi-profit">
                        <div class="kpi-details">
                            <h3>Net Margin</h3>
                            <div class="kpi-value" style="color: <?= ($net_profit >= 0) ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= format_currency($net_profit) ?>
                            </div>
                        </div>
                        <div class="kpi-icon" style="background-color: <?= ($net_profit >= 0) ? 'var(--success-light)' : 'var(--danger-light)' ?>; color: <?= ($net_profit >= 0) ? 'var(--success)' : 'var(--danger)' ?>;">
                            <i class="fa-solid <?= ($net_profit >= 0) ? 'fa-chart-line' : 'fa-chart-line-down' ?>"></i>
                        </div>
                    </div>
                    
                    <div class="kpi-card kpi-balance">
                        <div class="kpi-details">
                            <h3>Available Balance</h3>
                            <div class="kpi-value"><?= format_currency($total_bank_balance) ?></div>
                        </div>
                        <div class="kpi-icon">
                            <i class="fa-solid fa-building-columns"></i>
                        </div>
                    </div>
                    
                    <div class="kpi-card kpi-loan" style="background: linear-gradient(135deg, #78350f, #f59e0b); cursor: pointer;" onclick="window.location='loans.php'">
                        <div class="kpi-details">
                            <h3>Loan Outstanding</h3>
                            <div class="kpi-value"><?= format_currency($total_loans_outstanding) ?></div>
                        </div>
                        <div class="kpi-icon">
                            <i class="fa-solid fa-landmark" style="color: white; opacity: 0.85;"></i>
                        </div>
                    </div>

                    <div class="kpi-card kpi-lent" style="background: linear-gradient(135deg, #581c87, #8b5cf6); cursor: pointer;" onclick="window.location='loans-given.php'">
                        <div class="kpi-details">
                            <h3>Lent Receivables</h3>
                            <div class="kpi-value"><?= format_currency($total_given_outstanding) ?></div>
                        </div>
                        <div class="kpi-icon">
                            <i class="fa-solid fa-hand-holding-hand" style="color: white; opacity: 0.85;"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Dashboard Analytics Layout -->
                <div class="dashboard-main-layout" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
                    
                    <!-- Left Area (80% width) -->
                    <div style="flex: 4; min-width: 300px; display: flex; flex-direction: column; gap: 20px;">
                        
                        <!-- Left Row 1: Line Chart & Donut Chart -->
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <!-- Financial Overview Line Chart -->
                            <div class="dashboard-card" style="flex: 2; min-width: 250px; display: flex; flex-direction: column;">
                                <div class="dashboard-card-header">
                                    <h2>Financial Overview (This Month)</h2>
                                    <span class="header-action"><?= date('F Y') ?></span>
                                </div>
                                <div style="display: flex; flex-direction: row; gap: 20px; flex: 1; align-items: center; min-height: 230px; flex-wrap: wrap; padding: 10px 0;">
                                    <div style="flex: 1; min-width: 250px; position: relative; height: 210px;">
                                        <canvas id="dailyLineChart"></canvas>
                                    </div>
                                    <div class="chart-stats-panel">
                                        <div class="chart-stat-item">
                                            <label>Total Income</label>
                                            <div class="val" style="color: var(--success);"><?= format_currency($total_income_this_month) ?></div>
                                        </div>
                                        <div class="chart-stat-item">
                                            <label>Total Expense</label>
                                            <div class="val" style="color: var(--danger);"><?= format_currency($total_expense_this_month) ?></div>
                                        </div>
                                        <div class="chart-stat-item">
                                            <label>Net Balance</label>
                                            <div class="val" style="color: <?= ($net_profit >= 0) ? 'var(--success)' : 'var(--danger)' ?>;"><?= format_currency($net_profit) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Overall Fee Collection Position Donut -->
                            <div class="dashboard-card" style="flex: 1.1; min-width: 200px; display: flex; flex-direction: column;">
                                <div class="dashboard-card-header">
                                    <h2>Overall collection position</h2>
                                    <span class="header-action">Budget Position</span>
                                </div>
                                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; padding: 10px 0;">
                                    <div style="width: 130px; height: 130px; position: relative; margin-bottom: 12px;">
                                        <canvas id="collectionDonutChart"></canvas>
                                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none;">
                                            <span style="font-size: 1.25rem; font-weight: 800; color: var(--text-light);"><?= $collection_pct ?>%</span>
                                            <div style="font-size: 0.6rem; color: var(--text-secondary); text-transform: uppercase;">Collected</div>
                                        </div>
                                    </div>
                                    <div style="width: 100%; display: flex; flex-direction: column; gap: 4px; font-size: 0.75rem;">
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="color: var(--text-secondary);"><i class="fa-solid fa-circle" style="color: var(--success); margin-right: 5px; font-size: 0.6rem;"></i> Collected</span>
                                            <span style="font-weight: 700; color: var(--text-light);"><?= format_currency($loan_given_collected) ?></span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="color: var(--text-secondary);"><i class="fa-solid fa-circle" style="color: var(--warning); margin-right: 5px; font-size: 0.6rem;"></i> Outstanding</span>
                                            <span style="font-weight: 700; color: var(--text-light);"><?= format_currency($loan_given_outstanding) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Left Row 2: Bar Chart, Donut Chart, Recent Ledger Entries -->
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <!-- Monthly Bar Chart -->
                            <div class="dashboard-card" style="flex: 1; min-width: 200px; display: flex; flex-direction: column;">
                                <div class="dashboard-card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <h2>Income vs Expense</h2>
                                    <button onclick="toggleForecast()" id="forecastBtn" class="btn-secondary" style="font-size:0.75rem; padding:4px 8px; margin:0; border:1px solid var(--border-color); background:transparent; display:flex; align-items:center; gap:4px; cursor:pointer;">
                                        <i class="fa-solid fa-chart-line"></i> Show Forecast
                                    </button>
                                </div>
                                <div style="flex: 1; min-height: 200px; position: relative; padding: 10px 0;">
                                    <canvas id="monthlyBarChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Top Expense Categories Donut -->
                            <div class="dashboard-card" style="flex: 1; min-width: 200px; display: flex; flex-direction: column;">
                                <div class="dashboard-card-header">
                                    <h2>Top Expense Categories</h2>
                                    <span class="header-action">All Time</span>
                                </div>
                                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; padding: 10px 0;">
                                    <?php if (empty($cat_labels)): ?>
                                        <div style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                                            <i class="fa-solid fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                                            No expenses recorded
                                        </div>
                                    <?php else: ?>
                                        <div style="width: 110px; height: 110px; position: relative; margin-bottom: 12px;">
                                            <canvas id="categoryPieChart"></canvas>
                                        </div>
                                        <div style="width: 100%; display: flex; flex-direction: column; gap: 5px; font-size: 0.75rem; max-height: 90px; overflow-y: auto;">
                                            <?php 
                                            $colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
                                            foreach ($cat_labels as $idx => $label): 
                                                $col = $colors[$idx % count($colors)];
                                            ?>
                                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                                    <span style="color: var(--text-secondary);"><i class="fa-solid fa-circle" style="color: <?= $col ?>; margin-right: 5px; font-size: 0.6rem;"></i> <?= clean($label) ?></span>
                                                    <span style="font-weight: 700; color: var(--text-light);"><?= format_currency($cat_totals[$idx]) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Recent Ledger Entries -->
                            <div class="dashboard-card" style="flex: 1.2; min-width: 220px; display: flex; flex-direction: column;">
                                <div class="dashboard-card-header">
                                    <h2>Recent Ledger Entries</h2>
                                    <a href="reports.php" class="header-action" style="font-weight: 500;">View All</a>
                                </div>
                                <div class="mini-list" style="flex: 1; overflow-y: auto; max-height: 250px; display: flex; flex-direction: column; gap: 4px; padding: 10px 0;">
                                    <?php if (count($recent_transactions) === 0): ?>
                                        <div style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                                            <i class="fa-solid fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                                            No transactions found
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_transactions as $tx): ?>
                                            <?php 
                                            $itemClass = 'mini-income';
                                            $itemIcon = 'fa-arrow-down';
                                            $prefix = '+';
                                            if ($tx['type'] === 'expense') {
                                                $itemClass = 'mini-expense';
                                                $itemIcon = 'fa-arrow-up';
                                                $prefix = '-';
                                            } elseif ($tx['type'] === 'transfer') {
                                                $itemClass = 'mini-transfer';
                                                $itemIcon = 'fa-right-left';
                                                $prefix = '';
                                            }
                                            ?>
                                            <div class="mini-item <?= $itemClass ?>" style="padding: 8px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); margin-bottom: 2px;">
                                                <div class="mini-info" style="display: flex; align-items: center; gap: 10px;">
                                                    <div class="mini-icon" style="width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; background: var(--bg-primary); border: 1px solid var(--border-color);">
                                                        <i class="fa-solid <?= $itemIcon ?>"></i>
                                                    </div>
                                                    <div class="mini-details">
                                                        <h4 style="margin: 0; font-size: 0.8rem; color: var(--text-light); font-weight: 600; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 100px;"><?= clean($tx['title']) ?></h4>
                                                        <span style="font-size: 0.68rem; color: var(--text-secondary);"><?= clean(date('d M', strtotime($tx['txn_date']))) ?></span>
                                                    </div>
                                                </div>
                                                <div class="mini-amount" style="font-size: 0.8rem; font-weight: 700;"><?= $prefix . format_currency($tx['amount']) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Sidebar Area (20% width) -->
                    <div style="flex: 1.2; min-width: 260px; display: flex; flex-direction: column; gap: 20px;">
                        
                        <!-- Shortcuts Panel -->
                        <div class="dashboard-card" style="display: flex; flex-direction: column; padding: 15px;">
                            <div class="dashboard-card-header" style="margin-bottom: 12px;">
                                <h2>Shortcuts</h2>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <a href="accounts.php" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; transition: 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: rgba(16, 185, 129, 0.15); color: #10b981;">
                                            <i class="fa-solid fa-building-columns"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">Bank Accounts</div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary);">Manage accounts & balances</div>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: var(--text-secondary);"></i>
                                </a>
                                
                                <a href="income.php" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; transition: 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); color: #22c55e;">
                                            <i class="fa-solid fa-circle-arrow-down"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">Log Income</div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary);">Record incoming revenues</div>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: var(--text-secondary);"></i>
                                </a>
                                
                                <a href="expense.php" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; transition: 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); color: #ef4444;">
                                            <i class="fa-solid fa-circle-arrow-up"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">Log Expense</div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary);">Record business expenses</div>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: var(--text-secondary);"></i>
                                </a>
                                
                                <a href="loans.php" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; transition: 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                                            <i class="fa-solid fa-landmark"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">Loans Received</div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary);">Borrowed loans list</div>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: var(--text-secondary);"></i>
                                </a>
                                
                                <a href="loans-given.php" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; transition: 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: rgba(168, 85, 247, 0.15); color: #a855f7;">
                                            <i class="fa-solid fa-hand-holding-hand"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">Lent Receivables</div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary);">Track outgoing lent cash</div>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: var(--text-secondary);"></i>
                                </a>
                                
                                <a href="reports.php" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; transition: 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: rgba(14, 165, 233, 0.15); color: #0ea5e9;">
                                            <i class="fa-solid fa-file-invoice-dollar"></i>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">Reports Center</div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary);">Analyze & export statements</div>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: var(--text-secondary);"></i>
                                </a>
                            </div>
                        </div>
                        
                        <!-- Recent Activity Logs -->
                        <div class="dashboard-card" style="display: flex; flex-direction: column; padding: 15px;">
                            <div class="dashboard-card-header" style="margin-bottom: 12px;">
                                <h2>Recent Activity</h2>
                                <a href="activity_logs.php" class="header-action" style="font-weight: 500;">View All</a>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 12px; padding: 10px 0; flex: 1; overflow-y: auto; max-height: 250px;">
                                <?php if (count($recent_activities) === 0): ?>
                                    <div style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                                        <i class="fa-solid fa-bell-slash" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                                        No recent activity recorded
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $act): ?>
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px dashed var(--border-color); padding-bottom: 8px;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <i class="fa-solid fa-circle" style="font-size: 0.4rem; color: var(--primary); flex-shrink: 0;"></i>
                                                <div>
                                                    <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-light); line-height: 1.2;"><?= clean($act['log_text']) ?></div>
                                                    <div style="font-size: 0.65rem; color: var(--text-secondary); margin-top: 2px;">User system log</div>
                                                </div>
                                            </div>
                                            <span style="font-size: 0.68rem; color: var(--text-secondary); flex-shrink: 0;"><?= time_elapsed_string($act['created_at']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Row: 6 KPI Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-top: 20px;">
                    <div class="dashboard-card" style="display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--bg-secondary);">
                        <div style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); color: var(--success); font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-circle-arrow-down"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Total Inflow</div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-light);"><?= format_currency($total_income_this_month) ?></div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card" style="display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--bg-secondary);">
                        <div style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); color: var(--danger); font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-circle-arrow-up"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Total Outflow</div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-light);"><?= format_currency($total_expense_this_month) ?></div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card" style="display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--bg-secondary);">
                        <div style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(59, 130, 246, 0.15); color: #3b82f6; font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Net Margin</div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: <?= ($net_profit >= 0) ? 'var(--success)' : 'var(--danger)' ?>;"><?= format_currency($net_profit) ?></div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card" style="display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--bg-secondary);">
                        <div style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(245, 158, 11, 0.15); color: var(--warning); font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-building-columns"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Bank Accounts</div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-light);"><?= $active_accounts_count ?> Active</div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card" style="display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--bg-secondary);">
                        <div style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(168, 85, 247, 0.15); color: #a855f7; font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-landmark"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Borrowed Loans</div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-light);"><?= format_currency($total_loans_outstanding) ?></div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card" style="display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--bg-secondary);">
                        <div style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(14, 165, 233, 0.15); color: #0ea5e9; font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-hand-holding-hand"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Lent Receivables</div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-light);"><?= format_currency($total_given_outstanding) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Theme-aware Chart initialization script -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const styles = getComputedStyle(document.body);
        const primaryColor = styles.getPropertyValue('--primary').trim();
        const successColor = styles.getPropertyValue('--success').trim();
        const dangerColor = styles.getPropertyValue('--danger').trim();
        const warningColor = styles.getPropertyValue('--warning').trim();
        const textColor = styles.getPropertyValue('--text-secondary').trim();
        const borderColor = styles.getPropertyValue('--border-color').trim();

        // Common Chart.js font options
        const fontOptions = {
            family: 'Inter',
            size: 11
        };

        // 1. Daily Trends Line Chart
        const lineCtx = document.getElementById('dailyLineChart');
        if (lineCtx) {
            new Chart(lineCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?= json_encode($daily_labels) ?>,
                    datasets: [
                        {
                            label: 'Income',
                            data: <?= json_encode($daily_income_data) ?>,
                            borderColor: successColor,
                            backgroundColor: 'rgba(34, 197, 94, 0.05)',
                            fill: true,
                            tension: 0.3,
                            borderWidth: 2
                        },
                        {
                            label: 'Expense',
                            data: <?= json_encode($daily_expense_data) ?>,
                            borderColor: dangerColor,
                            backgroundColor: 'rgba(239, 68, 68, 0.05)',
                            fill: true,
                            tension: 0.3,
                            borderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: textColor, font: fontOptions }
                        },
                        y: {
                            grid: { color: borderColor },
                            ticks: { color: textColor, font: fontOptions }
                        }
                    }
                }
            });
        }

        // 2. Collection Donut Chart
        const donutCtx = document.getElementById('collectionDonutChart');
        if (donutCtx) {
            new Chart(donutCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Collected', 'Outstanding'],
                    datasets: [{
                        data: [<?= $loan_given_collected ?>, <?= $loan_given_outstanding ?>],
                        backgroundColor: [successColor, warningColor],
                        borderWidth: 0,
                        cutout: '75%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }

        // 3. Monthly Summary Bar Chart
        const barCtx = document.getElementById('monthlyBarChart');
        const histLabels = <?= json_encode($labels) ?>;
        const histIncome = <?= json_encode($income_data) ?>;
        const histExpense = <?= json_encode($expense_data) ?>;
        let isForecastActive = false;

        if (barCtx) {
            window.monthlySummaryChart = new Chart(barCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: histLabels,
                    datasets: [
                        {
                            label: 'Income',
                            data: histIncome,
                            backgroundColor: successColor,
                            borderRadius: 6,
                            maxBarThickness: 15
                        },
                        {
                            label: 'Expense',
                            data: histExpense,
                            backgroundColor: dangerColor,
                            borderRadius: 6,
                            maxBarThickness: 15
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: textColor, font: fontOptions }
                        },
                        y: {
                            grid: { color: borderColor },
                            ticks: { color: textColor, font: fontOptions }
                        }
                    }
                }
            });
        }

        function toggleForecast() {
            if (!barCtx) return;
            isForecastActive = !isForecastActive;
            
            const btn = document.getElementById('forecastBtn');
            if (isForecastActive) {
                btn.innerHTML = '<i class="fa-solid fa-chart-bar"></i> Hide Forecast';
                btn.classList.add('active');
                
                const N = histIncome.length;
                const x = Array.from({length: N}, (_, i) => i);
                const meanX = (N - 1) / 2;
                
                function linearForecast(data) {
                    const meanY = data.reduce((a,b) => a+b, 0) / N;
                    let num = 0;
                    let den = 0;
                    for (let i = 0; i < N; i++) {
                        num += (x[i] - meanX) * (data[i] - meanY);
                        den += Math.pow(x[i] - meanX, 2);
                    }
                    const m = den !== 0 ? num / den : 0;
                    const c = meanY - m * meanX;
                    
                    const proj = [];
                    for (let i = 6; i <= 8; i++) {
                        proj.push(Math.max(0, m * i + c));
                    }
                    return proj;
                }
                
                const fcIncome = linearForecast(histIncome);
                const fcExpense = linearForecast(histExpense);
                
                const lastLabel = histLabels[histLabels.length - 1];
                const parts = lastLabel.split(' ');
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                let mIdx = monthNames.indexOf(parts[0]);
                let year = parseInt(parts[1]);
                const futureLabels = [...histLabels];
                for (let i = 1; i <= 3; i++) {
                    mIdx++;
                    if (mIdx > 11) { mIdx = 0; year++; }
                    futureLabels.push(monthNames[mIdx] + " " + year);
                }
                
                const datasetHistInc = Array(9).fill(null);
                const datasetHistExp = Array(9).fill(null);
                const datasetFcInc = Array(9).fill(null);
                const datasetFcExp = Array(9).fill(null);
                
                for (let i = 0; i < N; i++) {
                    datasetHistInc[i] = histIncome[i];
                    datasetHistExp[i] = histExpense[i];
                }
                datasetFcInc[N - 1] = histIncome[N - 1];
                datasetFcExp[N - 1] = histExpense[N - 1];
                
                for (let i = 0; i < 3; i++) {
                    datasetFcInc[N + i] = fcIncome[i];
                    datasetFcExp[N + i] = fcExpense[i];
                }
                
                window.monthlySummaryChart.destroy();
                window.monthlySummaryChart = new Chart(barCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: futureLabels,
                        datasets: [
                            {
                                label: 'Hist Income',
                                data: datasetHistInc,
                                borderColor: successColor,
                                backgroundColor: 'transparent',
                                fill: false,
                                tension: 0.3,
                                borderWidth: 2
                            },
                            {
                                label: 'FC Income',
                                data: datasetFcInc,
                                borderColor: successColor,
                                borderDash: [5, 5],
                                fill: false,
                                tension: 0.3,
                                borderWidth: 2,
                                pointRadius: 4
                            },
                            {
                                label: 'Hist Expense',
                                data: datasetHistExp,
                                borderColor: dangerColor,
                                backgroundColor: 'transparent',
                                fill: false,
                                tension: 0.3,
                                borderWidth: 2
                            },
                            {
                                label: 'FC Expense',
                                data: datasetFcExp,
                                borderColor: dangerColor,
                                borderDash: [5, 5],
                                fill: false,
                                tension: 0.3,
                                borderWidth: 2,
                                pointRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true, labels: { color: textColor, font: fontOptions } }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: textColor, font: fontOptions }
                            },
                            y: {
                                grid: { color: borderColor },
                                ticks: { color: textColor, font: fontOptions }
                            }
                        }
                    }
                });
            } else {
                btn.innerHTML = '<i class="fa-solid fa-chart-line"></i> Show Forecast';
                btn.classList.remove('active');
                
                window.monthlySummaryChart.destroy();
                window.monthlySummaryChart = new Chart(barCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: histLabels,
                        datasets: [
                            {
                                label: 'Income',
                                data: histIncome,
                                backgroundColor: successColor,
                                borderRadius: 6,
                                maxBarThickness: 15
                            },
                            {
                                label: 'Expense',
                                data: histExpense,
                                backgroundColor: dangerColor,
                                borderRadius: 6,
                                maxBarThickness: 15
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: textColor, font: fontOptions }
                            },
                            y: {
                                grid: { color: borderColor },
                                ticks: { color: textColor, font: fontOptions }
                            }
                        }
                    }
                });
            }
        }

        // 4. Category Pie Chart
        const catCtx = document.getElementById('categoryPieChart');
        if (catCtx) {
            new Chart(catCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($cat_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($cat_totals) ?>,
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
