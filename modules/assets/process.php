<?php
/**
 * Asset Process Handler
 * Mkumbi Investments ERP System
 */

define('APP_ACCESS', true);
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

$db = Database::getInstance();
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'run_depreciation':
            // Check permission
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to run depreciation.");
            }
            
            $depreciation_month = $_POST['depreciation_month'] ?? date('Y-m');
            $year = substr($depreciation_month, 0, 4);
            $month = substr($depreciation_month, 5, 2);
            $depreciation_date = $depreciation_month . '-' . date('t', strtotime($depreciation_month . '-01')); // Last day of month
            
            // Check if already run
            $sql = "SELECT COUNT(*) as count FROM asset_depreciation 
                    WHERE asset_id IN (SELECT asset_id FROM fixed_assets WHERE company_id = ?)
                    AND YEAR(period_date) = ? AND MONTH(period_date) = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$company_id, $year, $month]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                throw new Exception("Depreciation already run for this month.");
            }
            
            // Get assets with depreciation info
            $sql = "SELECT a.*, ac.depreciation_method, ac.useful_life_years, ac.salvage_value_percentage
                    FROM fixed_assets a
                    JOIN asset_categories ac ON a.category_id = ac.category_id
                    WHERE a.company_id = ? AND a.status = 'active' AND a.current_book_value > 0";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$company_id]);
            $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($assets)) {
                throw new Exception("No depreciating assets found.");
            }
            
            $conn->beginTransaction();
            
            $total_depreciation = 0;
            foreach ($assets as $asset) {
                // Calculate depreciation based on method
                $method = $asset['depreciation_method'] ?? 'straight_line';
                $useful_life_years = $asset['useful_life_years'] ?? 5;
                $salvage_percentage = $asset['salvage_value_percentage'] ?? 10;
                $salvage_value = $asset['purchase_cost'] * ($salvage_percentage / 100);
                $depreciable_cost = $asset['purchase_cost'] - $salvage_value;
                
                if ($method === 'declining_balance') {
                    $rate = 2 / $useful_life_years; // Double declining balance
                    $depreciation = $asset['current_book_value'] * ($rate / 12);
                } else {
                    // Straight line
                    $depreciation = $depreciable_cost / ($useful_life_years * 12);
                }
                
                // Don't depreciate below salvage value
                $min_value = $salvage_value;
                $depreciation = min($depreciation, max(0, $asset['current_book_value'] - $min_value));
                
                if ($depreciation > 0) {
                    $new_value = max($min_value, $asset['current_book_value'] - $depreciation);
                    $new_accumulated = ($asset['accumulated_depreciation'] ?? 0) + $depreciation;
                    
                    // Record depreciation
                    $sql = "INSERT INTO asset_depreciation (asset_id, period_date, depreciation_amount, 
                                accumulated_depreciation, book_value, created_by)
                            VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $asset['asset_id'], $depreciation_date, $depreciation,
                        $new_accumulated, $new_value, $user_id
                    ]);
                    
                    // Update asset current book value and accumulated depreciation
                    $sql = "UPDATE fixed_assets SET current_book_value = ?, accumulated_depreciation = ?, 
                            last_depreciation_date = ?, updated_at = NOW() WHERE asset_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$new_value, $new_accumulated, $depreciation_date, $asset['asset_id']]);
                    
                    $total_depreciation += $depreciation;
                }
            }
            
            $conn->commit();
            
            logAudit($conn, $company_id, $user_id, 'depreciation', 'assets', 'asset_depreciation', 0, null, [
                'month' => $depreciation_month,
                'total' => $total_depreciation,
                'assets_count' => count($assets)
            ]);
            
            $_SESSION['success_message'] = "Depreciation run successfully. Total: " . formatCurrency($total_depreciation) . " for " . count($assets) . " assets.";
            header('Location: depreciation.php');
            exit;
            
        case 'schedule_maintenance':
            $asset_id = (int)$_POST['asset_id'];
            $maintenance_type = sanitize($_POST['maintenance_type']);
            $scheduled_date = $_POST['scheduled_date'];
            $description = sanitize($_POST['description']);
            $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
            
            // Verify asset
            $sql = "SELECT * FROM fixed_assets WHERE asset_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$asset_id, $company_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Asset not found.");
            }
            
            $sql = "INSERT INTO asset_maintenance (asset_id, maintenance_type, scheduled_date, 
                        description, estimated_cost, status, created_by)
                    VALUES (?, ?, ?, ?, ?, 'SCHEDULED', ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$asset_id, $maintenance_type, $scheduled_date, $description, $estimated_cost, $user_id]);
            
            logAudit($conn, $company_id, $user_id, 'create', 'assets', 'asset_maintenance', 
                     $conn->lastInsertId(), null, ['asset_id' => $asset_id, 'type' => $maintenance_type]);
            
            $_SESSION['success_message'] = "Maintenance scheduled successfully.";
            header('Location: list.php');
            exit;
            
        case 'dispose_asset':
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to dispose assets.");
            }
            
            $asset_id = (int)$_POST['asset_id'];
            $disposal_date = $_POST['disposal_date'];
            $disposal_reason = sanitize($_POST['disposal_reason']);
            $disposal_value = (float)($_POST['disposal_value'] ?? 0);
            
            // Get asset
            $sql = "SELECT * FROM fixed_assets WHERE asset_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$asset_id, $company_id]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$asset) {
                throw new Exception("Asset not found.");
            }
            
            $sql = "UPDATE fixed_assets SET status = 'disposed', disposal_date = ?, disposal_reason = ?, 
                        disposal_amount = ?, updated_at = NOW() WHERE asset_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$disposal_date, $disposal_reason, $disposal_value, $asset_id]);
            
            logAudit($conn, $company_id, $user_id, 'dispose', 'assets', 'assets', $asset_id, 
                     $asset, ['status' => 'DISPOSED', 'disposal_value' => $disposal_value]);
            
            $_SESSION['success_message'] = "Asset disposed successfully.";
            header('Location: list.php');
            exit;
            
        case 'add_category':
        case 'edit_category':
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to manage categories.");
            }
            
            $category_id = (int)($_POST['category_id'] ?? 0);
            $category_name = sanitize($_POST['category_name']);
            $category_code = sanitize($_POST['category_code']);
            $depreciation_rate = (float)$_POST['depreciation_rate'];
            $depreciation_method = sanitize($_POST['depreciation_method']);
            $useful_life_years = (int)$_POST['useful_life_years'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($category_name)) {
                throw new Exception("Category name is required.");
            }
            
            if ($action === 'add_category') {
                $sql = "INSERT INTO asset_categories (company_id, category_name, category_code, 
                            depreciation_rate, depreciation_method, useful_life_years, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$company_id, $category_name, $category_code, $depreciation_rate, 
                               $depreciation_method, $useful_life_years, $is_active]);
                $_SESSION['success_message'] = "Category added successfully.";
            } else {
                $sql = "UPDATE asset_categories SET category_name = ?, category_code = ?, 
                            depreciation_rate = ?, depreciation_method = ?, useful_life_years = ?, is_active = ?
                        WHERE category_id = ? AND company_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$category_name, $category_code, $depreciation_rate, $depreciation_method, 
                               $useful_life_years, $is_active, $category_id, $company_id]);
                $_SESSION['success_message'] = "Category updated successfully.";
            }
            
            header('Location: categories.php');
            exit;
            
        case 'delete_category':
            if (!hasPermission($conn, $user_id, ['COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to delete categories.");
            }
            
            $category_id = (int)$_POST['category_id'];
            
            // Check if in use
            $sql = "SELECT COUNT(*) as count FROM fixed_assets WHERE category_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$category_id]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                throw new Exception("Cannot delete category that has assets.");
            }
            
            $sql = "DELETE FROM asset_categories WHERE category_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$category_id, $company_id]);
            
            $_SESSION['success_message'] = "Category deleted successfully.";
            header('Location: categories.php');
            exit;
            
        default:
            throw new Exception("Invalid action.");
    }
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}
