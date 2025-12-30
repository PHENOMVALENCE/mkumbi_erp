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

// Get reservation ID and template ID
$reservation_id = $_GET['reservation_id'] ?? null;
$template_id = $_GET['template_id'] ?? null;

if (!$reservation_id) {
    die("Reservation ID is required");
}

// Fetch reservation details with all related information
try {
    $reservation_sql = "SELECT 
        r.*,
        c.full_name as customer_name,
        COALESCE(c.phone, c.phone1) as customer_phone,
        c.email as customer_email,
        CONCAT_WS(', ', c.street_address, c.ward, c.district, c.region) as customer_address,
        pl.plot_number,
        pl.plot_size,
        pl.block_number,
        pr.project_name,
        pr.location as project_location,
        comp.company_name,
        comp.address as company_address,
        comp.phone as company_phone,
        comp.email as company_email,
        COALESCE(SUM(p.amount), 0) as total_paid
    FROM reservations r
    INNER JOIN customers c ON r.customer_id = c.customer_id
    INNER JOIN plots pl ON r.plot_id = pl.plot_id
    INNER JOIN projects pr ON pl.project_id = pr.project_id
    INNER JOIN companies comp ON r.company_id = comp.company_id
    LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
    WHERE r.reservation_id = ? AND r.company_id = ?
    GROUP BY r.reservation_id";
    
    $stmt = $conn->prepare($reservation_sql);
    $stmt->execute([$reservation_id, $company_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        die("Reservation not found");
    }
} catch (PDOException $e) {
    error_log("Error fetching reservation: " . $e->getMessage());
    die("Failed to load reservation details");
}

// Fetch template
if (!$template_id) {
    // Get default template for the contract type
    $template_sql = "SELECT * FROM contract_templates 
                    WHERE company_id = ? AND is_default = 1 AND is_active = 1 
                    ORDER BY template_id DESC LIMIT 1";
    $template_stmt = $conn->prepare($template_sql);
    $template_stmt->execute([$company_id]);
} else {
    $template_sql = "SELECT * FROM contract_templates 
                    WHERE template_id = ? AND company_id = ? AND is_active = 1";
    $template_stmt = $conn->prepare($template_sql);
    $template_stmt->execute([$template_id, $company_id]);
}

$template = $template_stmt->fetch(PDO::FETCH_ASSOC);

if (!$template) {
    die("Contract template not found");
}

// Calculate balance
$balance = $reservation['total_amount'] - $reservation['total_paid'];

// Prepare replacement variables
$variables = [
    '{customer_name}' => strtoupper($reservation['customer_name']),
    '{customer_phone}' => $reservation['customer_phone'] ?? 'N/A',
    '{customer_email}' => $reservation['customer_email'] ?? 'N/A',
    '{customer_address}' => $reservation['customer_address'] ?? 'N/A',
    
    '{plot_number}' => $reservation['plot_number'],
    '{plot_size}' => number_format($reservation['plot_size'], 2),
    '{block_number}' => $reservation['block_number'] ?? 'N/A',
    
    '{project_name}' => strtoupper($reservation['project_name']),
    '{project_location}' => $reservation['project_location'] ?? 'N/A',
    
    '{total_amount}' => number_format($reservation['total_amount'], 2),
    '{deposit_amount}' => number_format($reservation['deposit_amount'], 2),
    '{balance}' => number_format($balance, 2),
    '{total_paid}' => number_format($reservation['total_paid'], 2),
    
    '{reservation_number}' => $reservation['reservation_number'],
    '{reservation_date}' => date('F d, Y', strtotime($reservation['reservation_date'])),
    '{current_date}' => date('F d, Y'),
    
    '{company_name}' => strtoupper($reservation['company_name']),
    '{company_address}' => $reservation['company_address'] ?? 'N/A',
    '{company_phone}' => $reservation['company_phone'] ?? 'N/A',
    '{company_email}' => $reservation['company_email'] ?? 'N/A',
];

// Replace variables in template
$contract_content = str_replace(
    array_keys($variables),
    array_values($variables),
    $template['template_content']
);

// Update reservation with template ID if not set
if (!$reservation['contract_template_id']) {
    try {
        $update_sql = "UPDATE reservations 
                      SET contract_template_id = ? 
                      WHERE reservation_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->execute([$template['template_id'], $reservation_id]);
    } catch (PDOException $e) {
        error_log("Failed to update reservation template: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract - <?= htmlspecialchars($reservation['reservation_number']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', serif;
            font-size: 12pt;
            line-height: 1.6;
            color: #000;
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
        }

        @media print {
            body {
                padding: 20px;
            }
            .no-print {
                display: none !important;
            }
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #000;
        }

        .company-logo {
            font-size: 24pt;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .contract-title {
            font-size: 18pt;
            font-weight: bold;
            text-decoration: underline;
            margin: 20px 0;
            text-align: center;
        }

        .content {
            white-space: pre-wrap;
            text-align: justify;
            margin: 20px 0;
        }

        .signatures {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }

        .signature-block {
            text-align: center;
            flex: 1;
            margin: 0 20px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-top: 50px;
            padding-top: 5px;
        }

        .toolbar {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
        }

        .toolbar button {
            display: block;
            width: 100%;
            margin-bottom: 10px;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }

        .btn-print {
            background: #007bff;
            color: white;
        }

        .btn-download {
            background: #28a745;
            color: white;
        }

        .btn-close {
            background: #6c757d;
            color: white;
        }

        .btn-template {
            background: #ffc107;
            color: #000;
        }

        .toolbar button:hover {
            opacity: 0.9;
        }

        .metadata {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 10pt;
        }
    </style>
</head>
<body>
    <!-- Toolbar -->
    <div class="toolbar no-print">
        <button class="btn-print" onclick="window.print()">
            🖨️ Print Contract
        </button>
        <button class="btn-download" onclick="downloadPDF()">
            📄 Download PDF
        </button>
        <button class="btn-template" onclick="changeTemplate()">
            📝 Change Template
        </button>
        <button class="btn-close" onclick="window.close()">
            ✖️ Close
        </button>
    </div>

    <!-- Contract Header -->
    <div class="header">
        <div class="company-logo"><?= htmlspecialchars($reservation['company_name']) ?></div>
        <div><?= htmlspecialchars($reservation['company_address']) ?></div>
        <div>Tel: <?= htmlspecialchars($reservation['company_phone']) ?> | Email: <?= htmlspecialchars($reservation['company_email']) ?></div>
    </div>

    <!-- Metadata (hidden when printed) -->
    <div class="metadata no-print">
        <strong>Template:</strong> <?= htmlspecialchars($template['template_name']) ?> |
        <strong>Generated:</strong> <?= date('F d, Y H:i:s') ?> |
        <strong>Reservation:</strong> <?= htmlspecialchars($reservation['reservation_number']) ?>
    </div>

    <div class="contract-title">
        <?= strtoupper(str_replace('_', ' ', $template['template_type'])) ?>
    </div>

    <!-- Contract Content -->
    <div class="content">
<?= htmlspecialchars($contract_content) ?>
    </div>

    <!-- Signatures -->
    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line">
                Vendor Signature
            </div>
            <div style="margin-top: 10px;">
                <strong><?= htmlspecialchars($reservation['company_name']) ?></strong>
            </div>
            <div>Date: _________________</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">
                Purchaser Signature
            </div>
            <div style="margin-top: 10px;">
                <strong><?= htmlspecialchars($reservation['customer_name']) ?></strong>
            </div>
            <div>Date: _________________</div>
        </div>
    </div>

    <script>
    function downloadPDF() {
        alert('PDF download functionality requires server-side PDF generation library (e.g., TCPDF, mPDF)');
        // Implement PDF generation here
        // window.location.href = 'generate_pdf.php?reservation_id=<?= $reservation_id ?>&template_id=<?= $template['template_id'] ?>';
    }

    function changeTemplate() {
        const reservationId = <?= $reservation_id ?>;
        window.location.href = 'select_template.php?reservation_id=' + reservationId;
    }
    </script>
</body>
</html>