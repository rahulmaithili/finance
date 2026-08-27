<?php
// Printable Repayment Receipt Voucher page for IEMS
require_once 'config.php';
require_login();

$type = $_GET['type'] ?? 'borrowed';
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['borrowed', 'given'])) {
    die("Invalid repayment specifications.");
}

$pmt = null;
$company = null;

try {
    // Fetch Company settings
    $c_stmt = $pdo->query("SELECT * FROM company_settings LIMIT 1");
    $company = $c_stmt->fetch();

    if ($type === 'borrowed') {
        $stmt = $pdo->prepare("
            SELECT p.*, l.lender_name as party_name, a.account_name, a.bank_name, a.account_number, u.full_name as recorder_name 
            FROM loan_payments p
            JOIN loans l ON p.loan_id = l.id
            JOIN bank_accounts a ON p.account_id = a.id
            JOIN users u ON p.recorded_by = u.id
            WHERE p.id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $pmt = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, l.debtor_name as party_name, a.account_name, a.bank_name, a.account_number, u.full_name as recorder_name 
            FROM loan_given_payments p
            JOIN loans_given l ON p.loan_given_id = l.id
            JOIN bank_accounts a ON p.account_id = a.id
            JOIN users u ON p.recorded_by = u.id
            WHERE p.id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $pmt = $stmt->fetch();
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

if (!$pmt) {
    die("Repayment record not found.");
}

$voucher_no = ($type === 'borrowed' ? 'LRP-' : 'LGR-') . str_pad($pmt['id'], 6, '0', STR_PAD_LEFT);
$voucher_title = $type === 'borrowed' ? 'Loan Repayment Payment Voucher' : 'Loan Repayment Receipt Voucher';
$party_label = $type === 'borrowed' ? 'Paid To (Lender Bank)' : 'Received From (Debtor)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repayment Voucher <?= clean($voucher_no) ?></title>
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
            <i class="fa-solid fa-print"></i> Print Receipt
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
                <h3 style="text-transform: uppercase; color: <?= $type === 'borrowed' ? 'var(--danger)' : 'var(--success)' ?>; margin:0; font-size:1.15rem;">
                    <?= $voucher_title ?>
                </h3>
                <p style="font-weight: 600; margin-top: 5px;">Voucher No: <?= clean($voucher_no) ?></p>
                <p style="font-size: 0.82rem; color: var(--text-secondary); margin-top: 2px;">
                    Date: <?= clean(date('d M Y', strtotime($pmt['payment_date']))) ?>
                </p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; font-size: 0.9rem;">
            <div>
                <h4 style="border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 10px; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; color: var(--text-secondary);">Transaction Party</h4>
                <p style="font-weight: 700; font-size:1rem; color:#0f172a;"><?= clean($pmt['party_name']) ?></p>
                <p style="color: var(--text-secondary); font-size:0.85rem; margin-top: 2px;"><?= $party_label ?></p>
            </div>
            
            <div>
                <h4 style="border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 10px; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; color: var(--text-secondary);">Settlement Account</h4>
                <p style="font-weight: 700;"><?= clean($pmt['account_name']) ?></p>
                <p style="color: var(--text-secondary); margin-top: 2px;"><?= clean($pmt['bank_name']) ?></p>
                <p style="color: var(--text-secondary); font-family: monospace; font-size: 0.8rem; margin-top: 2px;">Acc No: <?= clean($pmt['account_number']) ?></p>
            </div>
        </div>

        <!-- Details Table -->
        <table class="invoice-table" style="width: 100%; border-collapse: collapse; margin-bottom: 40px;">
            <thead>
                <tr>
                    <th style="width: 50%;">Description / Reference</th>
                    <th>Payment Method</th>
                    <th>Recorded By</th>
                    <th style="text-align: right;">Amount (INR)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div style="font-weight: 700; font-size: 0.95rem; color:#0f172a;">Repayment of EMI Installment</div>
                        <?php if ($pmt['note']): ?>
                            <div style="font-size: 0.82rem; color: var(--text-secondary); margin-top: 6px;">
                                Remarks: <?= clean($pmt['note']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(99,102,241,0.15); color:var(--primary); font-size:0.75rem; text-transform: uppercase;">
                            <?= clean($pmt['payment_method']) ?>
                        </span>
                    </td>
                    <td><?= clean($pmt['recorder_name']) ?></td>
                    <td style="text-align: right; font-weight: 700; font-size: 1.05rem; color:#0f172a;">
                        <?= format_currency($pmt['amount']) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Signature Lines -->
        <div style="margin-top: 70px; display: flex; justify-content: space-between; font-size: 0.85rem; text-align: center;">
            <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
                Received / Prepared By
            </div>
            <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
                Authorized Signature
            </div>
        </div>
    </div>

</body>
</html>
