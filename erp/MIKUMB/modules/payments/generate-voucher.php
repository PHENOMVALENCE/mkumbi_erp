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
$user_id = $_SESSION['user_id'];

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $voucher_type = $_POST['voucher_type'] ?? '';
        $payment_id = $_POST['payment_id'] ?? '';
        $description = $_POST['description'] ?? '';
        $auto_approve = isset($_POST['auto_approve']) ? 1 : 0;

        if (empty($voucher_type) || empty($payment_id)) {
            throw new Exception('Voucher type and payment are required.');
        }

        // Validate voucher type
        $valid_types = ['payment', 'receipt', 'refund', 'adjustment'];
        if (!in_array($voucher_type, $valid_types)) {
            throw new Exception('Invalid voucher type.');
        }

        // Fetch payment details
        $payment_query = "
            SELECT 
                p.*,
                r.reservation_id,
                r.reservation_number,
                r.customer_id,
                c.full_name as customer_name,
                pl.plot_id,
                pl.plot_number,
                pl.block_number,
                pr.project_name
            FROM payments p
            INNER JOIN reservations r ON p.reservation_id = r.reservation_id
            INNER JOIN customers c ON r.customer_id = c.customer_id
            INNER JOIN plots pl ON r.plot_id = pl.plot_id
            INNER JOIN projects pr ON pl.project_id = pr.project_id
            WHERE p.payment_id = ? 
            AND p.company_id = ?
            AND p.status = 'approved'
        ";
        $stmt = $conn->prepare($payment_query);
        $stmt->execute([$payment_id, $company_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception('Payment not found or not approved.');
        }

        // Check if voucher already exists for this payment
        $check_query = "SELECT voucher_id FROM payment_vouchers WHERE payment_id = ? AND company_id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->execute([$payment_id, $company_id]);
        if ($stmt->fetch()) {
            throw new Exception('A voucher already exists for this payment.');
        }

        // Generate voucher number
        $voucher_number = generateVoucherNumber($conn, $company_id, $voucher_type);

        // Determine approval status
        $approval_status = $auto_approve ? 'approved' : 'pending';
        $approved_by = $auto_approve ? $user_id : null;
        $approved_at = $auto_approve ? date('Y-m-d H:i:s') : null;

        // Insert voucher
        $insert_query = "
            INSERT INTO payment_vouchers (
                company_id,
                voucher_number,
                voucher_type,
                voucher_date,
                payment_id,
                reservation_id,
                customer_id,
                amount,
                bank_name,
                account_number,
                cheque_number,
                transaction_reference,
                approved_by,
                approved_at,
                approval_status,
                description,
                created_by,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ";

        $stmt = $conn->prepare($insert_query);
        $result = $stmt->execute([
            $company_id,
            $voucher_number,
            $voucher_type,
            $payment['payment_date'],
            $payment_id,
            $payment['reservation_id'],
            $payment['customer_id'],
            $payment['amount'],
            $payment['bank_name'],
            $payment['account_number'],
            $payment['cheque_number'] ?? null,
            $payment['transaction_reference'],
            $approved_by,
            $approved_at,
            $approval_status,
            $description,
            $user_id
        ]);

        if ($result) {
            $voucher_id = $conn->lastInsertId();
            
            $_SESSION['success_message'] = "Voucher {$voucher_number} generated successfully!";
            header("Location: view-voucher.php?id={$voucher_id}");
            exit;
        } else {
            throw new Exception('Failed to generate voucher.');
        }

    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: vouchers.php");
        exit;
    }
}

/**
 * Generate unique voucher number
 */
function generateVoucherNumber($conn, $company_id, $voucher_type) {
    // Get prefix based on voucher type
    $prefixes = [
        'payment' => 'PV',
        'receipt' => 'RV',
        'refund' => 'RF',
        'adjustment' => 'AV'
    ];
    
    $prefix = $prefixes[$voucher_type] ?? 'VCH';
    $year = date('Y');
    
    // Get the last voucher number for this type and year
    $query = "
        SELECT voucher_number 
        FROM payment_vouchers 
        WHERE company_id = ? 
        AND voucher_type = ?
        AND YEAR(voucher_date) = ?
        ORDER BY voucher_id DESC 
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$company_id, $voucher_type, $year]);
    $last_voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($last_voucher) {
        // Extract number from last voucher and increment
        preg_match('/(\d+)$/', $last_voucher['voucher_number'], $matches);
        $next_number = isset($matches[1]) ? (int)$matches[1] + 1 : 1;
    } else {
        $next_number = 1;
    }
    
    // Format: PV-2025-0001
    return sprintf('%s-%s-%04d', $prefix, $year, $next_number);
}

// If accessed directly via GET, redirect to vouchers page
header("Location: vouchers.php");
exit;
?>