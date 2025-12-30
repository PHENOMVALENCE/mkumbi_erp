<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$payment_id) {
    $_SESSION['error_message'] = "❌ Invalid payment ID";
    header('Location: payments.php');
    exit;
}

// Generate permanent receipt number
function generateReceiptNumber($conn, $payment_id, $company_id) {
    try {
        $check_query = "SELECT receipt_number FROM payments WHERE payment_id = ? AND company_id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->execute([$payment_id, $company_id]);
        $existing = $stmt->fetchColumn();
        
        if ($existing) return $existing;
        
        $year = date('Y');
        $prefix = "RCP";
        
        $last_query = "SELECT receipt_number FROM payments WHERE company_id = ? AND receipt_number LIKE ? ORDER BY receipt_number DESC LIMIT 1";
        $stmt = $conn->prepare($last_query);
        $stmt->execute([$company_id, "$prefix-$year-%"]);
        $last_receipt = $stmt->fetchColumn();
        
        if ($last_receipt) {
            preg_match('/\d+$/', $last_receipt, $matches);
            $sequence = isset($matches[0]) ? (int)$matches[0] + 1 : 1;
        } else {
            $sequence = 1;
        }
        
        $receipt_number = sprintf("%s-%s-%04d", $prefix, $year, $sequence);
        
        $update_query = "UPDATE payments SET receipt_number = ? WHERE payment_id = ? AND company_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->execute([$receipt_number, $payment_id, $company_id]);
        
        return $receipt_number;
    } catch (PDOException $e) {
        error_log("Error generating receipt number: " . $e->getMessage());
        return "RCP-" . date('Ymd') . "-" . str_pad($payment_id, 4, '0', STR_PAD_LEFT);
    }
}

// Convert number to words
function numberToWords($number) {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $teens = ['Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    
    $whole = floor($number);
    if ($whole == 0) return 'Zero';
    
    $words = '';
    
    if ($whole >= 1000000) {
        $millions = floor($whole / 1000000);
        $words .= numberToWords($millions) . ' Million ';
        $whole %= 1000000;
    }
    
    if ($whole >= 1000) {
        $thousands = floor($whole / 1000);
        $words .= numberToWords($thousands) . ' Thousand ';
        $whole %= 1000;
    }
    
    if ($whole >= 100) {
        $hundreds = floor($whole / 100);
        $words .= $ones[$hundreds] . ' Hundred ';
        $whole %= 100;
    }
    
    if ($whole >= 20) {
        $words .= $tens[floor($whole / 10)] . ' ';
        $whole %= 10;
    } elseif ($whole >= 10) {
        $words .= $teens[$whole - 10] . ' ';
        $whole = 0;
    }
    
    if ($whole > 0) {
        $words .= $ones[$whole] . ' ';
    }
    
    return trim($words);
}

// Fetch payment details
try {
    $query = "
        SELECT 
            p.*, r.reservation_number, r.total_amount as reservation_total,
            c.first_name, c.middle_name, c.last_name, c.phone, c.email, c.id_number,
            pl.plot_number, pl.block_number, pl.area,
            proj.project_name,
            ba.bank_name, ba.account_number as bank_account_number,
            u.first_name as created_by_first, u.last_name as created_by_last,
            ua.first_name as approved_by_first, ua.last_name as approved_by_last
        FROM payments p
        INNER JOIN reservations r ON p.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        LEFT JOIN plots pl ON r.plot_id = pl.plot_id
        LEFT JOIN projects proj ON pl.project_id = proj.project_id
        LEFT JOIN bank_accounts ba ON p.to_account_id = ba.bank_account_id
        LEFT JOIN users u ON p.created_by = u.user_id
        LEFT JOIN users ua ON p.approved_by = ua.user_id
        WHERE p.payment_id = ? AND p.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$payment_id, $company_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        $_SESSION['error_message'] = "❌ Payment not found";
        header('Location: payments.php');
        exit;
    }
    
    $receipt_number = generateReceiptNumber($conn, $payment_id, $company_id);
    $payment['receipt_number'] = $receipt_number;
    
} catch (PDOException $e) {
    error_log("Error fetching payment: " . $e->getMessage());
    $_SESSION['error_message'] = "❌ Error loading payment data";
    header('Location: payments.php');
    exit;
}

// Fetch company details
try {
    $company_query = "SELECT company_name, email, phone, physical_address, logo_path, tax_identification_number FROM companies WHERE company_id = ?";
    $stmt = $conn->prepare($company_query);
    $stmt->execute([$company_id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $company = [];
}

// Calculate amounts
$amount_paid = $payment['amount'];
$tax_amount = $payment['tax_amount'] ?? 0;
$subtotal = $amount_paid - $tax_amount;

// Get totals
try {
    $summary_query = "
        SELECT 
            SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as total_paid,
            (SELECT total_amount FROM reservations WHERE reservation_id = ?) as reservation_total
        FROM payments WHERE reservation_id = ? AND company_id = ?
    ";
    $stmt = $conn->prepare($summary_query);
    $stmt->execute([$payment['reservation_id'], $payment['reservation_id'], $company_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total_paid = $summary['total_paid'] ?? 0;
    $reservation_total = $summary['reservation_total'] ?? 0;
    $balance = $reservation_total - $total_paid;
} catch (PDOException $e) {
    $total_paid = 0;
    $balance = 0;
}

$customer_name = trim($payment['first_name'] . ' ' . ($payment['middle_name'] ? $payment['middle_name'] . ' ' : '') . $payment['last_name']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo htmlspecialchars($receipt_number); ?></title>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; padding: 0; }
            .container { box-shadow: none !important; border: none !important; }
            @page { margin: 15mm; size: A4; }
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background: #f5f5f5;
            padding: 10px;
            margin: 0;
            color: #000;
            font-size: 11px;
        }
        
        .container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 15px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            border: 2px solid #000;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        
        .logo { max-width: 80px; max-height: 60px; margin-bottom: 5px; }
        .company-name { font-size: 16px; font-weight: bold; text-transform: uppercase; margin: 3px 0; }
        .company-info { font-size: 9px; line-height: 1.4; }
        
        .title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            margin: 8px 0;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 5px 0;
        }
        
        .receipt-no {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            border: 2px solid #000;
            padding: 5px;
            margin: 8px auto;
            display: inline-block;
        }
        
        .status {
            text-align: center;
            border: 2px solid #000;
            padding: 4px 15px;
            display: inline-block;
            font-weight: bold;
            font-size: 11px;
        }
        
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.info td { padding: 3px 5px; border: 1px solid #000; font-size: 10px; }
        table.info td.label { font-weight: bold; width: 25%; background: #f5f5f5; }
        
        .section { font-weight: bold; border-bottom: 2px solid #000; margin: 10px 0 5px 0; padding-bottom: 2px; font-size: 11px; }
        
        .transfer-box {
            border: 1px solid #000;
            padding: 6px;
            margin: 6px 0;
            background: #fafafa;
        }
        
        .transfer-box .title {
            font-weight: bold;
            font-size: 10px;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }
        
        .transfer-box table { margin: 0; }
        .transfer-box table td { border: none; padding: 2px; font-size: 9px; }
        
        table.payment { border: 2px solid #000; }
        table.payment th {
            background: #000;
            color: #fff;
            padding: 6px;
            font-size: 10px;
            text-align: left;
        }
        table.payment td {
            padding: 5px 6px;
            border: 1px solid #000;
            font-size: 10px;
        }
        table.payment td.amount { text-align: right; font-family: 'Courier New', monospace; }
        table.payment tr.total { font-weight: bold; background: #f5f5f5; }
        table.payment tr.total td { border-top: 2px solid #000; padding: 8px 6px; }
        
        .words-box {
            border: 1px solid #000;
            padding: 6px;
            margin: 6px 0;
            background: #f9f9f9;
        }
        .words-box .label { font-weight: bold; font-size: 9px; }
        .words-box .value { font-size: 10px; font-weight: bold; font-style: italic; }
        
        table.summary { border: 2px solid #000; }
        table.summary td { padding: 5px; border: 1px solid #000; font-size: 10px; }
        table.summary td.label { font-weight: bold; width: 60%; }
        table.summary td.amount { text-align: right; font-family: 'Courier New', monospace; }
        table.summary tr.highlight { background: #f5f5f5; font-weight: bold; }
        
        .signatures {
            margin-top: 20px;
            display: table;
            width: 100%;
        }
        .sig-box {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 0 10px;
        }
        .sig-line {
            border-top: 2px solid #000;
            margin-top: 30px;
            padding-top: 5px;
            font-weight: bold;
            font-size: 10px;
        }
        
        .footer {
            text-align: center;
            margin-top: 12px;
            font-size: 9px;
            line-height: 1.5;
        }
        
        .print-info {
            text-align: center;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #ccc;
            font-size: 8px;
            color: #666;
        }
        
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            font-weight: bold;
            color: rgba(0,0,0,0.03);
            z-index: -1;
        }
        
        .btn {
            background: #000;
            color: white;
            border: none;
            padding: 8px 20px;
            margin: 5px;
            cursor: pointer;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="watermark">PAID</div>

<div class="no-print" style="text-align: center; margin-bottom: 10px;">
    <button onclick="window.print()" class="btn">PRINT</button>
    <button onclick="window.close()" class="btn" style="background: #666;">CLOSE</button>
    <a href="payments.php" class="btn" style="background: #333; text-decoration: none; color: white;">BACK</a>
</div>

<div class="container">
    <div class="header">
        <?php if (!empty($company['logo_path']) && file_exists('../../' . $company['logo_path'])): ?>
            <img src="../../<?php echo htmlspecialchars($company['logo_path']); ?>" alt="Logo" class="logo">
        <?php endif; ?>
        <div class="company-name"><?php echo htmlspecialchars($company['company_name'] ?? 'COMPANY NAME'); ?></div>
        <div class="company-info">
            <?php echo htmlspecialchars($company['physical_address'] ?? ''); ?> | 
            Tel: <?php echo htmlspecialchars($company['phone'] ?? 'N/A'); ?> | 
            <?php echo htmlspecialchars($company['email'] ?? ''); ?>
            <?php if (!empty($company['tax_identification_number'])): ?>
                <br>TIN: <?php echo htmlspecialchars($company['tax_identification_number']); ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="title">PAYMENT RECEIPT</div>
    
    <div style="text-align: center; margin: 8px 0;">
        <div class="receipt-no"><?php echo htmlspecialchars($receipt_number); ?></div>
        <span class="status"><?php echo strtoupper($payment['status']); ?></span>
    </div>
    
    <table class="info">
        <tr>
            <td class="label">Customer:</td>
            <td><?php echo htmlspecialchars($customer_name); ?></td>
            <td class="label">Date:</td>
            <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
        </tr>
        <tr>
            <td class="label">Phone:</td>
            <td><?php echo htmlspecialchars($payment['phone'] ?? 'N/A'); ?></td>
            <td class="label">Method:</td>
            <td><strong><?php echo strtoupper(str_replace('_', ' ', $payment['payment_method'])); ?></strong></td>
        </tr>
        <?php if (!empty($payment['id_number'])): ?>
        <tr>
            <td class="label">ID:</td>
            <td><?php echo htmlspecialchars($payment['id_number']); ?></td>
            <td class="label">Type:</td>
            <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_type'] ?? 'Installment')); ?></td>
        </tr>
        <?php endif; ?>
    </table>
    
    <div class="section">PROPERTY DETAILS</div>
    <table class="info">
        <tr>
            <td class="label">Project:</td>
            <td><?php echo htmlspecialchars($payment['project_name'] ?? 'N/A'); ?></td>
            <td class="label">Plot:</td>
            <td><strong><?php echo htmlspecialchars($payment['plot_number'] ?? 'N/A'); ?></strong>
                <?php if (!empty($payment['block_number'])): ?>- Blk <?php echo htmlspecialchars($payment['block_number']); ?><?php endif; ?>
            </td>
        </tr>
        <?php if (!empty($payment['area'])): ?>
        <tr>
            <td class="label">Area:</td>
            <td><?php echo number_format($payment['area'], 2); ?> m²</td>
            <td class="label">Reservation:</td>
            <td><?php echo htmlspecialchars($payment['reservation_number'] ?? 'N/A'); ?></td>
        </tr>
        <?php endif; ?>
    </table>
    
    <?php if ($payment['payment_method'] == 'bank_transfer'): ?>
    <div class="transfer-box">
        <div class="title">TRANSFER DETAILS</div>
        <table>
            <?php if (!empty($payment['transfer_from_bank'])): ?><tr><td style="width:35%;"><strong>From Bank:</strong></td><td><?php echo htmlspecialchars($payment['transfer_from_bank']); ?></td></tr><?php endif; ?>
            <?php if (!empty($payment['transfer_from_account'])): ?><tr><td><strong>From Account:</strong></td><td><?php echo htmlspecialchars($payment['transfer_from_account']); ?></td></tr><?php endif; ?>
            <?php if (!empty($payment['bank_name'])): ?><tr><td><strong>To Bank:</strong></td><td><?php echo htmlspecialchars($payment['bank_name']); ?></td></tr><?php endif; ?>
            <?php if (!empty($payment['bank_account_number'])): ?><tr><td><strong>To Account:</strong></td><td><?php echo htmlspecialchars($payment['bank_account_number']); ?></td></tr><?php endif; ?>
            <?php if (!empty($payment['transaction_reference'])): ?><tr><td><strong>Reference:</strong></td><td><strong><?php echo htmlspecialchars($payment['transaction_reference']); ?></strong></td></tr><?php endif; ?>
        </table>
    </div>
    <?php elseif ($payment['payment_method'] == 'mobile_money'): ?>
    <div class="transfer-box">
        <div class="title">MOBILE MONEY</div>
        <table>
            <?php if (!empty($payment['mobile_money_provider'])): ?><tr><td style="width:35%;"><strong>Provider:</strong></td><td><?php echo htmlspecialchars($payment['mobile_money_provider']); ?></td></tr><?php endif; ?>
            <?php if (!empty($payment['mobile_money_number'])): ?><tr><td><strong>Number:</strong></td><td><?php echo htmlspecialchars($payment['mobile_money_number']); ?></td></tr><?php endif; ?>
            <?php if (!empty($payment['transaction_reference'])): ?><tr><td><strong>Reference:</strong></td><td><strong><?php echo htmlspecialchars($payment['transaction_reference']); ?></strong></td></tr><?php endif; ?>
        </table>
    </div>
    <?php elseif ($payment['payment_method'] == 'cheque'): ?>
    <div class="transfer-box">
        <div class="title">CHEQUE</div>
        <table>
            <tr><td style="width:35%;"><strong>Cheque No:</strong></td><td><strong><?php echo htmlspecialchars($payment['transaction_reference'] ?? 'N/A'); ?></strong></td></tr>
            <?php if (!empty($payment['bank_name'])): ?><tr><td><strong>Bank:</strong></td><td><?php echo htmlspecialchars($payment['bank_name']); ?></td></tr><?php endif; ?>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="section">PAYMENT BREAKDOWN</div>
    <table class="payment">
        <thead>
            <tr>
                <th>DESCRIPTION</th>
                <th style="width:35%; text-align:right;">AMOUNT (TSH)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Payment for <?php echo ucfirst(str_replace('_', ' ', $payment['payment_type'] ?? 'Installment')); ?></td>
                <td class="amount"><?php echo number_format($subtotal, 2); ?></td>
            </tr>
            <?php if ($tax_amount > 0): ?>
            <tr>
                <td>Tax (VAT/WHT)</td>
                <td class="amount"><?php echo number_format($tax_amount, 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="total">
                <td><strong>TOTAL PAID</strong></td>
                <td class="amount"><strong>TSH <?php echo number_format($amount_paid, 2); ?></strong></td>
            </tr>
        </tbody>
    </table>
    
    <div class="words-box">
        <div class="label">Amount in Words:</div>
        <div class="value"><?php echo strtoupper(numberToWords($amount_paid)) . ' SHILLINGS ONLY'; ?></div>
    </div>
    
    <table class="summary">
        <tr>
            <td class="label">Contract Amount:</td>
            <td class="amount">TSH <?php echo number_format($reservation_total, 2); ?></td>
        </tr>
        <tr>
            <td class="label">Total Paid:</td>
            <td class="amount">TSH <?php echo number_format($total_paid, 2); ?></td>
        </tr>
        <tr class="highlight">
            <td class="label"><strong>Balance:</strong></td>
            <td class="amount"><strong>TSH <?php echo number_format($balance, 2); ?></strong></td>
        </tr>
    </table>
    
    <?php if (!empty($payment['remarks'])): ?>
    <div style="border:1px solid #000; padding:5px; margin:6px 0; font-size:9px;">
        <strong>Notes:</strong> <?php echo htmlspecialchars($payment['remarks']); ?>
    </div>
    <?php endif; ?>
    
    <div style="font-size:8px; margin:6px 0; line-height:1.3;">
        <strong>Terms:</strong> Valid when signed. Non-refundable. Retain for records. Report issues within 7 days.
    </div>
    
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line">Customer Signature</div>
        </div>
        <div class="sig-box">
            <div class="sig-line">
                <?php 
                if (!empty($payment['approved_by_first'])) {
                    echo htmlspecialchars($payment['approved_by_first'] . ' ' . $payment['approved_by_last']);
                } elseif (!empty($payment['created_by_first'])) {
                    echo htmlspecialchars($payment['created_by_first'] . ' ' . $payment['created_by_last']);
                } else {
                    echo 'Authorized Officer';
                }
                ?>
            </div>
            <div style="margin-top:5px; border:1px solid #000; padding:2px; display:inline-block; font-size:8px;">STAMP</div>
        </div>
    </div>
    
    <div class="footer">
        <strong>Thank you for your business!</strong><br>
        Contact: <?php echo htmlspecialchars($company['phone'] ?? 'N/A'); ?> | <?php echo htmlspecialchars($company['email'] ?? 'N/A'); ?>
    </div>
    
    <div class="print-info">
        Receipt: <strong><?php echo htmlspecialchars($receipt_number); ?></strong> | 
        Printed: <?php echo date('d/m/Y H:i'); ?>
        <?php if (!empty($payment['created_by_first'])): ?>
            | By: <?php echo htmlspecialchars($payment['created_by_first'] . ' ' . $payment['created_by_last']); ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>