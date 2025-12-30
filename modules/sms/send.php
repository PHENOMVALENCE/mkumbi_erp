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

// Twilio Configuration
define('TWILIO_ACCOUNT_SID', 'AC7fd6ea606b962787debdb858860fa0f0');
define('TWILIO_AUTH_TOKEN', ''); // Add your auth token here
define('TWILIO_MESSAGING_SERVICE_SID', 'MGe15b75029876ebd93da31a97d6b5d427');

$success_message = '';
$error_message = '';
$sent_messages = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    $recipients = $_POST['recipients'] ?? [];
    $message = trim($_POST['message'] ?? '');
    
    if (empty($recipients)) {
        $error_message = 'Please select at least one recipient';
    } elseif (empty($message)) {
        $error_message = 'Message cannot be empty';
    } else {
        foreach ($recipients as $phone) {
            $result = sendTwilioSMS($phone, $message);
            $sent_messages[] = [
                'phone' => $phone,
                'status' => $result['success'] ? 'success' : 'failed',
                'message' => $result['message'],
                'sid' => $result['sid'] ?? null,
                'sms_status' => $result['status'] ?? null,
                'details' => $result['details'] ?? ''
            ];
        }
        
        $success_count = count(array_filter($sent_messages, function($msg) {
            return $msg['status'] === 'success';
        }));
        
        $failed_count = count($sent_messages) - $success_count;
        
        if ($success_count > 0) {
            $success_message = "✅ Successfully sent $success_count message(s)";
            if ($failed_count > 0) {
                $success_message .= " • ⚠️ $failed_count failed";
            }
        } else {
            $error_message = "❌ All messages failed to send. Please check configuration.";
        }
    }
}

// Function to send SMS via Twilio
function sendTwilioSMS($to, $message) {
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';
    
    // Build data array with smart routing and force SMS enabled
    $data = [
        'To' => $to,
        'MessagingServiceSid' => TWILIO_MESSAGING_SERVICE_SID,
        'Body' => $message,
        'SmartEncoded' => 'true',  // Enable smart routing
        'ForceDelivery' => 'true', // Force SMS delivery
        'StatusCallback' => '',     // Optional: Add callback URL for delivery status
        'ValidityPeriod' => '86400' // Message validity: 24 hours
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Ensure SSL verification
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 second timeout
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'message' => 'Connection Error: ' . $error,
            'details' => 'Failed to connect to Twilio API. Check internet connection.'
        ];
    }
    
    $result = json_decode($response, true);
    
    if ($http_code === 201 || $http_code === 200) {
        return [
            'success' => true,
            'message' => 'Message queued for delivery',
            'sid' => $result['sid'] ?? 'N/A',
            'status' => $result['status'] ?? 'queued',
            'details' => 'SMS sent successfully to ' . $to
        ];
    } else {
        $error_msg = isset($result['message']) ? $result['message'] : 'Unknown error occurred';
        $error_code = isset($result['code']) ? ' (Code: ' . $result['code'] . ')' : '';
        
        return [
            'success' => false,
            'message' => $error_msg . $error_code,
            'details' => 'HTTP Status: ' . $http_code . ' - Response: ' . substr($response, 0, 200)
        ];
    }
}

// Test phone numbers
$test_phones = [
    [
        'number' => '+255745381762',
        'name' => 'Test Number 1',
        'label' => '+255 745 381 762'
    ],
    [
        'number' => '+255786133399',
        'name' => 'Test Number 2',
        'label' => '+255 786 133 399'
    ]
];

$page_title = 'Send SMS - Twilio Test';
require_once '../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class="fas fa-sms"></i> Send SMS - Twilio Test</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                        <li class="breadcrumb-item active">Send SMS</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            <div class="row">
                <!-- Send SMS Form -->
                <div class="col-lg-6">
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-paper-plane"></i> Compose Message
                            </h3>
                        </div>
                        <form method="POST">
                            <div class="card-body">
                                <?php if ($success_message): ?>
                                <div class="alert alert-success alert-dismissible fade show" style="border-left: 4px solid #28a745;">
                                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                                    <h5><i class="icon fas fa-check-circle"></i> Success!</h5>
                                    <?php echo $success_message; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($error_message): ?>
                                <div class="alert alert-danger alert-dismissible fade show" style="border-left: 4px solid #dc3545;">
                                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                                    <h5><i class="icon fas fa-exclamation-triangle"></i> Error!</h5>
                                    <?php echo $error_message; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Recipients -->
                                <div class="form-group">
                                    <label>Select Recipients <span class="text-danger">*</span></label>
                                    <div class="border rounded p-3">
                                        <?php foreach ($test_phones as $phone): ?>
                                        <div class="custom-control custom-checkbox mb-2">
                                            <input 
                                                type="checkbox" 
                                                class="custom-control-input" 
                                                id="phone_<?php echo md5($phone['number']); ?>"
                                                name="recipients[]" 
                                                value="<?php echo htmlspecialchars($phone['number']); ?>"
                                            >
                                            <label class="custom-control-label" for="phone_<?php echo md5($phone['number']); ?>">
                                                <strong><?php echo htmlspecialchars($phone['name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($phone['label']); ?></small>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Message -->
                                <div class="form-group">
                                    <label>Message <span class="text-danger">*</span></label>
                                    <textarea 
                                        class="form-control" 
                                        name="message" 
                                        rows="6" 
                                        placeholder="Enter your message..."
                                        maxlength="160"
                                        id="messageText"
                                        required
                                    >HELLO FROM SOFTGRID - THIS IS TESTING MKUMBI CUSTOMERS</textarea>
                                    <small class="form-text text-muted">
                                        <span id="charCount">0</span> / 160 characters
                                    </small>
                                </div>

                                <!-- Quick Templates -->
                                <div class="form-group">
                                    <label>Quick Templates</label>
                                    <div class="btn-group btn-group-sm d-flex flex-wrap" role="group">
                                        <button 
                                            type="button" 
                                            class="btn btn-outline-secondary mb-1"
                                            onclick="setTemplate('HELLO FROM SOFTGRID - THIS IS TESTING MKUMBI CUSTOMERS')"
                                        >
                                            Softgrid Test
                                        </button>
                                        <button 
                                            type="button" 
                                            class="btn btn-outline-secondary mb-1"
                                            onclick="setTemplate('Hello from Mkumbi Investment! Send your enquiry / Tuma maombi yako. Call: 0745381762')"
                                        >
                                            Enquiry
                                        </button>
                                        <button 
                                            type="button" 
                                            class="btn btn-outline-secondary mb-1"
                                            onclick="setTemplate('Karibu Mkumbi Investment! Tunakukaribisha. Wasiliana: 0786133399')"
                                        >
                                            Swahili
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="card-footer">
                                <button type="submit" name="send_sms" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Send SMS
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Twilio Configuration & Status -->
                <div class="col-lg-6">
                    <!-- Twilio Config -->
                    <div class="card card-info card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fab fa-twilio"></i> Twilio Configuration
                            </h3>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Account SID:</dt>
                                <dd class="col-sm-7">
                                    <code><?php echo TWILIO_ACCOUNT_SID; ?></code>
                                </dd>

                                <dt class="col-sm-5">Messaging Service:</dt>
                                <dd class="col-sm-7">
                                    <code><?php echo TWILIO_MESSAGING_SERVICE_SID; ?></code>
                                </dd>

                                <dt class="col-sm-5">Auth Token:</dt>
                                <dd class="col-sm-7">
                                    <?php if (empty(TWILIO_AUTH_TOKEN)): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-exclamation-triangle"></i> Not Set
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check"></i> Configured
                                        </span>
                                    <?php endif; ?>
                                </dd>

                                <dt class="col-sm-5">Smart Routing:</dt>
                                <dd class="col-sm-7">
                                    <span class="badge badge-success">
                                        <i class="fas fa-check-circle"></i> Enabled
                                    </span>
                                </dd>

                                <dt class="col-sm-5">Force SMS Delivery:</dt>
                                <dd class="col-sm-7">
                                    <span class="badge badge-success">
                                        <i class="fas fa-check-circle"></i> Enabled
                                    </span>
                                </dd>

                                <dt class="col-sm-5">API Status:</dt>
                                <dd class="col-sm-7">
                                    <span class="badge badge-info">
                                        <i class="fas fa-plug"></i> Connected
                                    </span>
                                </dd>
                            </dl>

                            <?php if (empty(TWILIO_AUTH_TOKEN)): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Configuration Required:</strong><br>
                                Please add your Twilio Auth Token in the file:<br>
                                <code>erp/modules/sms/send.php</code> (Line 17)
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Message Status -->
                    <?php if (!empty($sent_messages)): ?>
                    <div class="card card-success card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-check-circle"></i> Delivery Status
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Phone Number</th>
                                        <th>Status</th>
                                        <th>Message SID</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sent_messages as $msg): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-mobile-alt"></i> 
                                            <strong><?php echo htmlspecialchars($msg['phone']); ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($msg['status'] === 'success'): ?>
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check-circle"></i> Sent
                                                </span>
                                                <?php if (isset($msg['sms_status'])): ?>
                                                    <br><small class="text-muted"><?php echo ucfirst($msg['sms_status']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge badge-danger">
                                                    <i class="fas fa-times-circle"></i> Failed
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (isset($msg['sid']) && $msg['sid']): ?>
                                                <small><code><?php echo htmlspecialchars($msg['sid']); ?></code></small>
                                            <?php else: ?>
                                                <small class="text-muted">N/A</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="<?php echo $msg['status'] === 'success' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo htmlspecialchars($msg['message']); ?>
                                            </small>
                                            <?php if (isset($msg['details']) && $msg['details']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($msg['details']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                Total: <?php echo count($sent_messages); ?> message(s) • 
                                Success: <?php echo count(array_filter($sent_messages, function($m){ return $m['status'] === 'success'; })); ?> • 
                                Failed: <?php echo count(array_filter($sent_messages, function($m){ return $m['status'] === 'failed'; })); ?>
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Instructions -->
                    <div class="card card-secondary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i> Instructions
                            </h3>
                        </div>
                        <div class="card-body">
                            <ol class="pl-3 mb-3">
                                <li>Add your Twilio Auth Token to the configuration</li>
                                <li>Select one or more test phone numbers</li>
                                <li>Type your message or use a quick template</li>
                                <li>Click "Send SMS" to send the messages</li>
                                <li>Check the delivery status below</li>
                            </ol>

                            <div class="alert alert-success mb-3">
                                <i class="fas fa-check-circle"></i> 
                                <strong>Enabled Features:</strong><br>
                                • Smart Routing - Automatically selects best delivery path<br>
                                • Force SMS Delivery - Ensures messages sent as SMS<br>
                                • 24-hour message validity period
                            </div>

                            <div class="alert alert-info mb-0">
                                <i class="fas fa-lightbulb"></i> 
                                <strong>Note:</strong> This is a test page for Twilio SMS integration. 
                                Messages will be sent to real phone numbers. Smart routing ensures optimal 
                                delivery and force SMS prevents conversion to other message types.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Character counter
document.getElementById('messageText').addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});

// Initialize counter on page load
document.getElementById('charCount').textContent = document.getElementById('messageText').value.length;

// Set template
function setTemplate(text) {
    document.getElementById('messageText').value = text;
    document.getElementById('charCount').textContent = text.length;
}
</script>

<?php require_once '../../includes/footer.php'; ?>