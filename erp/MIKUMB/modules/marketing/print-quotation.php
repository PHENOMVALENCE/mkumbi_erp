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
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = 'Invalid quotation ID';
    header('Location: quotations.php');
    exit;
}

$quotation_id = (int)$_GET['id'];

// 🔍 DEBUG: Test database connection
try {
    $test_query = "SELECT 1 as test";
    $test_stmt = $conn->prepare($test_query);
    $test_stmt->execute();
    $test_result = $test_stmt->fetch(PDO::FETCH_ASSOC);
    if ($test_result['test'] != 1) {
        throw new Exception("Database connection test failed");
    }
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $_SESSION['error_message'] = 'Database connection error';
    header('Location: quotations.php');
    exit;
}

// 🔍 DEBUG: Check if quotation exists
try {
    $check_query = "SELECT quotation_id, quotation_number, status FROM quotations WHERE quotation_id = ? AND company_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->execute([$quotation_id, $company_id]);
    $quotation_check = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quotation_check) {
        $_SESSION['error_message'] = "Quotation not found (ID: $quotation_id)";
        header('Location: quotations.php');
        exit;
    }
    
    error_log("DEBUG: Found quotation: " . $quotation_check['quotation_number']);
} catch (PDOException $e) {
    error_log("DEBUG: Quotation check failed: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error checking quotation: ' . $e->getMessage();
    header('Location: quotations.php');
    exit;
}

// 🔍 DEBUG: Get actual table structure
try {
    $columns_query = "
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'quotations' 
        AND TABLE_SCHEMA = DATABASE()
        ORDER BY ORDINAL_POSITION
    ";
    $columns_stmt = $conn->query($columns_query);
    $quotation_columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
    error_log("DEBUG: Quotations table columns: " . implode(', ', $quotation_columns));
} catch (PDOException $e) {
    error_log("DEBUG: Could not fetch quotations columns: " . $e->getMessage());
}

// 🔍 DEBUG: Get quotation_items structure
try {
    $items_columns_query = "
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'quotation_items' 
        AND TABLE_SCHEMA = DATABASE()
        ORDER BY ORDINAL_POSITION
    ";
    $items_columns_stmt = $conn->query($items_columns_query);
    $items_columns = $items_columns_stmt->fetchAll(PDO::FETCH_COLUMN);
    error_log("DEBUG: Quotation_items columns: " . implode(', ', $items_columns));
} catch (PDOException $e) {
    error_log("DEBUG: Could not fetch quotation_items columns: " . $e->getMessage());
}

// ✅ FIXED: Simple query first - just get basic quotation data
try {
    $query = "
        SELECT 
            q.*
        FROM quotations q
        WHERE q.quotation_id = ? AND q.company_id = ?
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute([$quotation_id, $company_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        $_SESSION['error_message'] = 'Quotation not found';
        header('Location: quotations.php');
        exit;
    }

    error_log("DEBUG: Successfully loaded quotation: " . print_r($quotation, true));

    // ✅ FIXED: Safe items query with correct columns
    $items_query = "
        SELECT 
            *
        FROM quotation_items 
        WHERE quotation_id = ?
    ";
    $stmt = $conn->prepare($items_query);
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("DEBUG: Found " . count($items) . " items for quotation $quotation_id");

} catch (PDOException $e) {
    error_log("ERROR fetching quotation $quotation_id: " . $e->getMessage());
    error_log("SQLSTATE: " . $e->getCode());
    $_SESSION['error_message'] = 'Database Error: ' . $e->getMessage();
    header('Location: quotations.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DEBUG: Quotation <?php echo htmlspecialchars($quotation['quotation_number'] ?? 'Unknown'); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .debug { background: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; margin: 10px 0; }
        .error { background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 10px 0; }
        .success { background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 10px 0; }
        pre { background: #f1f3f4; padding: 10px; border-radius: 4px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #007bff; color: white; }
    </style>
</head>
<body>
    <h1>🛠️ DEBUG: Quotation Viewer</h1>
    
    <div class="success">
        ✅ <strong>Success!</strong> Quotation loaded successfully!
    </div>
    
    <div class="debug">
        <h3>📋 Quotation Details</h3>
        <table>
            <tr><th>Quotation ID</th><td><?php echo htmlspecialchars($quotation['quotation_id']); ?></td></tr>
            <tr><th>Number</th><td><?php echo htmlspecialchars($quotation['quotation_number']); ?></td></tr>
            <tr><th>Status</th><td><strong><?php echo htmlspecialchars($quotation['status']); ?></strong></td></tr>
            <tr><th>Date</th><td><?php echo htmlspecialchars($quotation['quote_date']); ?></td></tr>
            <tr><th>Total Amount</th><td>TSH <?php echo number_format((float)$quotation['total_amount'], 0); ?></td></tr>
        </table>
    </div>

    <div class="debug">
        <h3>📦 Quotation Items (<?php echo count($items); ?> found)</h3>
        <?php if (empty($items)): ?>
            <p class="error">❌ No items found for this quotation!</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Description</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['item_id'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($item['description'] ?? $item['item_description'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($item['quantity'] ?? 'N/A'); ?></td>
                        <td>TSH <?php echo number_format((float)($item['unit_price'] ?? 0), 0); ?></td>
                        <td>TSH <?php echo number_format((float)($item['total_price'] ?? 0), 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="debug">
        <h3>🔍 Available Quotation Columns</h3>
        <pre><?php 
        $avail_columns = [];
        foreach ($quotation as $key => $value) {
            $avail_columns[] = $key;
        }
        echo htmlspecialchars(implode(', ', $avail_columns)); 
        ?></pre>
    </div>

    <div class="debug">
        <h3>📊 Sample Item Data</h3>
        <?php if (!empty($items)): ?>
        <pre><?php echo htmlspecialchars(print_r($items[0], true)); ?></pre>
        <?php endif; ?>
    </div>

    <a href="quotations.php" class="btn btn-secondary" style="display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px;">← Back to Quotations</a>
    <a href="view-quotation.php?id=<?php echo $quotation_id; ?>" class="btn btn-primary" style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; margin-left: 10px;">✅ View Full Quotation</a>
</body>
</html>