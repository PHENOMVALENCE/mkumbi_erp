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

// Get voucher ID
$voucher_id = $_GET['id'] ?? 0;

if (!$voucher_id) {
    die('Invalid voucher ID.');
}

// Fetch voucher details
try {
    $query = "
        SELECT 
            pv.*,
            c.customer_id,
            c.full_name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            c.address as customer_address,
            c.id_number as customer_id_number,
            r.reservation_number,
            r.total_amount as reservation_total,
            r.down_payment,
            pl.plot_id,
            pl.plot_number,
            pl.block_number,
            pl.area,
            pl.price_per_sqm,
            pr.project_name,
            pr.physical_location as project_location,
            p.payment_number,
            p.payment_method,
            p.payment_date,
            p.receipt_number,
            creator.full_name as created_by_name,
            approver.full_name as approved_by_name,
            comp.company_name,
            comp.phone as company_phone,
            comp.email as company_email,
            comp.physical_address as company_address,
            comp.logo_path,
            comp.tax_identification_number as company_tin
        FROM payment_vouchers pv
        LEFT JOIN customers c ON pv.customer_id = c.customer_id
        LEFT JOIN reservations r ON pv.reservation_id = r.reservation_id
        LEFT JOIN plots pl ON r.plot_id = pl.plot_id
        LEFT JOIN projects pr ON pl.project_id = pr.project_id
        LEFT JOIN payments p ON pv.payment_id = p.payment_id
        LEFT JOIN users creator ON pv.created_by = creator.user_id
        LEFT JOIN users approver ON pv.approved_by = approver.user_id
        LEFT JOIN companies comp ON pv.company_id = comp.company_id
        WHERE pv.voucher_id = ? AND pv.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$voucher_id, $company_id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$voucher) {
        die('Voucher not found.');
    }
    
} catch (PDOException $e) {
    error_log("Error fetching voucher: " . $e->getMessage());
    die('Error loading voucher details.');
}

/**
 * Convert number to words
 */
function convertNumberToWords($number) {
    $ones = array(
        '', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE',
        'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN',
        'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'
    );
    
    $tens = array(
        '', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'
    );
    
    $number = (int)$number;
    
    if ($number == 0) {
        return 'ZERO';
    }
    
    if ($number < 0) {
        return 'MINUS ' . convertNumberToWords(abs($number));
    }
    
    $words = '';
    
    if ($number >= 1000000000) {
        $billions = intval($number / 1000000000);
        $words .= convertNumberToWords($billions) . ' BILLION ';
        $number %= 1000000000;
    }
    
    if ($number >= 1000000) {
        $millions = intval($number / 1000000);
        $words .= convertNumberToWords($millions) . ' MILLION ';
        $number %= 1000000;
    }
    
    if ($number >= 1000) {
        $thousands = intval($number / 1000);
        $words .= convertNumberToWords($thousands) . ' THOUSAND ';
        $number %= 1000;
    }
    
    if ($number >= 100) {
        $hundreds = intval($number / 100);
        $words .= $ones[$hundreds] . ' HUNDRED ';
        $number %= 100;
    }
    
    if ($number >= 20) {
        $words .= $tens[intval($number / 10)] . ' ';
        $number %= 10;
    }
    
    if ($number > 0) {
        $words .= $ones[$number] . ' ';
    }
    
    return trim($words);
}

// Get voucher type info
$voucherTypes = [
    'payment' => ['name' => 'PAYMENT VOUCHER', 'color' => '#0d6efd'],
    'receipt' => ['name' => 'RECEIPT VOUCHER', 'color' => '#198754'],
    'refund' => ['name' => 'REFUND VOUCHER', 'color' => '#ffc107'],
    'adjustment' => ['name' => 'ADJUSTMENT VOUCHER', 'color' => '#6c757d']
];

$voucherTypeInfo = $voucherTypes[$voucher['voucher_type']] ?? ['name' => 'VOUCHER', 'color' => '#007bff'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Voucher - <?php echo htmlspecialchars($voucher['voucher_number']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
        }
        
        .container {
            max-width: 210mm;
            margin: 20px auto;
            background: white;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #007bff;
        }
        
        .company-info {
            flex: 1;
        }
        
        .company-logo {
            max-width: 150px;
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .company-name {
            font-size: 20pt;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .company-details {
            font-size: 9pt;
            color: #6c757d;
            line-height: 1.4;
        }
        
        .voucher-banner {
            background: <?php echo $voucherTypeInfo['color']; ?>;
            color: white;
            text-align: center;
            padding: 15px;
            margin: 20px 0;
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .voucher-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 10px;
            background: #f8f9fa;
        }
        
        .voucher-number {
            font-size: 13pt;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 9pt;
            font-weight: bold;
            margin-top: 5px;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .section {
            margin-bottom: 20px;
        }
        
        .section-title {
            background: #f8f9fa;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 10px;
            border-left: 4px solid #007bff;
        }
        
        .info-table {
            width: 100%;
            margin-bottom: 10px;
        }
        
        .info-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-label {
            width: 150px;
            font-weight: bold;
            color: #6c757d;
            flex-shrink: 0;
        }
        
        .info-value {
            flex: 1;
            color: #212529;
        }
        
        .amount-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            text-align: center;
            margin: 25px 0;
            border-radius: 8px;
        }
        
        .amount-label {
            font-size: 10pt;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .amount-value {
            font-size: 28pt;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .amount-words {
            font-size: 11pt;
            font-style: italic;
            opacity: 0.95;
            margin-top: 10px;
        }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 45%;
        }
        
        .signature-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .signature-name {
            font-weight: bold;
            color: #007bff;
            margin: 5px 0;
        }
        
        .signature-date {
            font-size: 9pt;
            color: #6c757d;
            margin-bottom: 25px;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            padding-top: 5px;
            font-size: 9pt;
            color: #6c757d;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 9pt;
            color: #6c757d;
            font-style: italic;
        }
        
        .no-print {
            text-align: center;
            margin: 20px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 11pt;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        @media print {
            body {
                background: white;
            }
            
            .container {
                max-width: 100%;
                margin: 0;
                padding: 15mm;
                box-shadow: none;
            }
            
            .no-print {
                display: none !important;
            }
            
            .page-break {
                page-break-after: always;
            }
        }
        
        @page {
            size: A4;
            margin: 15mm;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" class="btn">
            <i class="fas fa-print"></i> Print Voucher
        </button>
        <a href="view-voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Voucher
        </a>
        <a href="vouchers.php" class="btn btn-secondary">
            <i class="fas fa-list"></i> Back to List
        </a>
    </div>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <?php if (!empty($voucher['logo_path']) && file_exists('../../' . $voucher['logo_path'])): ?>
                <img src="../../<?php echo htmlspecialchars($voucher['logo_path']); ?>" alt="Company Logo" class="company-logo">
                <?php endif; ?>
                <div class="company-name"><?php echo htmlspecialchars($voucher['company_name']); ?></div>
                <div class="company-details">
                    <?php if (!empty($voucher['company_address'])): ?>
                    <?php echo htmlspecialchars($voucher['company_address']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($voucher['company_phone'])): ?>
                    Tel: <?php echo htmlspecialchars($voucher['company_phone']); ?>
                    <?php endif; ?>
                    <?php if (!empty($voucher['company_email'])): ?>
                    | Email: <?php echo htmlspecialchars($voucher['company_email']); ?>
                    <?php endif; ?>
                    <?php if (!empty($voucher['company_tin'])): ?>
                    <br>TIN: <?php echo htmlspecialchars($voucher['company_tin']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Voucher Type Banner -->
        <div class="voucher-banner">
            <?php echo $voucherTypeInfo['name']; ?>
        </div>
        
        <!-- Voucher Meta -->
        <div class="voucher-meta">
            <div>
                <div class="voucher-number">Voucher No: <?php echo htmlspecialchars($voucher['voucher_number']); ?></div>
                <span class="status-badge status-<?php echo $voucher['approval_status']; ?>">
                    <?php echo strtoupper($voucher['approval_status']); ?>
                </span>
            </div>
            <div style="text-align: right;">
                <div><strong>Date:</strong></div>
                <div><?php echo date('d/m/Y', strtotime($voucher['voucher_date'])); ?></div>
            </div>
        </div>
        
        <!-- Customer Information -->
        <div class="section">
            <div class="section-title">CUSTOMER INFORMATION</div>
            <div class="info-table">
                <?php if (!empty($voucher['customer_name'])): ?>
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['customer_name']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['customer_phone'])): ?>
                <div class="info-row">
                    <div class="info-label">Phone:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['customer_phone']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['customer_email'])): ?>
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['customer_email']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['customer_id_number'])): ?>
                <div class="info-row">
                    <div class="info-label">ID Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['customer_id_number']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['customer_address'])): ?>
                <div class="info-row">
                    <div class="info-label">Address:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['customer_address']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Transaction Details -->
        <div class="section">
            <div class="section-title">TRANSACTION DETAILS</div>
            <div class="info-table">
                <?php if (!empty($voucher['payment_number'])): ?>
                <div class="info-row">
                    <div class="info-label">Payment Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['payment_number']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['receipt_number'])): ?>
                <div class="info-row">
                    <div class="info-label">Receipt Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['receipt_number']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['payment_method'])): ?>
                <div class="info-row">
                    <div class="info-label">Payment Method:</div>
                    <div class="info-value"><?php echo ucwords(str_replace('_', ' ', $voucher['payment_method'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['payment_date'])): ?>
                <div class="info-row">
                    <div class="info-label">Payment Date:</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($voucher['payment_date'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['reservation_number'])): ?>
                <div class="info-row">
                    <div class="info-label">Reservation No:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['reservation_number']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Plot Information -->
        <?php if (!empty($voucher['plot_number'])): ?>
        <div class="section">
            <div class="section-title">PLOT INFORMATION</div>
            <div class="info-table">
                <?php if (!empty($voucher['project_name'])): ?>
                <div class="info-row">
                    <div class="info-label">Project Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['project_name']); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <div class="info-label">Plot Number:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($voucher['plot_number']); ?>
                        <?php if (!empty($voucher['block_number'])): ?>
                        - Block <?php echo htmlspecialchars($voucher['block_number']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($voucher['area'])): ?>
                <div class="info-row">
                    <div class="info-label">Plot Area:</div>
                    <div class="info-value"><?php echo number_format($voucher['area'], 2); ?> sq.m</div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['price_per_sqm'])): ?>
                <div class="info-row">
                    <div class="info-label">Price per Sq.m:</div>
                    <div class="info-value">TSH <?php echo number_format($voucher['price_per_sqm'], 2); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['project_location'])): ?>
                <div class="info-row">
                    <div class="info-label">Location:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['project_location']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Bank Details -->
        <?php if (!empty($voucher['bank_name']) || !empty($voucher['transaction_reference']) || !empty($voucher['cheque_number'])): ?>
        <div class="section">
            <div class="section-title">BANK/TRANSACTION DETAILS</div>
            <div class="info-table">
                <?php if (!empty($voucher['bank_name'])): ?>
                <div class="info-row">
                    <div class="info-label">Bank Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['bank_name']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['account_number'])): ?>
                <div class="info-row">
                    <div class="info-label">Account Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['account_number']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['cheque_number'])): ?>
                <div class="info-row">
                    <div class="info-label">Cheque Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['cheque_number']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($voucher['transaction_reference'])): ?>
                <div class="info-row">
                    <div class="info-label">Transaction Ref:</div>
                    <div class="info-value"><?php echo htmlspecialchars($voucher['transaction_reference']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Amount Section -->
        <div class="amount-section">
            <div class="amount-label">VOUCHER AMOUNT</div>
            <div class="amount-value">TSH <?php echo number_format($voucher['amount'], 2); ?></div>
            <div class="amount-words">
                <?php echo ucwords(strtolower(convertNumberToWords($voucher['amount']))); ?> Only
            </div>
        </div>
        
        <!-- Description -->
        <?php if (!empty($voucher['description'])): ?>
        <div class="section">
            <div class="section-title">DESCRIPTION</div>
            <div style="padding: 10px; background: #f8f9fa;">
                <?php echo nl2br(htmlspecialchars($voucher['description'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-title">Prepared By:</div>
                <div class="signature-name"><?php echo htmlspecialchars($voucher['created_by_name']); ?></div>
                <div class="signature-date"><?php echo date('d/m/Y H:i', strtotime($voucher['created_at'])); ?></div>
                <div class="signature-line">Signature</div>
            </div>
            
            <?php if ($voucher['approval_status'] == 'approved' && !empty($voucher['approved_by_name'])): ?>
            <div class="signature-box">
                <div class="signature-title">Approved By:</div>
                <div class="signature-name"><?php echo htmlspecialchars($voucher['approved_by_name']); ?></div>
                <div class="signature-date"><?php echo date('d/m/Y H:i', strtotime($voucher['approved_at'])); ?></div>
                <div class="signature-line">Signature & Stamp</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            This is a computer-generated voucher. For any queries, please contact <?php echo htmlspecialchars($voucher['company_phone']); ?><br>
            Generated on: <?php echo date('d/m/Y H:i:s'); ?>
        </div>
    </div>
    
    <div class="no-print" style="text-align: center; margin: 20px;">
        <button onclick="window.print()" class="btn">
            <i class="fas fa-print"></i> Print Voucher
        </button>
    </div>
</body>
</html>