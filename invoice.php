<?php
// Printable transaction receipt/invoice page for IEMS
require_once 'config.php';
require_login();

$type = $_GET['type'] ?? 'income';
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['income', 'expense'])) {
    die("Invalid transaction specifications.");
}

$txn = null;

try {
    if ($type === 'income') {
        $stmt = $pdo->prepare("
            SELECT i.*, a.account_name, a.bank_name, a.account_number, u.full_name as recorder_name 
            FROM income i
            JOIN bank_accounts a ON i.account_id = a.id
            JOIN users u ON i.created_by = u.id
            WHERE i.id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $txn = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare("
            SELECT e.*, a.account_name, a.bank_name, a.account_number, u.full_name as recorder_name 
            FROM expenses e
            JOIN bank_accounts a ON e.account_id = a.id
            JOIN users u ON e.created_by = u.id
            WHERE e.id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $txn = $stmt->fetch();
    }
} catch (PDOException $e) {
    die("Error fetching transaction details.");
}

if (!$txn) {
    die("Transaction entry not found.");
}

$voucher_no = strtoupper(substr($type, 0, 3)) . "-" . str_pad($txn['id'], 6, '0', STR_PAD_LEFT);
$txn_date = $type === 'income' ? $txn['income_date'] : $txn['expense_date'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher <?= clean($voucher_no) ?> - IEMS ERP</title>
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

    <!-- Utility Bar to toggle actions -->
    <div class="print-btn-bar">
        <a href="javascript:window.close();" class="btn-primary btn-secondary" style="width: auto; padding: 10px 20px; font-size: 0.9rem;">
            <i class="fa-solid fa-arrow-left"></i> Close Window
        </a>
        <button onclick="window.print()" class="btn-primary" style="width: auto; padding: 10px 20px; font-size: 0.9rem;">
            <i class="fa-solid fa-print"></i> Print Voucher
        </button>
    </div>

    <!-- Printable Receipt Layout -->
    <div class="print-container">
        <div class="invoice-header">
            <div class="invoice-logo">
                <h2>IEMS ERP SYSTEM</h2>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 5px;">
                    Income & Expense Management System
                </p>
            </div>
            <div class="invoice-details">
                <h3 style="text-transform: uppercase; color: <?= $type === 'income' ? 'var(--success)' : 'var(--danger)' ?>;">
                    <?= $type === 'income' ? 'Receipt Voucher' : 'Payment Voucher' ?>
                </h3>
                <p style="font-weight: 600; margin-top: 5px;">Voucher No: <?= clean($voucher_no) ?></p>
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 2px;">
                    Date: <?= clean(date('d M Y', strtotime($txn_date))) ?>
                </p>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; font-size: 0.9rem;">
            <div>
                <h4 style="border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 10px; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; color: var(--text-secondary);">Account Details</h4>
                <p style="font-weight: 700;"><?= clean($txn['account_name']) ?></p>
                <p style="color: var(--text-secondary); margin-top: 2px;"><?= clean($txn['bank_name']) ?><?php if (!empty($txn['branch_name'])): ?> (<?= clean($txn['branch_name']) ?> Branch)<?php endif; ?></p>
                <p style="color: var(--text-secondary); font-family: monospace; font-size: 0.8rem; margin-top: 2px;">Acc No: <?= clean($txn['account_number']) ?></p>
                <?php if (!empty($txn['ifsc_code'])): ?>
                    <p style="color: var(--text-secondary); font-family: monospace; font-size: 0.8rem; margin-top: 2px;">IFSC: <?= clean($txn['ifsc_code']) ?></p>
                <?php endif; ?>
            </div>
            
            <div>
                <h4 style="border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 10px; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; color: var(--text-secondary);">Payment Specifications</h4>
                <p><strong>Method:</strong> <span style="text-transform: uppercase;"><?= clean($txn['payment_method']) ?></span></p>
                <?php if ($txn['reference_no']): ?>
                    <p style="margin-top: 4px;"><strong>Reference No:</strong> <?= clean($txn['reference_no']) ?></p>
                <?php endif; ?>
                <p style="margin-top: 4px;"><strong>Recorded By:</strong> <?= clean($txn['recorder_name']) ?></p>
            </div>
        </div>
        
        <table class="invoice-table">
            <thead>
                <tr>
                    <th style="width: 70%;">Particulars / Description</th>
                    <th>Category</th>
                    <th style="text-align: right;">Amount (INR)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div style="font-weight: 700; font-size: 1rem; color: #0f172a;"><?= clean($txn['title']) ?></div>
                        <?php if ($txn['description']): ?>
                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 6px; line-height: 1.4;">
                                <?= nl2br(clean($txn['description'])) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $type === 'income' ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.7rem; padding: 4px 8px;">
                            <?= clean($txn['category']) ?>
                        </span>
                    </td>
                    <td style="text-align: right; font-weight: 700; font-size: 1.05rem; color: #0f172a;">
                        <?= format_currency($txn['amount']) ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 60px;">
            <div style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.4;">
                <p>This is a computer-generated voucher.</p>
                <p>Generated at: <?= clean(date('Y-m-d H:i:s')) ?></p>
            </div>
            
            <div class="invoice-total">
                <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-secondary); margin-right: 15px; text-transform: uppercase;">Total Net Amount:</span>
                <span style="color: #4f46e5;"><?= format_currency($txn['amount']) ?></span>
            </div>
        </div>
        
        <div style="margin-top: 80px; display: flex; justify-content: space-between; font-size: 0.85rem; text-align: center;">
            <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
                Prepared By
            </div>
            <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
                Verified / Approved By
            </div>
        </div>
    </div>

</body>
</html>
