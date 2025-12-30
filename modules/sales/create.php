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

echo "<h2>Deep Table Analysis for 'changed_by'</h2>";

// 1. Check columns again
echo "<h3>1. Columns in reservations table:</h3>";
try {
    $sql = "SHOW COLUMNS FROM reservations";
    $stmt = $conn->query($sql);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<pre>";
    foreach ($columns as $col) {
        if (stripos($col['Field'], 'changed') !== false || stripos($col['Field'], 'updated') !== false) {
            echo "<strong style='color:red;'>" . print_r($col, true) . "</strong>";
        } else {
            echo print_r($col, true);
        }
    }
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// 2. Check for triggers
echo "<h3>2. Triggers on reservations table:</h3>";
try {
    $sql = "SHOW TRIGGERS WHERE `Table` = 'reservations'";
    $stmt = $conn->query($sql);
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p style='color:green;'>No triggers found.</p>";
    } else {
        echo "<pre>";
        print_r($triggers);
        echo "</pre>";
    }
} catch (PDOException $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// 3. Check table creation statement
echo "<h3>3. Full table creation statement:</h3>";
try {
    $sql = "SHOW CREATE TABLE reservations";
    $stmt = $conn->query($sql);
    $create = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<pre>";
    echo htmlspecialchars($create['Create Table']);
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// 4. Test insert to see exact error
echo "<h3>4. Test Insert (will fail but show exact error):</h3>";
try {
    $conn->beginTransaction();
    
    $test_sql = "INSERT INTO reservations (
        company_id, 
        customer_id, 
        plot_id, 
        reservation_date, 
        total_amount,
        status
    ) VALUES (?, ?, ?, ?, ?, ?)";
    
    $test_stmt = $conn->prepare($test_sql);
    $test_stmt->execute([
        $_SESSION['company_id'],
        1, // test customer
        1, // test plot
        date('Y-m-d'),
        100000,
        'draft'
    ]);
    
    $conn->rollBack();
    echo "<p style='color:green;'>Test insert would succeed!</p>";
    
} catch (PDOException $e) {
    $conn->rollBack();
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>SQL State:</strong> " . $e->getCode() . "</p>";
}

?>