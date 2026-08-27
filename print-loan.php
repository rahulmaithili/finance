<?php
// Printable Loan Amortization Schedule page for IEMS
require_once 'config.php';
require_login();

$type = $_GET['type'] ?? 'borrowed';
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['borrowed', 'given'])) {
    die("Invalid loan specifications.");
}

$loan = null;
$company = null;

try {
    // Fetch Company settings
    $c_stmt = $pdo->query("SELECT * FROM company_settings LIMIT 1");
    $company = $c_stmt->fetch();

    if ($type === 'borrowed') {
        $stmt = $pdo->prepare("
            SELECT l.*, a.account_name, a.bank_name, a.account_number 
            FROM loans l
            JOIN bank_accounts a ON l.repayment_account_id = a.id
            WHERE l.id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $loan = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare("
            SELECT l.*, a.account_name, a.bank_name, a.account_number 
            FROM loans_given l
            JOIN bank_accounts a ON l.repayment_account_id = a.id
            WHERE l.id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $loan = $stmt->fetch();
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

if (!$loan) {
    die("Loan record not found.");
}

$title_label = $type === 'borrowed' ? 'Borrowed Loan Repayment Schedule' : 'Lent Loan Repayment Schedule';
$party_label = $type === 'borrowed' ? 'Lender / Bank' : 'Borrower / Debtor';
$party_name = $type === 'borrowed' ? $loan['lender_name'] : $loan['debtor_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Schedule - <?= clean($party_name) ?></title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f1f5f9;
            color: #1e293b;
            display: block;
            min-height: auto;
            padding: 20px;
        }
        .print-btn-bar {
            max-width: 800px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: space-between;
        }
        .spec-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 30px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            font-size: 0.85rem;
        }
        .spec-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .spec-item strong {
            color: #64748b;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .spec-item span {
            font-weight: 600;
            color: #0f172a;
        }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .print-btn-bar, .nav-btn, .btn-primary {
                display: none !important;
            }
            .print-container {
                box-shadow: none;
                margin: 0;
                border: none;
                width: 100%;
                max-width: 100%;
                padding: 0;
            }
            .spec-grid {
                background: transparent;
                border-color: #cbd5e1;
            }
        }
    </style>
</head>
<body>

    <!-- Utility Bar -->
    <div class="print-btn-bar">
        <a href="javascript:window.close();" class="btn-primary btn-secondary" style="width: auto; padding: 10px 20px; font-size: 0.9rem;">
            <i class="fa-solid fa-arrow-left"></i> Close Window
        </a>
        <button onclick="window.print()" class="btn-primary" style="width: auto; padding: 10px 20px; font-size: 0.9rem;">
            <i class="fa-solid fa-print"></i> Print Schedule
        </button>
    </div>

    <!-- Printable Container -->
    <div class="print-container">
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="invoice-logo" style="display:flex; align-items:center; gap:15px;">
                <?php if ($company && !empty($company['logo']) && file_exists(__DIR__ . '/' . $company['logo'])): ?>
                    <img src="<?= $company['logo'] ?>" alt="Company Logo" style="max-height:60px; max-width:150px; object-fit:contain; border-radius: 4px;">
                <?php endif; ?>
                <div>
                    <h2 style="font-size: 1.5rem; color: #0f172a;"><?= ($company && !empty($company['company_name'])) ? clean($company['company_name']) : 'IEMS ERP SYSTEM' ?></h2>
                    <?php if ($company && !empty($company['address'])): ?>
                        <p style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 3px; line-height: 1.3;">
                            <?= clean($company['address']) ?><br>
                            Phone: <?= clean($company['phone']) ?> | Email: <?= clean($company['email']) ?>
                        </p>
                    <?php else: ?>
                        <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 5px;">
                            Income & Expense Management System
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="invoice-details" style="text-align: right;">
                <h3 style="text-transform: uppercase; color: var(--primary); margin:0; font-size:1.15rem;">
                    <?= $title_label ?>
                </h3>
                <p style="font-size: 0.82rem; color: var(--text-secondary); margin-top: 5px;">
                    Report Date: <?= date('d M Y') ?>
                </p>
            </div>
        </div>

        <!-- Specifications Grid -->
        <div class="spec-grid">
            <div class="spec-item">
                <strong><?= $party_label ?></strong>
                <span><?= clean($party_name) ?></span>
            </div>
            <div class="spec-item">
                <strong>Loan Principal</strong>
                <span><?= format_currency($loan['principal']) ?></span>
            </div>
            <div class="spec-item">
                <strong>Interest Rate</strong>
                <span><?= number_format($loan['interest_rate'], 2) ?>% P.A.</span>
            </div>
            <div class="spec-item">
                <strong>Tenure Months</strong>
                <span><?= $loan['tenure_months'] ?> Months</span>
            </div>
            <div class="spec-item">
                <strong>Monthly EMI</strong>
                <span style="color:#4f46e5;"><?= format_currency($loan['emi_amount']) ?></span>
            </div>
            <div class="spec-item">
                <strong>Start Date</strong>
                <span><?= clean(date('d M Y', strtotime($loan['start_date']))) ?></span>
            </div>
            <div class="spec-item">
                <strong>Repayment Bank Account</strong>
                <span><?= clean($loan['account_name']) ?> (<?= clean($loan['bank_name']) ?>)</span>
            </div>
            <div class="spec-item">
                <strong>EMIs Settled / Paid</strong>
                <span><?= $loan['emi_paid'] ?> / <?= $loan['tenure_months'] ?></span>
            </div>
            <div class="spec-item">
                <strong>Remaining Balance</strong>
                <span><?= format_currency(max(0, $loan['principal'] - ($loan['emi_paid'] * ($loan['emi_amount'] - ($loan['principal'] * ($loan['interest_rate']/100/12)))))) ?></span>
            </div>
        </div>

        <!-- Schedule Table -->
        <table class="invoice-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Date</th>
                    <th style="text-align: right;">EMI Amount</th>
                    <th style="text-align: right;">Principal</th>
                    <th style="text-align: right;">Interest</th>
                    <th style="text-align: right;">Remaining Bal</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $balance = (float)$loan['principal'];
                $rate = (float)$loan['interest_rate'];
                $tenure = (int)$loan['tenure_months'];
                $emiAmount = (float)$loan['emi_amount'];
                $paidCount = (int)$loan['emi_paid'];
                $monthlyRate = ($rate / 100) / 12;
                $start = new DateTime($loan['start_date']);

                for ($m = 1; $m <= $tenure; $m++) {
                    $interest = $balance * $monthlyRate;
                    $principalPortion = $emiAmount - $interest;
                    $balance = $balance - $principalPortion;
                    
                    $instDate = clone $start;
                    if ($m > 1) {
                        $instDate->modify('+' . ($m - 1) . ' month');
                    }
                    $dateStr = $instDate->format('d M Y');
                    $isPaid = $m <= $paidCount;
                    ?>
                    <tr>
                        <td style="font-weight: 700;">Month <?= $m ?></td>
                        <td><?= $dateStr ?></td>
                        <td style="text-align: right; font-weight: 700; color: #4f46e5;"><?= format_currency($emiAmount) ?></td>
                        <td style="text-align: right;"><?= format_currency($principalPortion) ?></td>
                        <td style="text-align: right; color: var(--text-secondary);"><?= format_currency($interest) ?></td>
                        <td style="text-align: right; font-weight: 600;"><?= format_currency(max(0, $balance)) ?></td>
                        <td>
                            <?php if ($isPaid): ?>
                                <span style="color:#10b981; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Paid</span>
                            <?php else: ?>
                                <span style="color:#f59e0b; font-weight:700;"><i class="fa-solid fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>

        <!-- Signature Lines -->
        <div style="margin-top: 70px; display: flex; justify-content: space-between; font-size: 0.85rem; text-align: center;">
            <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
                Client Signature
            </div>
            <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
                Authorized Representative
            </div>
        </div>
    </div>

</body>
</html>
