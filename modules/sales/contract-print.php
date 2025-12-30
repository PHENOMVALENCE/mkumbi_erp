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

// Get contract ID with proper validation
$contract_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$contract_id || $contract_id <= 0) {
    die("Error: Contract ID is required.");
}

// Fetch contract details
try {
    $query = "
        SELECT 
            pc.*,
            r.reservation_id, r.reservation_number, r.reservation_date, 
            r.total_amount as reservation_total, r.down_payment, r.payment_periods,
            r.installment_amount, r.discount_percentage, r.discount_amount,
            c.customer_id, c.full_name as customer_name, c.first_name, c.middle_name, c.last_name,
            COALESCE(c.phone, c.phone1) as customer_phone, c.alternative_phone,
            c.email as customer_email, c.national_id as customer_id_number,
            c.address as customer_address, c.region as customer_region,
            c.district as customer_district, c.ward as customer_ward,
            p.plot_id, p.plot_number, p.block_number, p.area, p.selling_price,
            p.price_per_sqm, p.survey_plan_number, p.town_plan_number,
            p.gps_coordinates, p.corner_plot,
            pr.project_id, pr.project_name, pr.project_code,
            pr.region_name as project_region, pr.district_name as project_district,
            pr.ward_name as project_ward, pr.village_name as project_village,
            pr.physical_location as project_location,
            co.company_name, co.registration_number as company_reg_number,
            co.tax_identification_number as company_tin, co.email as company_email,
            co.phone as company_phone, co.physical_address as company_address,
            co.logo_path
        FROM plot_contracts pc
        INNER JOIN reservations r ON pc.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        INNER JOIN companies co ON pc.company_id = co.company_id
        WHERE pc.contract_id = ? AND pc.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$contract_id, $company_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        die("Error: Contract not found.");
    }
    
} catch (PDOException $e) {
    error_log("Error fetching contract for print: " . $e->getMessage());
    die("Error: Unable to retrieve contract details. " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract - <?php echo htmlspecialchars($contract['contract_number']); ?></title>
    <style>
        @page {
            size: A4;
            margin: 20mm;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #000;
            background: #fff;
        }
        
        .contract-container {
            max-width: 210mm;
            margin: 0 auto;
            padding: 20mm;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px double #000;
        }
        
        .logo {
            max-width: 150px;
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .company-name {
            font-size: 20pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 10px 0;
        }
        
        .company-details {
            font-size: 10pt;
            margin: 5px 0;
        }
        
        .document-title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 30px 0 20px 0;
            text-decoration: underline;
        }
        
        .contract-number {
            text-align: center;
            font-size: 11pt;
            margin-bottom: 30px;
        }
        
        .section {
            margin: 20px 0;
        }
        
        .section-title {
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 20px 0 10px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #000;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        .info-table td {
            padding: 8px;
            border: 1px solid #000;
        }
        
        .info-table td:first-child {
            width: 40%;
            font-weight: bold;
            background-color: #f5f5f5;
        }
        
        .clause {
            margin: 15px 0;
            text-align: justify;
        }
        
        .clause-number {
            font-weight: bold;
            margin-right: 5px;
        }
        
        .parties {
            margin: 20px 0;
        }
        
        .party {
            margin: 15px 0;
        }
        
        .party-title {
            font-weight: bold;
            text-decoration: underline;
        }
        
        .signature-section {
            margin-top: 50px;
            page-break-inside: avoid;
        }
        
        .signature-block {
            margin: 40px 0;
        }
        
        .signature-line {
            border-bottom: 1px solid #000;
            width: 250px;
            margin: 30px 0 10px 0;
        }
        
        .signature-label {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .footer {
            text-align: center;
            font-size: 9pt;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #000;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                background: white;
            }
            
            .contract-container {
                padding: 0;
            }
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .print-button:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Contract
    </button>

    <div class="contract-container">
        <!-- Header -->
        <div class="header">
            <?php if (!empty($contract['logo_path']) && file_exists('../../' . $contract['logo_path'])): ?>
            <img src="../../<?php echo htmlspecialchars($contract['logo_path']); ?>" alt="Company Logo" class="logo">
            <?php endif; ?>
            
            <div class="company-name"><?php echo htmlspecialchars($contract['company_name']); ?></div>
            
            <div class="company-details">
                <?php if (!empty($contract['company_reg_number'])): ?>
                Registration No: <?php echo htmlspecialchars($contract['company_reg_number']); ?><br>
                <?php endif; ?>
                <?php if (!empty($contract['company_tin'])): ?>
                TIN: <?php echo htmlspecialchars($contract['company_tin']); ?><br>
                <?php endif; ?>
                <?php if (!empty($contract['company_address'])): ?>
                <?php echo htmlspecialchars($contract['company_address']); ?><br>
                <?php endif; ?>
                <?php if (!empty($contract['company_phone'])): ?>
                Tel: <?php echo htmlspecialchars($contract['company_phone']); ?>
                <?php endif; ?>
                <?php if (!empty($contract['company_email'])): ?>
                <?php if (!empty($contract['company_phone'])): ?>|<?php endif; ?>
                Email: <?php echo htmlspecialchars($contract['company_email']); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Document Title -->
        <div class="document-title">
            LAND SALE AGREEMENT
        </div>
        
        <div class="contract-number">
            Contract No: <strong><?php echo htmlspecialchars($contract['contract_number']); ?></strong><br>
            Date: <strong><?php echo date('d F Y', strtotime($contract['contract_date'])); ?></strong>
        </div>

        <!-- Parties -->
        <div class="section">
            <div class="section-title">Parties to this Agreement</div>
            
            <div class="parties">
                <div class="party">
                    <div class="party-title">THE SELLER:</div>
                    <p>
                        <strong><?php echo htmlspecialchars($contract['company_name']); ?></strong>, 
                        a company duly registered under the laws of Tanzania<?php if (!empty($contract['company_address'])): ?>, 
                        with its principal place of business at <?php echo htmlspecialchars($contract['company_address']); ?><?php endif; ?><?php if (!empty($contract['company_reg_number'])): ?>,
                        Registration Number <?php echo htmlspecialchars($contract['company_reg_number']); ?><?php endif; ?>,
                        (hereinafter referred to as "the Seller").
                    </p>
                </div>
                
                <div class="party">
                    <div class="party-title">THE BUYER:</div>
                    <p>
                        <strong><?php echo htmlspecialchars($contract['customer_name']); ?></strong><?php if (!empty($contract['customer_id_number'])): ?>,
                        National ID Number <?php echo htmlspecialchars($contract['customer_id_number']); ?><?php endif; ?>,
                        of <?php 
                        $customer_address = array_filter([
                            $contract['customer_address'],
                            $contract['customer_ward'],
                            $contract['customer_district'],
                            $contract['customer_region']
                        ]);
                        echo htmlspecialchars(implode(', ', $customer_address) ?: 'Address on file');
                        ?><?php if (!empty($contract['customer_phone'])): ?>,
                        Contact: <?php echo htmlspecialchars($contract['customer_phone']); ?><?php endif; ?>,
                        (hereinafter referred to as "the Buyer").
                    </p>
                </div>
            </div>
        </div>

        <!-- Property Details -->
        <div class="section">
            <div class="section-title">Property Description</div>
            
            <table class="info-table">
                <tr>
                    <td>Project Name</td>
                    <td><?php echo htmlspecialchars($contract['project_name']); ?></td>
                </tr>
                <tr>
                    <td>Plot Number</td>
                    <td><?php echo htmlspecialchars($contract['plot_number']); ?></td>
                </tr>
                <?php if (!empty($contract['block_number'])): ?>
                <tr>
                    <td>Block Number</td>
                    <td><?php echo htmlspecialchars($contract['block_number']); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>Plot Area</td>
                    <td><?php echo number_format($contract['area'], 2); ?> Square Meters</td>
                </tr>
                <?php if (!empty($contract['survey_plan_number'])): ?>
                <tr>
                    <td>Survey Plan Number</td>
                    <td><?php echo htmlspecialchars($contract['survey_plan_number']); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>Location</td>
                    <td>
                        <?php 
                        $location = array_filter([
                            $contract['project_village'],
                            $contract['project_ward'],
                            $contract['project_district'],
                            $contract['project_region']
                        ]);
                        echo htmlspecialchars(implode(', ', $location));
                        ?>
                    </td>
                </tr>
                <?php if (!empty($contract['gps_coordinates'])): ?>
                <tr>
                    <td>GPS Coordinates</td>
                    <td><?php echo htmlspecialchars($contract['gps_coordinates']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Financial Terms -->
        <div class="section">
            <div class="section-title">Purchase Price and Payment Terms</div>
            
            <table class="info-table">
                <tr>
                    <td>Total Purchase Price</td>
                    <td><strong>TZS <?php echo number_format($contract['selling_price'], 2); ?>/=</strong></td>
                </tr>
                <tr>
                    <td>Price per Square Meter</td>
                    <td>TZS <?php echo number_format($contract['price_per_sqm'], 2); ?>/=</td>
                </tr>
                <tr>
                    <td>Down Payment</td>
                    <td>TZS <?php echo number_format($contract['down_payment'], 2); ?>/=</td>
                </tr>
                <tr>
                    <td>Payment Period</td>
                    <td><?php echo $contract['payment_periods']; ?> Months</td>
                </tr>
                <tr>
                    <td>Monthly Installment</td>
                    <td>TZS <?php echo number_format($contract['installment_amount'], 2); ?>/=</td>
                </tr>
                <?php if ($contract['discount_percentage'] > 0): ?>
                <tr>
                    <td>Discount Applied</td>
                    <td><?php echo $contract['discount_percentage']; ?>% (TZS <?php echo number_format($contract['discount_amount'], 2); ?>/=)</td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Terms and Conditions -->
        <div class="section">
            <div class="section-title">Terms and Conditions</div>
            
            <?php if (!empty($contract['terms_conditions'])): ?>
                <div style="white-space: pre-wrap; text-align: justify;">
                    <?php echo htmlspecialchars($contract['terms_conditions']); ?>
                </div>
            <?php else: ?>
                <div class="clause">
                    <span class="clause-number">1.</span>
                    <strong>SALE OF PROPERTY:</strong> The Seller agrees to sell and the Buyer agrees to purchase the above-described property for the total purchase price as stated herein.
                </div>
                
                <div class="clause">
                    <span class="clause-number">2.</span>
                    <strong>PAYMENT TERMS:</strong> The Buyer shall pay the purchase price through the payment plan specified above. All payments must be made on time as per the agreed schedule.
                </div>
                
                <div class="clause">
                    <span class="clause-number">3.</span>
                    <strong>TITLE TRANSFER:</strong> Upon full payment of the purchase price, the Seller shall execute and deliver to the Buyer a proper deed of conveyance transferring good and marketable title to the property.
                </div>
                
                <div class="clause">
                    <span class="clause-number">4.</span>
                    <strong>DEFAULT:</strong> In the event of default by the Buyer in making any payment, the Seller reserves the right to cancel this agreement and retain all payments made as liquidated damages.
                </div>
                
                <div class="clause">
                    <span class="clause-number">5.</span>
                    <strong>POSSESSION:</strong> The Buyer shall be entitled to possession of the property upon execution of this agreement, subject to full compliance with payment terms.
                </div>
                
                <div class="clause">
                    <span class="clause-number">6.</span>
                    <strong>TAXES AND CHARGES:</strong> All taxes, levies, and other charges related to the property shall be borne by the Buyer from the date of this agreement.
                </div>
                
                <div class="clause">
                    <span class="clause-number">7.</span>
                    <strong>GOVERNING LAW:</strong> This agreement shall be governed by and construed in accordance with the laws of the United Republic of Tanzania.
                </div>
            <?php endif; ?>
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="section-title">Signatures</div>
            
            <p style="margin-bottom: 30px;">
                IN WITNESS WHEREOF, the parties hereto have executed this Agreement on the day and year first above written.
            </p>
            
            <table style="width: 100%; margin-top: 40px;">
                <tr>
                    <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                        <div class="signature-block">
                            <div class="signature-label">FOR THE SELLER:</div>
                            <div class="signature-line"></div>
                            <div><strong><?php echo htmlspecialchars($contract['company_name']); ?></strong></div>
                            <div>Name: _______________________</div>
                            <div>Title: _______________________</div>
                            <div>Date: _______________________</div>
                        </div>
                    </td>
                    <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                        <div class="signature-block">
                            <div class="signature-label">FOR THE BUYER:</div>
                            <div class="signature-line"></div>
                            <div><strong><?php echo htmlspecialchars($contract['customer_name']); ?></strong></div>
                            <div>ID No: <?php echo !empty($contract['customer_id_number']) ? htmlspecialchars($contract['customer_id_number']) : '_____________________'; ?></div>
                            <div>Date: _______________________</div>
                        </div>
                    </td>
                </tr>
            </table>
            
            <div style="margin-top: 60px;">
                <div class="signature-label">WITNESSES:</div>
                <table style="width: 100%; margin-top: 20px;">
                    <tr>
                        <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                            <div class="signature-line"></div>
                            <div>Witness Name: _____________________</div>
                            <div>ID No: _____________________</div>
                            <div>Date: _____________________</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                            <div class="signature-line"></div>
                            <div>Witness Name: _____________________</div>
                            <div>ID No: _____________________</div>
                            <div>Date: _____________________</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>This is a computer-generated contract. Valid with authorized signatures.</p>
            <p>Contract Reference: <?php echo htmlspecialchars($contract['contract_number']); ?> | 
            Generated on: <?php echo date('d/m/Y H:i:s'); ?></p>
        </div>
    </div>

    <script>
        // Auto-print when page loads (optional - remove if not desired)
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>