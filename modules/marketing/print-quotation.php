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

// Get quotation ID
$quotation_id = $_GET['id'] ?? 0;

if (!$quotation_id) {
    die("Invalid quotation ID");
}

// Fetch company details
try {
    $company_stmt = $conn->prepare("
        SELECT * FROM companies WHERE company_id = ?
    ");
    $company_stmt->execute([$company_id]);
    $company = $company_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $company = null;
}

// Fetch quotation with customer/lead details
try {
    $stmt = $conn->prepare("
        SELECT 
            q.*,
            c.first_name,
            c.last_name,
            c.email as customer_email,
            COALESCE(c.phone, c.phone1) as customer_phone,
            c.address as customer_address,
            l.full_name as lead_name,
            l.email as lead_email,
            l.phone as lead_phone,
            l.company_name as lead_company
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.customer_id
        LEFT JOIN leads l ON q.lead_id = l.lead_id
        WHERE q.quotation_id = ? AND q.company_id = ?
    ");
    $stmt->execute([$quotation_id, $company_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quotation) {
        die("Quotation not found");
    }
} catch (PDOException $e) {
    die("Error loading quotation: " . $e->getMessage());
}

// Fetch quotation items
try {
    $items_stmt = $conn->prepare("
        SELECT * FROM quotation_items 
        WHERE quotation_id = ?
        ORDER BY item_id ASC
    ");
    $items_stmt->execute([$quotation_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
}

// Determine customer/lead name and details
$client_name = '';
$client_email = '';
$client_phone = '';
$client_address = '';

if ($quotation['customer_id']) {
    $client_name = trim($quotation['first_name'] . ' ' . $quotation['last_name']);
    $client_email = $quotation['customer_email'];
    $client_phone = $quotation['customer_phone'];
    $client_address = $quotation['customer_address'];
} elseif ($quotation['lead_id']) {
    $client_name = $quotation['lead_name'];
    $client_email = $quotation['lead_email'];
    $client_phone = $quotation['lead_phone'];
    if ($quotation['lead_company']) {
        $client_name .= ' (' . $quotation['lead_company'] . ')';
    }
}

// Helper function
function safe_number($value) {
    return number_format((float)$value, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation <?php echo htmlspecialchars($quotation['quotation_number']); ?></title>
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
            background: #fff;
        }
        
        .container {
            max-width: 210mm;
            margin: 0 auto;
            padding: 20mm;
            background: white;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #667eea;
        }
        
        .company-logo {
            max-width: 200px;
            max-height: 80px;
        }
        
        .company-info {
            text-align: left;
        }
        
        .company-info h1 {
            font-size: 24pt;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .company-info p {
            margin: 2px 0;
            font-size: 9pt;
            color: #666;
        }
        
        .quotation-info {
            text-align: right;
        }
        
        .quotation-title {
            font-size: 28pt;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .quotation-number {
            font-size: 14pt;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .quotation-meta {
            font-size: 9pt;
            color: #666;
        }
        
        /* Client Details */
        .details-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        
        .detail-box {
            width: 48%;
        }
        
        .detail-box h3 {
            font-size: 11pt;
            color: #667eea;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e5e7eb;
            font-weight: 600;
        }
        
        .detail-box p {
            margin: 5px 0;
            font-size: 10pt;
        }
        
        .detail-box strong {
            color: #555;
            display: inline-block;
            width: 80px;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        
        .items-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .items-table th {
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 10pt;
        }
        
        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }
        
        .items-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .items-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .items-table td {
            padding: 10px;
            font-size: 10pt;
            vertical-align: top;
        }
        
        .item-description {
            color: #666;
            font-size: 9pt;
            white-space: pre-line;
            line-height: 1.4;
        }
        
        /* Totals */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }
        
        .totals-table {
            width: 350px;
        }
        
        .totals-table tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .totals-table td {
            padding: 10px;
            font-size: 10pt;
        }
        
        .totals-table td:last-child {
            text-align: right;
            font-weight: 600;
        }
        
        .totals-table .total-row {
            background: #f3f4f6;
            font-weight: 700;
            font-size: 12pt;
        }
        
        .totals-table .total-row td {
            color: #667eea;
            padding: 15px 10px;
            border-top: 2px solid #667eea;
        }
        
        /* Terms Section */
        .terms-section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .terms-section h3 {
            font-size: 11pt;
            color: #667eea;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .terms-section p,
        .terms-section ul {
            font-size: 9pt;
            color: #555;
            line-height: 1.6;
            white-space: pre-line;
        }
        
        .terms-section ul {
            margin-left: 20px;
        }
        
        .terms-section li {
            margin-bottom: 5px;
        }
        
        /* Footer */
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            font-size: 9pt;
            color: #666;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 60px;
            margin-bottom: 40px;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 50px;
            padding-top: 10px;
            font-size: 10pt;
            font-weight: 600;
        }
        
        .signature-date {
            font-size: 9pt;
            color: #666;
            margin-top: 5px;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-draft { background: #e5e7eb; color: #374151; }
        .status-sent { background: #dbeafe; color: #1e40af; }
        .status-accepted { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-expired { background: #fef3c7; color: #92400e; }
        
        /* Print Styles */
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .container {
                max-width: 100%;
                margin: 0;
                padding: 15mm;
            }
            
            .no-print {
                display: none !important;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            .items-table tbody tr:hover {
                background: transparent;
            }
        }
        
        /* Print Button */
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 11pt;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            z-index: 1000;
        }
        
        .print-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }
        
        .print-button i {
            margin-right: 8px;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Print Button -->
    <button class="print-button no-print" onclick="window.print()">
        <i class="fas fa-print"></i>Print / Save as PDF
    </button>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <?php if (file_exists('../../assets/img/logo.jpg')): ?>
                    <img src="../../assets/img/logo.jpg" alt="Company Logo" class="company-logo">
                <?php else: ?>
                    <h1><?php echo htmlspecialchars($company['company_name'] ?? 'Company Name'); ?></h1>
                <?php endif; ?>
                
                <?php if ($company): ?>
                <p>
                    <?php if (!empty($company['address'])): ?>
                        <?php echo htmlspecialchars($company['address']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($company['phone'])): ?>
                        <strong>Phone:</strong> <?php echo htmlspecialchars($company['phone']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($company['email'])): ?>
                        <strong>Email:</strong> <?php echo htmlspecialchars($company['email']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($company['website'])): ?>
                        <strong>Website:</strong> <?php echo htmlspecialchars($company['website']); ?>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
            
            <div class="quotation-info">
                <div class="quotation-title">QUOTATION</div>
                <div class="quotation-number"><?php echo htmlspecialchars($quotation['quotation_number']); ?></div>
                <div class="quotation-meta">
                    <p><strong>Date:</strong> <?php echo date('d M Y', strtotime($quotation['quotation_date'])); ?></p>
                    <p><strong>Valid Until:</strong> <?php echo date('d M Y', strtotime($quotation['valid_until_date'])); ?></p>
                    <p>
                        <span class="status-badge status-<?php echo $quotation['status']; ?>">
                            <?php echo ucfirst($quotation['status']); ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Client Details -->
        <div class="details-section">
            <div class="detail-box">
                <h3>Quotation For:</h3>
                <?php if ($client_name): ?>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($client_name); ?></p>
                <?php endif; ?>
                <?php if ($client_email): ?>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($client_email); ?></p>
                <?php endif; ?>
                <?php if ($client_phone): ?>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($client_phone); ?></p>
                <?php endif; ?>
                <?php if ($client_address): ?>
                    <p><strong>Address:</strong> <?php echo htmlspecialchars($client_address); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="detail-box">
                <h3>Quotation Details:</h3>
                <p><strong>Number:</strong> <?php echo htmlspecialchars($quotation['quotation_number']); ?></p>
                <p><strong>Date:</strong> <?php echo date('d M Y', strtotime($quotation['quotation_date'])); ?></p>
                <p><strong>Valid Until:</strong> <?php echo date('d M Y', strtotime($quotation['valid_until_date'])); ?></p>
                <p><strong>Status:</strong> <span class="status-badge status-<?php echo $quotation['status']; ?>"><?php echo ucfirst($quotation['status']); ?></span></p>
            </div>
        </div>
        
        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 45%;">Description</th>
                    <th style="width: 10%;">Qty</th>
                    <th style="width: 10%;">Unit</th>
                    <th style="width: 15%;">Unit Price</th>
                    <th style="width: 15%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($items as $item): 
                ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td>
                        <div class="item-description"><?php echo nl2br(htmlspecialchars($item['item_description'])); ?></div>
                    </td>
                    <td><?php echo safe_number($item['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                    <td>TSH <?php echo safe_number($item['unit_price']); ?></td>
                    <td>TSH <?php echo safe_number($item['total_price']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td>TSH <?php echo safe_number($quotation['subtotal']); ?></td>
                </tr>
                <?php if ($quotation['tax_amount'] > 0): ?>
                <tr>
                    <td>Tax (VAT):</td>
                    <td>TSH <?php echo safe_number($quotation['tax_amount']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($quotation['discount_amount'] > 0): ?>
                <tr>
                    <td>Discount:</td>
                    <td>- TSH <?php echo safe_number($quotation['discount_amount']); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>TOTAL AMOUNT:</td>
                    <td>TSH <?php echo safe_number($quotation['total_amount']); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Payment Terms -->
        <?php if ($quotation['payment_terms']): ?>
        <div class="terms-section">
            <h3>Payment Terms:</h3>
            <p><?php echo nl2br(htmlspecialchars($quotation['payment_terms'])); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Delivery Terms -->
        <?php if ($quotation['delivery_terms']): ?>
        <div class="terms-section">
            <h3>Delivery Terms:</h3>
            <p><?php echo nl2br(htmlspecialchars($quotation['delivery_terms'])); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Terms & Conditions -->
        <?php if ($quotation['terms_conditions']): ?>
        <div class="terms-section">
            <h3>Terms & Conditions:</h3>
            <p><?php echo nl2br(htmlspecialchars($quotation['terms_conditions'])); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Default Terms if none provided -->
        <?php if (!$quotation['terms_conditions']): ?>
        <div class="terms-section">
            <h3>Terms & Conditions:</h3>
            <ul>
                <li>This quotation is valid for <?php echo floor((strtotime($quotation['valid_until_date']) - strtotime($quotation['quotation_date'])) / 86400); ?> days from the date of issue.</li>
                <li>Prices quoted are in Tanzanian Shillings (TSH) and are subject to change without notice after the validity period.</li>
                <li>Payment terms and delivery schedule as specified above.</li>
                <li>Any additional requirements or modifications may affect the final pricing.</li>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Signature Section -->
        <div class="signature-section no-print">
            <div class="signature-box">
                <div class="signature-line">
                    <?php echo htmlspecialchars($company['company_name'] ?? 'Company Name'); ?>
                </div>
                <div class="signature-date">Authorized Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">
                    <?php echo htmlspecialchars($client_name); ?>
                </div>
                <div class="signature-date">Customer Signature & Date</div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>
                <strong><?php echo htmlspecialchars($company['company_name'] ?? 'Company Name'); ?></strong><br>
                Thank you for your business!
            </p>
            <p style="margin-top: 10px; font-size: 8pt; color: #999;">
                This is a computer-generated quotation and is valid without signature.
            </p>
        </div>
    </div>
    
    <script>
        // Auto-focus for printing
        window.onload = function() {
            // Optional: Auto-print on load (uncomment if needed)
            // window.print();
        };
    </script>
</body>
</html>