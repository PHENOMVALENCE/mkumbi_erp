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

// Get payroll detail ID
$payroll_detail_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payroll_detail_id <= 0) {
    die('Invalid payslip ID');
}

// Fetch payslip details
try {
    $query = "
        SELECT 
            pd.*,
            p.payroll_month,
            p.payroll_year,
            p.payment_date as payroll_payment_date,
            e.employee_number,
            e.hire_date,
            e.bank_name,
            e.account_number,
            u.full_name,
            u.email,
            u.phone1,
            d.department_name,
            pos.position_title,
            c.company_name,
            c.tax_identification_number as company_tin,
            c.phone as company_phone,
            c.email as company_email,
            c.physical_address as company_address,
            c.logo_path
        FROM payroll_details pd
        INNER JOIN payroll p ON pd.payroll_id = p.payroll_id
        INNER JOIN employees e ON pd.employee_id = e.employee_id
        INNER JOIN users u ON e.user_id = u.user_id
        INNER JOIN companies c ON p.company_id = c.company_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions pos ON e.position_id = pos.position_id
        WHERE pd.payroll_detail_id = ? 
        AND p.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$payroll_detail_id, $company_id]);
    $payslip = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payslip) {
        die('Payslip not found');
    }
    
} catch (PDOException $e) {
    error_log("Error fetching payslip: " . $e->getMessage());
    die('Error loading payslip');
}

$period_name = date('F Y', mktime(0, 0, 0, $payslip['payroll_month'], 1, $payslip['payroll_year']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?php echo htmlspecialchars($payslip['full_name']); ?> - <?php echo $period_name; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 12pt;
            line-height: 1.6;
            color: #333;
            background: #fff;
        }
        
        .payslip-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 15mm;
            background: white;
        }
        
        .header {
            text-align: center;
            padding: 20px 0;
            border-bottom: 3px solid #333;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 28pt;
            color: #333;
            margin-bottom: 5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .header .period {
            font-size: 14pt;
            color: #666;
            font-weight: bold;
        }
        
        .company-section {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }
        
        .company-section h2 {
            font-size: 18pt;
            margin-bottom: 8px;
            color: #000;
        }
        
        .company-section p {
            margin: 3px 0;
            color: #555;
        }
        
        .logo-container {
            float: right;
            max-width: 150px;
        }
        
        .logo {
            max-width: 100%;
            height: auto;
        }
        
        .employee-section {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 2px solid #333;
            border-radius: 5px;
        }
        
        .section-title {
            font-size: 14pt;
            font-weight: bold;
            color: #000;
            border-bottom: 2px solid #333;
            padding-bottom: 8px;
            margin-bottom: 12px;
            text-transform: uppercase;
        }
        
        .info-table {
            width: 100%;
            margin-bottom: 10px;
        }
        
        .info-table td {
            padding: 6px;
            vertical-align: top;
        }
        
        .info-table .label {
            font-weight: bold;
            width: 35%;
            color: #333;
        }
        
        .info-table .value {
            width: 65%;
            color: #000;
        }
        
        .amounts-section {
            margin: 20px 0;
        }
        
        .amounts-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .amounts-table th {
            background: #333;
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .amounts-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
        }
        
        .amounts-table .label-col {
            width: 70%;
            font-weight: 500;
        }
        
        .amounts-table .amount-col {
            width: 30%;
            text-align: right;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        
        .amounts-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .amounts-table .subtotal-row td {
            background: #e9ecef;
            font-weight: bold;
            font-size: 11pt;
            border-top: 2px solid #333;
            padding: 12px;
        }
        
        .net-salary-section {
            background: #28a745;
            color: white;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .net-salary-section .label {
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .net-salary-section .amount {
            font-size: 24pt;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        
        .payment-info {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .payment-info h3 {
            font-size: 12pt;
            margin-bottom: 10px;
            color: #000;
        }
        
        .payment-info p {
            margin: 5px 0;
            color: #333;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #333;
            text-align: center;
        }
        
        .footer p {
            font-size: 9pt;
            color: #666;
            margin: 5px 0;
        }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 50px;
            padding-top: 8px;
            font-weight: bold;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .payslip-container {
                margin: 0;
                width: 100%;
            }
            
            @page {
                size: A4;
                margin: 0;
            }
        }
        
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }
    </style>
</head>
<body onload="window.print();">
    <div class="payslip-container">
        
        <!-- Header -->
        <div class="header">
            <h1>SALARY SLIP</h1>
            <p class="period"><?php echo $period_name; ?></p>
        </div>
        
        <!-- Company Information -->
        <div class="company-section clearfix">
            <?php if (!empty($payslip['logo_path'])): ?>
            <div class="logo-container">
                <img src="../../<?php echo htmlspecialchars($payslip['logo_path']); ?>" alt="Logo" class="logo">
            </div>
            <?php endif; ?>
            
            <h2><?php echo htmlspecialchars($payslip['company_name']); ?></h2>
            <?php if (!empty($payslip['company_address'])): ?>
            <p><?php echo htmlspecialchars($payslip['company_address']); ?></p>
            <?php endif; ?>
            <?php if (!empty($payslip['company_phone'])): ?>
            <p>Phone: <?php echo htmlspecialchars($payslip['company_phone']); ?></p>
            <?php endif; ?>
            <?php if (!empty($payslip['company_email'])): ?>
            <p>Email: <?php echo htmlspecialchars($payslip['company_email']); ?></p>
            <?php endif; ?>
            <?php if (!empty($payslip['company_tin'])): ?>
            <p>TIN: <?php echo htmlspecialchars($payslip['company_tin']); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Employee Information -->
        <div class="employee-section">
            <div class="section-title">Employee Information</div>
            
            <table class="info-table">
                <tr>
                    <td class="label">Employee Name:</td>
                    <td class="value"><?php echo htmlspecialchars($payslip['full_name']); ?></td>
                    <td class="label">Employee Number:</td>
                    <td class="value"><?php echo htmlspecialchars($payslip['employee_number']); ?></td>
                </tr>
                <tr>
                    <td class="label">Department:</td>
                    <td class="value"><?php echo htmlspecialchars($payslip['department_name'] ?? 'N/A'); ?></td>
                    <td class="label">Position:</td>
                    <td class="value"><?php echo htmlspecialchars($payslip['position_title'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="label">Email:</td>
                    <td class="value"><?php echo htmlspecialchars($payslip['email']); ?></td>
                    <td class="label">Date of Joining:</td>
                    <td class="value"><?php echo date('M j, Y', strtotime($payslip['hire_date'])); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Earnings -->
        <div class="amounts-section">
            <table class="amounts-table">
                <thead>
                    <tr>
                        <th colspan="2">EARNINGS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="label-col">Basic Salary</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['basic_salary'], 2); ?></td>
                    </tr>
                    <?php if ($payslip['allowances'] > 0): ?>
                    <tr>
                        <td class="label-col">Allowances</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['allowances'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($payslip['overtime_pay'] > 0): ?>
                    <tr>
                        <td class="label-col">Overtime Pay</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['overtime_pay'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($payslip['bonus'] > 0): ?>
                    <tr>
                        <td class="label-col">Bonus</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['bonus'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="subtotal-row">
                        <td>GROSS SALARY</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['gross_salary'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Deductions -->
        <div class="amounts-section">
            <table class="amounts-table">
                <thead>
                    <tr>
                        <th colspan="2">DEDUCTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($payslip['tax_amount'] > 0): ?>
                    <tr>
                        <td class="label-col">Income Tax (PAYE)</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['tax_amount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($payslip['nssf_amount'] > 0): ?>
                    <tr>
                        <td class="label-col">NSSF Contribution</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['nssf_amount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($payslip['nhif_amount'] > 0): ?>
                    <tr>
                        <td class="label-col">NHIF Contribution</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['nhif_amount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($payslip['loan_deduction'] > 0): ?>
                    <tr>
                        <td class="label-col">Loan Deduction</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['loan_deduction'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($payslip['other_deductions'] > 0): ?>
                    <tr>
                        <td class="label-col">Other Deductions</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['other_deductions'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="subtotal-row">
                        <td>TOTAL DEDUCTIONS</td>
                        <td class="amount-col">TSH <?php echo number_format($payslip['total_deductions'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Net Salary -->
        <div class="net-salary-section">
            <div class="label">NET SALARY PAYABLE</div>
            <div class="amount">TSH <?php echo number_format($payslip['net_salary'], 2); ?></div>
        </div>
        
        <!-- Payment Information -->
        <?php if (!empty($payslip['bank_name']) || !empty($payslip['account_number'])): ?>
        <div class="payment-info">
            <h3>PAYMENT DETAILS</h3>
            <?php if (!empty($payslip['bank_name'])): ?>
            <p><strong>Bank Name:</strong> <?php echo htmlspecialchars($payslip['bank_name']); ?></p>
            <?php endif; ?>
            <?php if (!empty($payslip['account_number'])): ?>
            <p><strong>Account Number:</strong> <?php echo htmlspecialchars($payslip['account_number']); ?></p>
            <?php endif; ?>
            <?php if ($payslip['payment_date']): ?>
            <p><strong>Payment Date:</strong> <?php echo date('F j, Y', strtotime($payslip['payment_date'])); ?></p>
            <?php endif; ?>
            <?php if (!empty($payslip['payment_reference'])): ?>
            <p><strong>Payment Reference:</strong> <?php echo htmlspecialchars($payslip['payment_reference']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Employee Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized By</div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p><strong>This is a computer-generated payslip and does not require a signature.</strong></p>
            <p>For any queries regarding this payslip, please contact the Human Resources Department.</p>
            <p>Generated on: <?php echo date('F j, Y g:i A'); ?></p>
        </div>
        
    </div>
</body>
</html>