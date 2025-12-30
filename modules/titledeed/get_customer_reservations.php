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

header('Content-Type: application/json');

$customer_id = $_GET['customer_id'] ?? null;

if (!$customer_id) {
    echo json_encode(['error' => 'Customer ID is required']);
    exit;
}

try {
    $sql = "SELECT 
        r.reservation_id,
        r.plot_id,
        r.reservation_date,
        r.reservation_number,
        p.plot_number,
        p.area,
        pr.project_name
    FROM reservations r
    INNER JOIN plots p ON r.plot_id = p.plot_id
    INNER JOIN projects pr ON p.project_id = pr.project_id
    WHERE r.customer_id = ? 
    AND r.company_id = ?
    AND r.is_active = 1
    ORDER BY r.reservation_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$customer_id, $company_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($reservations as &$reservation) {
        $reservation['plot_size'] = $reservation['area'] ?? 0;
    }
    
    echo json_encode($reservations);
    
} catch (PDOException $e) {
    error_log("Error fetching reservations: " . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>