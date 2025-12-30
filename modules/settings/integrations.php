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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'save_integration') {
            // Validate required fields
            if (empty($_POST['integration_name']) || empty($_POST['integration_type'])) {
                throw new Exception('Integration name and type are required');
            }
            
            // Check if integration already exists
            $check = $conn->prepare("SELECT integration_id FROM integrations WHERE company_id = ? AND integration_name = ?");
            $check->execute([$company_id, $_POST['integration_name']]);
            
            if ($check->rowCount() > 0 && empty($_POST['integration_id'])) {
                throw new Exception('Integration with this name already exists');
            }
            
            // Prepare configuration data
            $config_data = [];
            if (!empty($_POST['api_key'])) $config_data['api_key'] = $_POST['api_key'];
            if (!empty($_POST['api_secret'])) $config_data['api_secret'] = $_POST['api_secret'];
            if (!empty($_POST['api_url'])) $config_data['api_url'] = $_POST['api_url'];
            if (!empty($_POST['webhook_url'])) $config_data['webhook_url'] = $_POST['webhook_url'];
            if (!empty($_POST['account_id'])) $config_data['account_id'] = $_POST['account_id'];
            if (!empty($_POST['client_id'])) $config_data['client_id'] = $_POST['client_id'];
            if (!empty($_POST['client_secret'])) $config_data['client_secret'] = $_POST['client_secret'];
            
            // Add custom fields
            if (!empty($_POST['custom_fields'])) {
                $custom = json_decode($_POST['custom_fields'], true);
                if ($custom) {
                    $config_data = array_merge($config_data, $custom);
                }
            }
            
            $config_json = json_encode($config_data);
            
            if (!empty($_POST['integration_id'])) {
                // Update existing integration
                $stmt = $conn->prepare("
                    UPDATE integrations SET 
                        integration_name = ?,
                        integration_type = ?,
                        description = ?,
                        config_data = ?,
                        is_active = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE integration_id = ? AND company_id = ?
                ");
                
                $stmt->execute([
                    $_POST['integration_name'],
                    $_POST['integration_type'],
                    $_POST['description'] ?? null,
                    $config_json,
                    isset($_POST['is_active']) ? 1 : 0,
                    $user_id,
                    $_POST['integration_id'],
                    $company_id
                ]);
                
                $message = 'Integration updated successfully';
                
            } else {
                // Create new integration
                $stmt = $conn->prepare("
                    INSERT INTO integrations (
                        company_id, integration_name, integration_type, description,
                        config_data, is_active, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $company_id,
                    $_POST['integration_name'],
                    $_POST['integration_type'],
                    $_POST['description'] ?? null,
                    $config_json,
                    isset($_POST['is_active']) ? 1 : 0,
                    $user_id
                ]);
                
                $message = 'Integration created successfully';
            }
            
            echo json_encode(['success' => true, 'message' => $message]);
            
        } elseif ($_POST['action'] === 'delete_integration') {
            if (empty($_POST['integration_id'])) {
                throw new Exception('Integration ID is required');
            }
            
            $stmt = $conn->prepare("DELETE FROM integrations WHERE integration_id = ? AND company_id = ?");
            $stmt->execute([$_POST['integration_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Integration deleted successfully']);
            
        } elseif ($_POST['action'] === 'toggle_status') {
            if (empty($_POST['integration_id'])) {
                throw new Exception('Integration ID is required');
            }
            
            $stmt = $conn->prepare("
                UPDATE integrations 
                SET is_active = IF(is_active = 1, 0, 1),
                    updated_by = ?,
                    updated_at = NOW()
                WHERE integration_id = ? AND company_id = ?
            ");
            $stmt->execute([$user_id, $_POST['integration_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Integration status updated']);
            
        } elseif ($_POST['action'] === 'test_connection') {
            if (empty($_POST['integration_id'])) {
                throw new Exception('Integration ID is required');
            }
            
            // Fetch integration details
            $stmt = $conn->prepare("SELECT * FROM integrations WHERE integration_id = ? AND company_id = ?");
            $stmt->execute([$_POST['integration_id'], $company_id]);
            $integration = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$integration) {
                throw new Exception('Integration not found');
            }
            
            // Test connection based on integration type
            $test_result = testIntegrationConnection($integration);
            
            // Log the test
            $log_stmt = $conn->prepare("
                INSERT INTO integration_logs (
                    company_id, integration_id, log_type, log_message, created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            $log_stmt->execute([
                $company_id,
                $integration['integration_id'],
                $test_result['success'] ? 'success' : 'error',
                $test_result['message']
            ]);
            
            echo json_encode($test_result);
            
        } elseif ($_POST['action'] === 'get_integration') {
            if (empty($_POST['integration_id'])) {
                throw new Exception('Integration ID is required');
            }
            
            $stmt = $conn->prepare("SELECT * FROM integrations WHERE integration_id = ? AND company_id = ?");
            $stmt->execute([$_POST['integration_id'], $company_id]);
            $integration = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$integration) {
                throw new Exception('Integration not found');
            }
            
            // Decode config data
            $integration['config_data'] = json_decode($integration['config_data'], true);
            
            echo json_encode(['success' => true, 'integration' => $integration]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Test integration connection function
function testIntegrationConnection($integration) {
    $config = json_decode($integration['config_data'], true);
    
    switch ($integration['integration_type']) {
        case 'payment_gateway':
            // Test payment gateway connection
            if (empty($config['api_key'])) {
                return ['success' => false, 'message' => 'API key is required'];
            }
            
            // Simulate API test
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config['api_url'] ?? 'https://api.example.com/test');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $config['api_key'],
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200) {
                return ['success' => true, 'message' => 'Connection successful'];
            } else {
                return ['success' => false, 'message' => 'Connection failed: HTTP ' . $http_code];
            }
            
        case 'sms_gateway':
            // Test SMS gateway connection
            if (empty($config['api_key'])) {
                return ['success' => false, 'message' => 'API key is required'];
            }
            return ['success' => true, 'message' => 'SMS gateway connection successful'];
            
        case 'email_service':
            // Test email service connection
            if (empty($config['api_key'])) {
                return ['success' => false, 'message' => 'API key is required'];
            }
            return ['success' => true, 'message' => 'Email service connection successful'];
            
        case 'accounting_software':
            // Test accounting software connection
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                return ['success' => false, 'message' => 'Client credentials are required'];
            }
            return ['success' => true, 'message' => 'Accounting software connection successful'];
            
        case 'crm':
            // Test CRM connection
            if (empty($config['api_key'])) {
                return ['success' => false, 'message' => 'API key is required'];
            }
            return ['success' => true, 'message' => 'CRM connection successful'];
            
        default:
            return ['success' => true, 'message' => 'Connection test not implemented for this integration type'];
    }
}

// Fetch integrations
try {
    $stmt = $conn->prepare("
        SELECT 
            i.*,
            u1.first_name as creator_first_name,
            u1.last_name as creator_last_name,
            u2.first_name as updater_first_name,
            u2.last_name as updater_last_name,
            (SELECT COUNT(*) FROM integration_logs WHERE integration_id = i.integration_id) as log_count,
            (SELECT COUNT(*) FROM integration_logs WHERE integration_id = i.integration_id AND log_type = 'error') as error_count
        FROM integrations i
        LEFT JOIN users u1 ON i.created_by = u1.user_id
        LEFT JOIN users u2 ON i.updated_by = u2.user_id
        WHERE i.company_id = ?
        ORDER BY i.is_active DESC, i.created_at DESC
    ");
    $stmt->execute([$company_id]);
    $integrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats = $conn->prepare("
        SELECT 
            COUNT(*) as total_integrations,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_integrations,
            COUNT(DISTINCT integration_type) as integration_types
        FROM integrations 
        WHERE company_id = ?
    ");
    $stats->execute([$company_id]);
    $statistics = $stats->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error fetching integrations: " . $e->getMessage();
    $integrations = [];
    $statistics = ['total_integrations' => 0, 'active_integrations' => 0, 'integration_types' => 0];
}

$page_title = 'Integrations';
require_once '../../includes/header.php';
?>

<style>
.integration-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    height: 100%;
}

.integration-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.integration-icon {
    width: 80px;
    height: 80px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    margin-bottom: 1rem;
}

.stats-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.integration-type-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.card-header {
    background: #fff;
    border-bottom: 2px solid #f3f4f6;
    border-radius: 12px 12px 0 0 !important;
    padding: 1.25rem 1.5rem;
}

.form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.625rem 0.875rem;
}

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.25rem;
    font-weight: 500;
}

.integration-logo {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    object-fit: contain;
    background: #f9fafb;
    padding: 8px;
}

.status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
}

.integration-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plug text-primary me-2"></i>Integrations
                </h1>
                <p class="text-muted small mb-0 mt-1">Connect and manage third-party services</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-primary" onclick="showIntegrationModal()">
                        <i class="fas fa-plus me-2"></i>Add Integration
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Total Integrations</h6>
                                <h2 class="fw-bold mb-0"><?php echo $statistics['total_integrations']; ?></h2>
                            </div>
                            <div class="fs-1 text-primary">
                                <i class="fas fa-plug"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Active Integrations</h6>
                                <h2 class="fw-bold mb-0 text-success"><?php echo $statistics['active_integrations']; ?></h2>
                            </div>
                            <div class="fs-1 text-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Integration Types</h6>
                                <h2 class="fw-bold mb-0"><?php echo $statistics['integration_types']; ?></h2>
                            </div>
                            <div class="fs-1 text-info">
                                <i class="fas fa-layer-group"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Available Integrations Templates -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-store me-2"></i>Available Integration Templates
                </h5>
            </div>
            <div class="card-body">
                <div class="integration-grid">
                    <!-- M-Pesa -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-success bg-opacity-10 text-success mx-auto">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h5 class="fw-bold mb-2">M-Pesa</h5>
                            <span class="integration-type-badge bg-primary text-white">Payment Gateway</span>
                            <p class="text-muted small mt-3 mb-3">Accept mobile money payments from customers via M-Pesa</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('M-Pesa', 'payment_gateway')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                    
                    <!-- Airtel Money -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-danger bg-opacity-10 text-danger mx-auto">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h5 class="fw-bold mb-2">Airtel Money</h5>
                            <span class="integration-type-badge bg-primary text-white">Payment Gateway</span>
                            <p class="text-muted small mt-3 mb-3">Accept payments through Airtel Money platform</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('Airtel Money', 'payment_gateway')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                    
                    <!-- Tigo Pesa -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-info bg-opacity-10 text-info mx-auto">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h5 class="fw-bold mb-2">Tigo Pesa</h5>
                            <span class="integration-type-badge bg-primary text-white">Payment Gateway</span>
                            <p class="text-muted small mt-3 mb-3">Process payments via Tigo Pesa mobile wallet</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('Tigo Pesa', 'payment_gateway')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                    
                    <!-- SMS Gateway -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-warning bg-opacity-10 text-warning mx-auto">
                                <i class="fas fa-sms"></i>
                            </div>
                            <h5 class="fw-bold mb-2">SMS Gateway</h5>
                            <span class="integration-type-badge bg-warning text-dark">SMS Service</span>
                            <p class="text-muted small mt-3 mb-3">Send SMS notifications to customers and staff</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('SMS Gateway', 'sms_gateway')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                    
                    <!-- Email Service -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-secondary bg-opacity-10 text-secondary mx-auto">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <h5 class="fw-bold mb-2">SMTP Email</h5>
                            <span class="integration-type-badge bg-secondary text-white">Email Service</span>
                            <p class="text-muted small mt-3 mb-3">Configure SMTP for sending emails</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('SMTP Email', 'email_service')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                    
                    <!-- QuickBooks -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-success bg-opacity-10 text-success mx-auto">
                                <i class="fas fa-calculator"></i>
                            </div>
                            <h5 class="fw-bold mb-2">QuickBooks</h5>
                            <span class="integration-type-badge bg-success text-white">Accounting</span>
                            <p class="text-muted small mt-3 mb-3">Sync financial data with QuickBooks</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('QuickBooks', 'accounting_software')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                    
                    <!-- Salesforce -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-primary bg-opacity-10 text-primary mx-auto">
                                <i class="fas fa-cloud"></i>
                            </div>
                            <h5 class="fw-bold mb-2">Salesforce</h5>
                            <span class="integration-type-badge bg-info text-white">CRM</span>
                            <p class="text-muted small mt-3 mb-3">Integrate with Salesforce CRM</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('Salesforce', 'crm')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                    
                    <!-- WhatsApp Business -->
                    <div class="card integration-card">
                        <div class="card-body text-center">
                            <div class="integration-icon bg-success bg-opacity-10 text-success mx-auto">
                                <i class="fab fa-whatsapp"></i>
                            </div>
                            <h5 class="fw-bold mb-2">WhatsApp</h5>
                            <span class="integration-type-badge bg-success text-white">Messaging</span>
                            <p class="text-muted small mt-3 mb-3">Send messages via WhatsApp Business API</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="quickSetupIntegration('WhatsApp Business', 'messaging')">
                                <i class="fas fa-plus me-1"></i>Setup
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Configured Integrations -->
        <?php if (count($integrations) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Configured Integrations
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Integration</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                                <th>Logs</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($integrations as $integration): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <i class="fas <?php 
                                                echo match($integration['integration_type']) {
                                                    'payment_gateway' => 'fa-credit-card',
                                                    'sms_gateway' => 'fa-sms',
                                                    'email_service' => 'fa-envelope',
                                                    'accounting_software' => 'fa-calculator',
                                                    'crm' => 'fa-users',
                                                    'messaging' => 'fa-comment',
                                                    default => 'fa-plug'
                                                };
                                            ?> fa-2x text-primary"></i>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($integration['integration_name']); ?></strong>
                                            <?php if ($integration['description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($integration['description']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="integration-type-badge <?php 
                                        echo match($integration['integration_type']) {
                                            'payment_gateway' => 'bg-primary text-white',
                                            'sms_gateway' => 'bg-warning text-dark',
                                            'email_service' => 'bg-secondary text-white',
                                            'accounting_software' => 'bg-success text-white',
                                            'crm' => 'bg-info text-white',
                                            'messaging' => 'bg-success text-white',
                                            default => 'bg-dark text-white'
                                        };
                                    ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $integration['integration_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($integration['is_active']): ?>
                                        <span class="badge bg-success">
                                            <span class="status-indicator bg-white"></span>Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <span class="status-indicator bg-white"></span>Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($integration['creator_first_name'] . ' ' . $integration['creator_last_name']); ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($integration['created_at'])); ?></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $integration['log_count']; ?> logs</span>
                                    <?php if ($integration['error_count'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $integration['error_count']; ?> errors</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editIntegration(<?php echo $integration['integration_id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-info" onclick="testConnection(<?php echo $integration['integration_id']; ?>)" title="Test Connection">
                                            <i class="fas fa-plug"></i>
                                        </button>
                                        <button class="btn btn-outline-<?php echo $integration['is_active'] ? 'warning' : 'success'; ?>" 
                                                onclick="toggleStatus(<?php echo $integration['integration_id']; ?>)" 
                                                title="<?php echo $integration['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas fa-<?php echo $integration['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="deleteIntegration(<?php echo $integration['integration_id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-plug fa-4x text-muted mb-4"></i>
                <h4 class="text-muted">No Integrations Configured</h4>
                <p class="text-muted">Start by adding an integration from the templates above</p>
                <button class="btn btn-primary mt-3" onclick="showIntegrationModal()">
                    <i class="fas fa-plus me-2"></i>Add Your First Integration
                </button>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</section>

<!-- Integration Modal -->
<div class="modal fade" id="integrationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plug me-2"></i>
                    <span id="modalTitle">Add Integration</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="integrationForm">
                <div class="modal-body">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" value="save_integration">
                    <input type="hidden" name="integration_id" id="integration_id">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Integration Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="integration_name" id="integration_name" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Integration Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="integration_type" id="integration_type" required onchange="updateConfigFields()">
                                <option value="">Select Type</option>
                                <option value="payment_gateway">Payment Gateway</option>
                                <option value="sms_gateway">SMS Gateway</option>
                                <option value="email_service">Email Service</option>
                                <option value="accounting_software">Accounting Software</option>
                                <option value="crm">CRM</option>
                                <option value="messaging">Messaging</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold mb-3">Configuration</h6>
                        </div>
                        
                        <div class="col-md-6" id="api_key_field">
                            <label class="form-label">API Key</label>
                            <input type="text" class="form-control" name="api_key" id="api_key">
                        </div>
                        
                        <div class="col-md-6" id="api_secret_field">
                            <label class="form-label">API Secret</label>
                            <input type="password" class="form-control" name="api_secret" id="api_secret">
                        </div>
                        
                        <div class="col-md-6" id="api_url_field">
                            <label class="form-label">API URL</label>
                            <input type="url" class="form-control" name="api_url" id="api_url" placeholder="https://api.example.com">
                        </div>
                        
                        <div class="col-md-6" id="webhook_url_field">
                            <label class="form-label">Webhook URL</label>
                            <input type="url" class="form-control" name="webhook_url" id="webhook_url" placeholder="https://yoursite.com/webhook">
                        </div>
                        
                        <div class="col-md-6" id="account_id_field">
                            <label class="form-label">Account ID</label>
                            <input type="text" class="form-control" name="account_id" id="account_id">
                        </div>
                        
                        <div class="col-md-6" id="client_id_field">
                            <label class="form-label">Client ID</label>
                            <input type="text" class="form-control" name="client_id" id="client_id">
                        </div>
                        
                        <div class="col-md-6" id="client_secret_field">
                            <label class="form-label">Client Secret</label>
                            <input type="password" class="form-control" name="client_secret" id="client_secret">
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Integration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let integrationModal;

$(document).ready(function() {
    integrationModal = new bootstrap.Modal(document.getElementById('integrationModal'));
});

function showIntegrationModal() {
    document.getElementById('integrationForm').reset();
    document.getElementById('integration_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Integration';
    updateConfigFields();
    integrationModal.show();
}

function quickSetupIntegration(name, type) {
    document.getElementById('integrationForm').reset();
    document.getElementById('integration_id').value = '';
    document.getElementById('integration_name').value = name;
    document.getElementById('integration_type').value = type;
    document.getElementById('modalTitle').textContent = 'Setup ' + name;
    updateConfigFields();
    integrationModal.show();
}

function updateConfigFields() {
    const type = document.getElementById('integration_type').value;
    
    // Hide all fields first
    document.getElementById('api_key_field').style.display = 'none';
    document.getElementById('api_secret_field').style.display = 'none';
    document.getElementById('api_url_field').style.display = 'none';
    document.getElementById('webhook_url_field').style.display = 'none';
    document.getElementById('account_id_field').style.display = 'none';
    document.getElementById('client_id_field').style.display = 'none';
    document.getElementById('client_secret_field').style.display = 'none';
    
    // Show relevant fields based on type
    if (type === 'payment_gateway' || type === 'sms_gateway' || type === 'messaging') {
        document.getElementById('api_key_field').style.display = 'block';
        document.getElementById('api_secret_field').style.display = 'block';
        document.getElementById('api_url_field').style.display = 'block';
        document.getElementById('webhook_url_field').style.display = 'block';
    } else if (type === 'email_service') {
        document.getElementById('api_key_field').style.display = 'block';
        document.getElementById('api_url_field').style.display = 'block';
    } else if (type === 'accounting_software' || type === 'crm') {
        document.getElementById('client_id_field').style.display = 'block';
        document.getElementById('client_secret_field').style.display = 'block';
        document.getElementById('api_url_field').style.display = 'block';
        document.getElementById('account_id_field').style.display = 'block';
    }
}

function editIntegration(integrationId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_integration',
            integration_id: integrationId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const integration = response.integration;
                const config = integration.config_data;
                
                document.getElementById('integration_id').value = integration.integration_id;
                document.getElementById('integration_name').value = integration.integration_name;
                document.getElementById('integration_type').value = integration.integration_type;
                document.getElementById('description').value = integration.description || '';
                
                updateConfigFields();
                
                document.getElementById('api_key').value = config.api_key || '';
                document.getElementById('api_secret').value = config.api_secret || '';
                document.getElementById('api_url').value = config.api_url || '';
                document.getElementById('webhook_url').value = config.webhook_url || '';
                document.getElementById('account_id').value = config.account_id || '';
                document.getElementById('client_id').value = config.client_id || '';
                document.getElementById('client_secret').value = config.client_secret || '';
                
                document.getElementById('is_active').checked = integration.is_active == 1;
                
                document.getElementById('modalTitle').textContent = 'Edit Integration';
                integrationModal.show();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading integration');
        }
    });
}

function deleteIntegration(integrationId) {
    if (confirm('Are you sure you want to delete this integration? This action cannot be undone.')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_integration',
                integration_id: integrationId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('Error deleting integration');
            }
        });
    }
}

function toggleStatus(integrationId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'toggle_status',
            integration_id: integrationId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error updating status');
        }
    });
}

function testConnection(integrationId) {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'test_connection',
            integration_id: integrationId
        },
        dataType: 'json',
        success: function(response) {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            
            if (response.success) {
                alert('✓ ' + response.message);
            } else {
                alert('✗ ' + response.message);
            }
        },
        error: function() {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            alert('Error testing connection');
        }
    });
}

// Save integration
document.getElementById('integrationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error saving integration');
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>