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

$customer_id = $_GET['customer_id'] ?? null;

if (!$customer_id) {
    die("No customer specified");
}

// Fetch company details
try {
    $company_query = "SELECT company_name, email, phone, physical_address, logo_path, tax_identification_number 
                      FROM companies WHERE company_id = ?";
    $stmt = $conn->prepare($company_query);
    $stmt->execute([$company_id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $company = [];
}


// Get customer details
try {
    $customer_sql = "SELECT customer_id, full_name as customer_name,
                            phone, phone1, email, region, district, ward, street_address
                     FROM customers 
                     WHERE customer_id = ? AND company_id = ? AND is_active = 1";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->execute([$customer_id, $company_id]);
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        die("Customer not found");
    }
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Get all reservations for this customer
try {
    $reservations_sql = "SELECT 
        r.reservation_id,
        r.reservation_date,
        r.reservation_number,
        r.total_amount,
        pl.plot_number,
        pl.block_number,
        pr.project_name,
        COALESCE(SUM(p.amount), 0) as total_paid
    FROM reservations r
    INNER JOIN plots pl ON r.plot_id = pl.plot_id
    INNER JOIN projects pr ON pl.project_id = pr.project_id
    LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
    WHERE r.customer_id = ? AND r.company_id = ? AND r.is_active = 1
    GROUP BY r.reservation_id, r.reservation_date, r.reservation_number, r.total_amount,
             pl.plot_number, pl.block_number, pr.project_name
    ORDER BY r.reservation_date ASC";
    
    $res_stmt = $conn->prepare($reservations_sql);
    $res_stmt->execute([$customer_id, $company_id]);
    $reservations = $res_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reservations = [];
}

// Get all payments for this customer
try {
    $payments_sql = "SELECT 
        p.payment_date,
        p.payment_number,
        p.amount,
        p.payment_method,
        p.receipt_number,
        p.transaction_reference,
        r.reservation_number
    FROM payments p
    INNER JOIN reservations r ON p.reservation_id = r.reservation_id
    WHERE r.customer_id = ? AND r.company_id = ? AND p.status = 'approved'
    ORDER BY p.payment_date ASC";
    
    $pay_stmt = $conn->prepare($payments_sql);
    $pay_stmt->execute([$customer_id, $company_id]);
    $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
}

// Calculate statistics
$total_amount = array_sum(array_column($reservations, 'total_amount'));
$total_credits = array_sum(array_column($payments, 'amount'));
$closing_balance = $total_amount - $total_credits;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Statement - <?= htmlspecialchars($customer['customer_name']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12pt;
            line-height: 1.6;
            color: #333;
            padding: 20px;
        }

        .statement-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
        }

  /* --- Header Section --- */
.statement-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 5px;
}

.header-left img {
    max-width: 60px;
    max-height: 55px;
    object-fit: contain;
}

.header-right {
    text-align: left;
    line-height: 1.3;
}

.header-right h1 {
    color: #667eea; /* restored theme color */
    font-size: 13pt; /* compact like the receipt */
    font-weight: bold;
    margin: 0 0 2px 0;
}

.header-right p {
    color: #444;
    font-size: 8.5pt; /* smaller contact info */
    margin: 0;
}

.divider {
    border: none;
    border-top: 2px solid #667eea; /* theme color line */
    margin: 5px 0 8px 0;
}

.sub-header {
    text-align: center;
    margin-bottom: 10px;
}

.sub-header h3 {
    color: #667eea; /* theme color for title */
    font-size: 11pt; /* smaller title font */
    font-weight: bold;
    margin: 0;
}

.sub-header p {
    color: #555;
    font-size: 8.5pt;
    margin: 0;
}

/* --- Buttons --- */
.btn {
    background: #667eea; /* theme color background */
    color: white;
    border: none;
    padding: 4px 12px; /* compact size */
    margin: 0 2px; /* reduced space between buttons */
    cursor: pointer;
    font-weight: bold;
    font-size: 9pt;
    text-transform: uppercase;
    border-radius: 4px;
}

.btn:hover {
    background: #556cd6; /* darker hover shade */
}

.no-print {
    text-align: center;
    margin-bottom: 8px;
}




        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-box {
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 6px;
        }

        .info-box h3 {
            color: #667eea;
            font-size: 12pt;
            margin-bottom: 10px;
            text-transform: uppercase;
            border-bottom: 2px solid #667eea;
            padding-bottom: 5px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 10pt;
        }

        .info-label {
            font-weight: 600;
            color: #666;
        }

        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .transactions-table th {
            background: #667eea;
            color: white;
            padding: 12px 8px;
            text-align: left;
            font-size: 10pt;
            font-weight: 600;
        }

        .transactions-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #dee2e6;
            font-size: 10pt;
        }

        .transactions-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .text-right {
            text-align: right !important;
        }

        .text-danger {
            color: #dc3545;
            font-weight: 600;
        }

        .text-success {
            color: #28a745;
            font-weight: 600;
        }

        .summary-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            border: 2px solid #667eea;
            margin-bottom: 30px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 12pt;
        }

        .summary-row.total {
            font-size: 14pt;
            font-weight: 700;
            color: #667eea;
            border-top: 2px solid #667eea;
            padding-top: 15px;
            margin-top: 10px;
        }

        .statement-footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            color: #666;
            font-size: 10pt;
        }

        @media print {
            body {
                padding: 0;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="statement-container">
    <div class="statement-header">
    <div class="header-left">
        <?php if (!empty($company['logo_path']) && file_exists('../../' . $company['logo_path'])): ?>
            <img src="../../<?php echo htmlspecialchars($company['logo_path']); ?>" alt="Logo">
        <?php endif; ?>
    </div>
    <div class="header-right">
        <h1><?php echo htmlspecialchars($company['company_name'] ?? 'COMPANY NAME'); ?></h1>
        <p>
            <?php echo htmlspecialchars($company['physical_address'] ?? ''); ?> |
            Tel: <?php echo htmlspecialchars($company['phone'] ?? 'N/A'); ?> |
            <?php echo htmlspecialchars($company['email'] ?? ''); ?>
            <?php if (!empty($company['tax_identification_number'])): ?>
                <br>TIN: <?php echo htmlspecialchars($company['tax_identification_number']); ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<hr class="divider">

<div class="sub-header">
    <h3>CUSTOMER STATEMENT</h3>
    <p>Account Activity Report</p>
</div>



        <div class="info-section">
            <div class="info-box">
                <h3>Customer Information</h3>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span><?= htmlspecialchars($customer['customer_name']) ?></span>
                </div>
                <?php if ($customer['phone'] || $customer['phone1']): ?>
                <div class="info-row">
                    <span class="info-label">Phone:</span>
                    <span><?= htmlspecialchars($customer['phone'] ?? $customer['phone1']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($customer['email']): ?>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span><?= htmlspecialchars($customer['email']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($customer['street_address']): ?>
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span><?= htmlspecialchars($customer['street_address']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="info-box">
                <h3>Statement Details</h3>
                <div class="info-row">
                    <span class="info-label">Statement Date:</span>
                    <span><?= date('F d, Y') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Customer ID:</span>
                    <span><?= $customer['customer_id'] ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Reservations:</span>
                    <span><?= count($reservations) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Payments:</span>
                    <span><?= count($payments) ?></span>
                </div>
            </div>
        </div>

        <h3 style="color: #667eea; margin-bottom: 15px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
            Reservations
        </h3>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reservation #</th>
                    <th>Plot/Project</th>
                    <th class="text-right">Amount (TZS)</th>
                    <th class="text-right">Paid (TZS)</th>
                    <th class="text-right">Balance (TZS)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #999;">
                            No reservations found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reservations as $res): ?>
                        <?php $balance = $res['total_amount'] - $res['total_paid']; ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($res['reservation_date'])) ?></td>
                            <td><?= htmlspecialchars($res['reservation_number']) ?></td>
                            <td>
                                Plot <?= htmlspecialchars($res['plot_number']) ?>
                                <?php if ($res['block_number']): ?>
                                    Block <?= htmlspecialchars($res['block_number']) ?>
                                <?php endif; ?>
                                <br>
                                <small style="color: #666;"><?= htmlspecialchars($res['project_name']) ?></small>
                            </td>
                            <td class="text-right" style="font-weight: 600;">
                                <?= number_format($res['total_amount'], 2) ?>
                            </td>
                            <td class="text-right" style="font-weight: 600;">
                                <span class="text-success"><?= number_format($res['total_paid'], 2) ?></span>
                            </td>
                            <td class="text-right" style="font-weight: 600;">
                                <span class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($balance, 2) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="color: #667eea; margin: 30px 0 15px 0; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
            Payment History
        </h3>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Payment #</th>
                    <th>Reservation</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-right">Amount (TZS)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #999;">
                            No payments recorded
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $pay): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($pay['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($pay['payment_number']) ?></td>
                            <td><small><?= htmlspecialchars($pay['reservation_number']) ?></small></td>
                            <td><?= ucfirst(str_replace('_', ' ', $pay['payment_method'])) ?></td>
                            <td>
                                <?php if ($pay['receipt_number']): ?>
                                    <?= htmlspecialchars($pay['receipt_number']) ?>
                                <?php elseif ($pay['transaction_reference']): ?>
                                    <?= htmlspecialchars($pay['transaction_reference']) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-right" style="font-weight: 600;">
                                <span class="text-success"><?= number_format($pay['amount'], 2) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="summary-section">
            <div class="summary-row">
                <span>Total Reservation Amount:</span>
                <span>TZS <?= number_format($total_amount, 2) ?></span>
            </div>
            <div class="summary-row">
                <span>Total Paid:</span>
                <span class="text-success">TZS <?= number_format($total_credits, 2) ?></span>
            </div>
            <div class="summary-row total">
                <span>Outstanding Balance:</span>
                <span>TZS <?= number_format($closing_balance, 2) ?></span>
            </div>
        </div>

        
    </div>

    <script>
        // Auto-print when page loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>