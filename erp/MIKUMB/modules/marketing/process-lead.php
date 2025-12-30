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
    header('Location: leads.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Validate required fields
    if (empty($_POST['company_name']) || empty($_POST['contact_person']) || 
        empty($_POST['email']) || empty($_POST['phone']) || empty($_POST['source'])) {
        throw new Exception('Please fill in all required fields.');
    }

    // Validate email format
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please provide a valid email address.');
    }

    // Insert lead
    $insert_lead = "
        INSERT INTO leads (
            company_id,
            company_name,
            industry,
            company_size,
            website,
            contact_person,
            job_title,
            email,
            phone,
            address,
            city,
            country,
            source,
            campaign_id,
            status,
            assigned_to,
            estimated_value,
            expected_close_date,
            lead_score,
            requirements,
            notes,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmt = $conn->prepare($insert_lead);
    $stmt->execute([
        $company_id,
        $_POST['company_name'],
        $_POST['industry'] ?? null,
        $_POST['company_size'] ?? null,
        $_POST['website'] ?? null,
        $_POST['contact_person'],
        $_POST['job_title'] ?? null,
        $_POST['email'],
        $_POST['phone'],
        $_POST['address'] ?? null,
        $_POST['city'] ?? null,
        $_POST['country'] ?? 'Tanzania',
        $_POST['source'],
        !empty($_POST['campaign_id']) ? $_POST['campaign_id'] : null,
        $_POST['status'] ?? 'new',
        !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
        !empty($_POST['estimated_value']) ? floatval($_POST['estimated_value']) : null,
        !empty($_POST['expected_close_date']) ? $_POST['expected_close_date'] : null,
        !empty($_POST['lead_score']) ? intval($_POST['lead_score']) : 5,
        $_POST['requirements'] ?? null,
        $_POST['notes'] ?? null
    ]);

    $lead_id = $conn->lastInsertId();

    $conn->commit();

    $_SESSION['success_message'] = "Lead '{$_POST['company_name']}' has been added successfully!";
    header('Location: view-lead.php?id=' . $lead_id);
    exit;

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error creating lead: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header('Location: create-lead.php');
    exit;
}
?>