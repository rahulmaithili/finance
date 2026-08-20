<?php
// Dashboard page for Income & Expense Management System (IEMS)
require_once 'config.php';
require_login();

$active_page = 'dashboard';

// Fetch Current Month Income
$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM income WHERE DATE_FORMAT(income_date, '%Y-%m') = ?");
$stmt->execute([$current_month]);
$total_income_this_month = $stmt->fetch()['total'] ?? 0.00;

// Fetch Current Month Expense
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
$stmt->execute([$current_month]);
$total_expense_this_month = $stmt->fetch()['total'] ?? 0.00;

// Net Profit / Loss
$net_profit = $total_income_this_month - $total_expense_this_month;

// Total Bank Balance
$stmt = $pdo->query("SELECT SUM(current_balance) as total FROM bank_accounts WHERE status = 'active'");
$total_bank_balance = $stmt->fetch()['total'] ?? 0.00;

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
    SELECT DATE_FORMAT(income_date, '%Y-%m') as month_str, SUM(amount) as total 
    FROM income 
    WHERE income_date >= DATE_SUB(LAST_DAY(NOW()), INTERVAL 6 MONTH) 
    GROUP BY month_str
");
while ($row = $stmt->fetch()) {
    if (isset($chart_months[$row['month_str']])) {
        $chart_months[$row['month_str']]['income'] = (float)$row['total'];
    }
}

// Fetch 6-month expense summary
$stmt = $pdo->query("
    SELECT DATE_FORMAT(expense_date, '%Y-%m') as month_str, SUM(amount) as total 
    FROM expenses 
    WHERE expense_date >= DATE_SUB(LAST_DAY(NOW()), INTERVAL 6 MONTH) 
    GROUP BY month_str
");
while ($row = $stmt->fetch()) {
    if (isset($chart_months[$row['month_str']])) {
        $chart_months[$row['month_str']]['expense'] = (float)$row['total'];
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
                
                <!-- Dashboard Analytics Grid -->
                <div class="dashboard-grid">
                    <!-- Left: Chart Panel -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2>Income vs Expense Analysis</h2>
                            <span class="header-action">Last 6 Months</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="incomeExpenseChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Right: Recent Transactions List -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2>Recent Ledger Entries</h2>
                            <a href="reports.php" class="header-action" style="font-weight: 500;">View All</a>
                        </div>
                        
                        <div class="mini-list">
                            <?php if (count($recent_transactions) === 0): ?>
                                <div style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                                    No recent transactions found
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
                                    <div class="mini-item <?= $itemClass ?>">
                                        <div class="mini-info">
                                            <div class="mini-icon">
                                                <i class="fa-solid <?= $itemIcon ?>"></i>
                                            </div>
                                            <div class="mini-details">
                                                <h4><?= clean($tx['title']) ?></h4>
                                                <span><?= clean(date('d M Y', strtotime($tx['txn_date']))) ?> • <?= clean($tx['category']) ?></span>
                                            </div>
                                        </div>
                                        <div class="mini-amount"><?= $prefix . format_currency($tx['amount']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Theme-aware Chart initialization script -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const ctx = document.getElementById('incomeExpenseChart').getContext('2d');
        
        // Retrieve current colors from CSS variables
        const styles = getComputedStyle(document.body);
        const primaryColor = styles.getPropertyValue('--primary').trim();
        const successColor = styles.getPropertyValue('--success').trim();
        const dangerColor = styles.getPropertyValue('--danger').trim();
        const textColor = styles.getPropertyValue('--text-secondary').trim();
        const borderColor = styles.getPropertyValue('--border-color').trim();
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [
                    {
                        label: 'Income',
                        data: <?= json_encode($income_data) ?>,
                        backgroundColor: successColor,
                        borderRadius: 6,
                        maxBarThickness: 30
                    },
                    {
                        label: 'Expense',
                        data: <?= json_encode($expense_data) ?>,
                        backgroundColor: dangerColor,
                        borderRadius: 6,
                        maxBarThickness: 30
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: textColor,
                            font: {
                                family: 'Inter',
                                size: 12
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: borderColor
                        },
                        ticks: {
                            color: textColor
                        }
                    },
                    y: {
                        grid: {
                            color: borderColor
                        },
                        ticks: {
                            color: textColor,
                            callback: function(value) {
                                return '₹' + value;
                            }
                        }
                    }
                }
            }
        });
    });
    </script>
</body>
</html>
