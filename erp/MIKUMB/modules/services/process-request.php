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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: requests.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Validate required fields
    if (empty($_POST['request_date']) || empty($_POST['service_type_id']) || empty($_POST['service_description'])) {
        throw new Exception('Please fill in all required fields.');
    }

    // Generate request number
    $year = date('Y');
    $prefix = 'SR';
    
    $number_query = "SELECT MAX(CAST(SUBSTRING(request_number, LENGTH(?)+2) AS UNSIGNED)) as max_num 
                     FROM service_requests 
                     WHERE company_id = ? 
                     AND request_number LIKE ? 
                     AND YEAR(request_date) = ?";
    $stmt = $conn->prepare($number_query);
    $stmt->execute([$prefix, $company_id, $prefix . '-' . $year . '-%', $year]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_num = ($result['max_num'] ?? 0) + 1;
    $request_number = sprintf('%s-%s-%04d', $prefix, $year, $next_num);

    // Insert service request
    $insert_request = "
        INSERT INTO service_requests (
            company_id,
            request_number,
            request_date,
            service_type_id,
            customer_id,
            plot_id,
            project_id,
            service_description,
            plot_size,
            location_details,
            requested_start_date,
            expected_completion_date,
            assigned_to,
            status,
            remarks,
            created_at,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ";

    $stmt = $conn->prepare($insert_request);
    $stmt->execute([
        $company_id,
        $request_number,
        $_POST['request_date'],
        $_POST['service_type_id'],
        !empty($_POST['customer_id']) ? $_POST['customer_id'] : null,
        !empty($_POST['plot_id']) ? $_POST['plot_id'] : null,
        !empty($_POST['project_id']) ? $_POST['project_id'] : null,
        $_POST['service_description'],
        !empty($_POST['plot_size']) ? floatval($_POST['plot_size']) : null,
        $_POST['location_details'] ?? null,
        !empty($_POST['requested_start_date']) ? $_POST['requested_start_date'] : null,
        !empty($_POST['expected_completion_date']) ? $_POST['expected_completion_date'] : null,
        !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
        $_POST['status'] ?? 'pending',
        $_POST['remarks'] ?? null,
        $user_id
    ]);

    $service_request_id = $conn->lastInsertId();

    $conn->commit();

    $_SESSION['success_message'] = "Service request {$request_number} has been created successfully!";
    header('Location: view-request.php?id=' . $service_request_id);
    exit;

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error creating service request: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header('Location: create.php');
    exit;
}
?>