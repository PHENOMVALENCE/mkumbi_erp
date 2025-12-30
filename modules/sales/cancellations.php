<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = [];

// Handle cancellation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_reservation') {
    $conn->beginTransaction();
    
    try {
        $reservation_id = intval($_POST['reservation_id']);
        $cancellation_reason = $_POST['cancellation_reason'];
        $detailed_reason = trim($_POST['detailed_reason']);
        $penalty_amount = floatval($_POST['penalty_amount'] ?? 0);
        $amount_forfeited = floatval($_POST['amount_forfeited'] ?? 0);
        $plot_return_status = $_POST['plot_return_status'] ?? 'returned_to_market';
        $internal_notes = trim($_POST['internal_notes'] ?? '');
        
        // Validate inputs
        if (empty($reservation_id) || empty($cancellation_reason) || empty($detailed_reason)) {
            $errors[] = "Reservation, reason, and detailed explanation are required";
        } else {
            // Get reservation details
            $reservation_sql = "SELECT r.*, p.plot_id, p.plot_number, p.status as plot_status,
                                      pr.project_name,
                                      c.full_name as customer_name,
                                      COALESCE(SUM(pay.amount), 0) as total_paid
                               FROM reservations r
                               INNER JOIN plots p ON r.plot_id = p.plot_id
                               INNER JOIN projects pr ON p.project_id = pr.project_id
                               INNER JOIN customers c ON r.customer_id = c.customer_id
                               LEFT JOIN payments pay ON r.reservation_id = pay.reservation_id 
                                   AND pay.status = 'approved'
                               WHERE r.reservation_id = ? AND r.company_id = ?
                               GROUP BY r.reservation_id";
            
            $stmt = $conn->prepare($reservation_sql);
            $stmt->execute([$reservation_id, $company_id]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reservation) {
                $errors[] = "Reservation not found";
            } elseif ($reservation['status'] === 'cancelled') {
                $errors[] = "This reservation is already cancelled";
            } else {
                // Generate cancellation number
                $cancellation_number = 'CAN-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Calculate refund amount (if any)
                $refund_amount = $reservation['total_paid'] - $amount_forfeited - $penalty_amount;
                $refund_amount = max(0, $refund_amount); // Ensure non-negative
                
                // Insert cancellation record
                $insert_sql = "INSERT INTO reservation_cancellations 
                              (company_id, reservation_id, cancellation_number, cancellation_date,
                               cancellation_reason, detailed_reason, total_amount_paid, 
                               refund_amount, penalty_amount, amount_forfeited, plot_id,
                               plot_return_status, internal_notes, created_by, created_at)
                              VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->execute([
                    $company_id,
                    $reservation_id,
                    $cancellation_number,
                    $cancellation_reason,
                    $detailed_reason,
                    $reservation['total_paid'],
                    $refund_amount,
                    $penalty_amount,
                    $amount_forfeited,
                    $reservation['plot_id'],
                    $plot_return_status,
                    $internal_notes,
                    $_SESSION['user_id']
                ]);
                
                // Update reservation status to cancelled
                $update_reservation_sql = "UPDATE reservations 
                                          SET status = 'cancelled', 
                                              updated_at = NOW() 
                                          WHERE reservation_id = ? AND company_id = ?";
                $stmt = $conn->prepare($update_reservation_sql);
                $stmt->execute([$reservation_id, $company_id]);
                
                // Update plot status based on return status
                if ($plot_return_status === 'returned_to_market') {
                    $update_plot_sql = "UPDATE plots 
                                       SET status = 'available', 
                                           updated_at = NOW() 
                                       WHERE plot_id = ? AND company_id = ?";
                } else {
                    $update_plot_sql = "UPDATE plots 
                                       SET status = ?, 
                                           updated_at = NOW() 
                                       WHERE plot_id = ? AND company_id = ?";
                }
                
                $stmt = $conn->prepare($update_plot_sql);
                if ($plot_return_status === 'returned_to_market') {
                    $stmt->execute([$reservation['plot_id'], $company_id]);
                } else {
                    $stmt->execute([$plot_return_status, $reservation['plot_id'], $company_id]);
                }
                
                $conn->commit();
                
                $success[] = "Reservation <strong>{$reservation['reservation_number']}</strong> cancelled successfully! Cancellation Number: <strong>$cancellation_number</strong>";
                
                if ($refund_amount > 0) {
                    $success[] = "Refund amount: <strong>TZS " . number_format($refund_amount, 2) . "</strong>. Please process refund from the refund module.";
                }
            }
        }
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $errors[] = "Error processing cancellation: " . $e->getMessage();
    }
}

// Fetch all cancellations
try {
    $cancellations_sql = "SELECT 
                            rc.cancellation_id,
                            rc.cancellation_number,
                            rc.cancellation_date,
                            rc.cancellation_reason,
                            rc.detailed_reason,
                            rc.total_amount_paid,
                            rc.refund_amount,
                            rc.penalty_amount,
                            rc.amount_forfeited,
                            rc.plot_return_status,
                            rc.created_at,
                            r.reservation_number,
                            r.total_amount as reservation_amount,
                            c.full_name as customer_name,
                            c.phone as customer_phone,
                            c.email as customer_email,
                            pl.plot_number,
                            pr.project_name,
                            u.full_name as cancelled_by
                         FROM reservation_cancellations rc
                         INNER JOIN reservations r ON rc.reservation_id = r.reservation_id
                         INNER JOIN customers c ON r.customer_id = c.customer_id
                         INNER JOIN plots pl ON rc.plot_id = pl.plot_id
                         INNER JOIN projects pr ON pl.project_id = pr.project_id
                         LEFT JOIN users u ON rc.created_by = u.user_id
                         WHERE rc.company_id = ?
                         ORDER BY rc.cancellation_date DESC";
    
    $stmt = $conn->prepare($cancellations_sql);
    $stmt->execute([$company_id]);
    $cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $stats = [
        'total' => count($cancellations),
        'total_amount_lost' => array_sum(array_column($cancellations, 'amount_forfeited')),
        'total_refunded' => array_sum(array_column($cancellations, 'refund_amount')),
        'this_month' => 0
    ];
    
    foreach ($cancellations as $cancellation) {
        if (date('Y-m', strtotime($cancellation['cancellation_date'])) === date('Y-m')) {
            $stats['this_month']++;
        }
    }
    
} catch (PDOException $e) {
    $cancellations = [];
    $errors[] = "Error fetching cancellations: " . $e->getMessage();
}

// Fetch active reservations that can be cancelled
try {
    $reservations_sql = "SELECT 
                            r.reservation_id,
                            r.reservation_number,
                            r.reservation_date,
                            r.total_amount,
                            r.down_payment,
                            r.status,
                            c.full_name as customer_name,
                            c.phone as customer_phone,
                            pl.plot_number,
                            pr.project_name,
                            COALESCE(SUM(p.amount), 0) as total_paid,
                            COUNT(p.payment_id) as payment_count
                         FROM reservations r
                         INNER JOIN customers c ON r.customer_id = c.customer_id
                         INNER JOIN plots pl ON r.plot_id = pl.plot_id
                         INNER JOIN projects pr ON pl.project_id = pr.project_id
                         LEFT JOIN payments p ON r.reservation_id = p.reservation_id 
                             AND p.status = 'approved'
                         WHERE r.company_id = ? 
                         AND r.status IN ('active', 'draft')
                         GROUP BY r.reservation_id
                         ORDER BY r.reservation_date DESC";
    
    $stmt = $conn->prepare($reservations_sql);
    $stmt->execute([$company_id]);
    $active_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $active_reservations = [];
}

$page_title = 'Reservation Cancellations';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-align: center;
    transition: all 0.3s;
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stats-number {
    font-size: 36px;
    font-weight: 800;
    color: #dc3545;
}

.stats-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    margin-top: 5px;
}

.form-card {
    background: white;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.table-container {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow: hidden;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 15px 12px;
}

.table tbody td {
    padding: 12px;
    vertical-align: middle;
}

.badge-returned {
    background: #28a745;
    color: #fff;
}

.badge-blocked {
    background: #dc3545;
    color: #fff;
}

.badge-reserved {
    background: #ffc107;
    color: #000;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-ban"></i> Reservation Cancellations</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="reservations.php">Reservations</a></li>
                    <li class="breadcrumb-item active">Cancellations</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-ban"></i> Error!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-check-circle"></i> Success!</h5>
            <ul class="mb-0">
                <?php foreach ($success as $msg): ?>
                    <li><?php echo $msg; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-danger"><?php echo $stats['total']; ?></div>
                    <div class="stats-label">Total Cancellations</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $stats['this_month']; ?></div>
                    <div class="stats-label">This Month</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-danger" style="font-size: 24px;">
                        TZS <?php echo number_format($stats['total_amount_lost'], 0); ?>
                    </div>
                    <div class="stats-label">Amount Forfeited</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-success" style="font-size: 24px;">
                        TZS <?php echo number_format($stats['total_refunded'], 0); ?>
                    </div>
                    <div class="stats-label">Total Refunded</div>
                </div>
            </div>
        </div>

        <!-- Cancellation Form -->
        <div class="form-card">
            <h5 class="mb-4"><i class="fas fa-times-circle"></i> Cancel Reservation</h5>
            
            <form method="POST" id="cancellationForm">
                <input type="hidden" name="action" value="cancel_reservation">

                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Warning:</strong> Cancelling a reservation will update the reservation status and plot availability. This action cannot be undone.
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Reservation <span class="text-danger">*</span></label>
                            <select name="reservation_id" id="reservation_id" class="form-select" required onchange="loadReservationInfo()">
                                <option value="">-- Select Reservation --</option>
                                <?php foreach ($active_reservations as $res): ?>
                                    <option value="<?php echo $res['reservation_id']; ?>"
                                            data-number="<?php echo htmlspecialchars($res['reservation_number']); ?>"
                                            data-customer="<?php echo htmlspecialchars($res['customer_name']); ?>"
                                            data-phone="<?php echo htmlspecialchars($res['customer_phone']); ?>"
                                            data-plot="<?php echo htmlspecialchars($res['plot_number']); ?>"
                                            data-project="<?php echo htmlspecialchars($res['project_name']); ?>"
                                            data-total="<?php echo $res['total_amount']; ?>"
                                            data-paid="<?php echo $res['total_paid']; ?>"
                                            data-payments="<?php echo $res['payment_count']; ?>">
                                        <?php echo htmlspecialchars($res['reservation_number']); ?> - 
                                        <?php echo htmlspecialchars($res['customer_name']); ?> - 
                                        Plot <?php echo htmlspecialchars($res['plot_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Cancellation Reason <span class="text-danger">*</span></label>
                            <select name="cancellation_reason" class="form-select" required>
                                <option value="">-- Select Reason --</option>
                                <option value="customer_request">Customer Request</option>
                                <option value="payment_default">Payment Default</option>
                                <option value="mutual_agreement">Mutual Agreement</option>
                                <option value="breach_of_contract">Breach of Contract</option>
                                <option value="plot_unavailable">Plot Unavailable</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Reservation Details Display -->
                <div id="reservationDetails" style="display: none;" class="alert alert-light mb-3">
                    <h6 class="fw-bold"><i class="fas fa-info-circle"></i> Reservation Details</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Customer:</strong> <span id="detail_customer"></span></p>
                            <p class="mb-1"><strong>Phone:</strong> <span id="detail_phone"></span></p>
                            <p class="mb-1"><strong>Plot:</strong> <span id="detail_plot"></span></p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Project:</strong> <span id="detail_project"></span></p>
                            <p class="mb-1"><strong>Total Amount:</strong> <span id="detail_total" class="text-primary"></span></p>
                            <p class="mb-1"><strong>Amount Paid:</strong> <span id="detail_paid" class="text-success"></span></p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Outstanding:</strong> <span id="detail_outstanding" class="text-danger"></span></p>
                            <p class="mb-1"><strong>Payments Made:</strong> <span id="detail_payments"></span></p>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Penalty Amount</label>
                            <input type="number" name="penalty_amount" id="penalty_amount" 
                                   class="form-control" step="0.01" min="0" value="0" 
                                   placeholder="0.00" onchange="calculateRefund()">
                            <small class="text-muted">Cancellation fee charged to customer</small>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Amount Forfeited</label>
                            <input type="number" name="amount_forfeited" id="amount_forfeited" 
                                   class="form-control" step="0.01" min="0" value="0" 
                                   placeholder="0.00" onchange="calculateRefund()">
                            <small class="text-muted">Amount not refundable (e.g., admin fees)</small>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Plot Return Status <span class="text-danger">*</span></label>
                            <select name="plot_return_status" class="form-select" required>
                                <option value="returned_to_market">Return to Market (Available)</option>
                                <option value="blocked">Block Plot</option>
                                <option value="reserved_for_other">Reserve for Another Customer</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Detailed Explanation <span class="text-danger">*</span></label>
                    <textarea name="detailed_reason" class="form-control" rows="3" required
                              placeholder="Provide detailed explanation for this cancellation..."></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Internal Notes</label>
                    <textarea name="internal_notes" class="form-control" rows="2"
                              placeholder="Internal notes (not visible to customer)..."></textarea>
                </div>

                <!-- Refund Calculation Summary -->
                <div id="refundSummary" style="display: none;" class="alert alert-warning">
                    <h6 class="fw-bold"><i class="fas fa-calculator"></i> Financial Summary</h6>
                    <div class="row">
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Total Paid:</strong></p>
                            <h5 class="text-success" id="summary_paid">TZS 0</h5>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Penalty:</strong></p>
                            <h5 class="text-danger" id="summary_penalty">TZS 0</h5>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Forfeited:</strong></p>
                            <h5 class="text-danger" id="summary_forfeited">TZS 0</h5>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Refund Amount:</strong></p>
                            <h5 class="text-primary" id="summary_refund">TZS 0</h5>
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-ban"></i> Cancel Reservation
                    </button>
                </div>
            </form>
        </div>

        <!-- Cancellations List -->
        <div class="table-container">
            <div class="p-3 border-bottom">
                <h5 class="mb-0"><i class="fas fa-list"></i> Cancellation Records</h5>
            </div>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Cancellation #</th>
                        <th>Reservation</th>
                        <th>Customer</th>
                        <th>Plot</th>
                        <th>Paid</th>
                        <th>Refund</th>
                        <th>Forfeited</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cancellations)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No cancellation records found</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($cancellations as $cancellation): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($cancellation['cancellation_date'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($cancellation['cancellation_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($cancellation['reservation_number']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($cancellation['customer_name']); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($cancellation['customer_phone']); ?></small>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($cancellation['plot_number']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($cancellation['project_name']); ?></small>
                            </td>
                            <td class="text-success">
                                <strong>TZS <?php echo number_format($cancellation['total_amount_paid'], 2); ?></strong>
                            </td>
                            <td class="text-primary">
                                <strong>TZS <?php echo number_format($cancellation['refund_amount'], 2); ?></strong>
                            </td>
                            <td class="text-danger">
                                <strong>TZS <?php echo number_format($cancellation['amount_forfeited'], 2); ?></strong>
                            </td>
                            <td><?php echo ucwords(str_replace('_', ' ', $cancellation['cancellation_reason'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick='viewCancellationDetails(<?php echo json_encode($cancellation); ?>)'>
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</section>

<!-- Cancellation Details Modal -->
<div class="modal fade" id="cancellationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-ban"></i> Cancellation Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Cancellation Number:</strong> <span id="modal_cancellation_number"></span></p>
                        <p><strong>Date:</strong> <span id="modal_date"></span></p>
                        <p><strong>Reservation:</strong> <span id="modal_reservation"></span></p>
                        <p><strong>Customer:</strong> <span id="modal_customer"></span></p>
                        <p><strong>Plot:</strong> <span id="modal_plot"></span></p>
                        <p><strong>Project:</strong> <span id="modal_project"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Total Paid:</strong> <span id="modal_paid" class="text-success"></span></p>
                        <p><strong>Penalty:</strong> <span id="modal_penalty" class="text-danger"></span></p>
                        <p><strong>Forfeited:</strong> <span id="modal_forfeited" class="text-danger"></span></p>
                        <p><strong>Refund Amount:</strong> <span id="modal_refund" class="text-primary"></span></p>
                        <p><strong>Plot Status:</strong> <span id="modal_plot_status"></span></p>
                        <p><strong>Cancelled By:</strong> <span id="modal_cancelled_by"></span></p>
                    </div>
                </div>
                <hr>
                <p><strong>Cancellation Reason:</strong> <span id="modal_reason"></span></p>
                <div class="alert alert-info">
                    <strong>Detailed Explanation:</strong>
                    <p id="modal_detailed_reason" class="mb-0"></p>
                </div>
                <div id="modal_notes_section" style="display: none;">
                    <div class="alert alert-warning">
                        <strong>Internal Notes:</strong>
                        <p id="modal_notes" class="mb-0"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let selectedTotalPaid = 0;

function loadReservationInfo() {
    const select = document.getElementById('reservation_id');
    const selectedOption = select.options[select.selectedIndex];
    const detailsDiv = document.getElementById('reservationDetails');
    
    if (select.value) {
        const totalAmount = parseFloat(selectedOption.dataset.total);
        const paidAmount = parseFloat(selectedOption.dataset.paid);
        const outstanding = totalAmount - paidAmount;
        selectedTotalPaid = paidAmount;
        
        document.getElementById('detail_customer').textContent = selectedOption.dataset.customer;
        document.getElementById('detail_phone').textContent = selectedOption.dataset.phone;
        document.getElementById('detail_plot').textContent = 'Plot ' + selectedOption.dataset.plot;
        document.getElementById('detail_project').textContent = selectedOption.dataset.project;
        document.getElementById('detail_total').textContent = 'TZS ' + totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('detail_paid').textContent = 'TZS ' + paidAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('detail_outstanding').textContent = 'TZS ' + outstanding.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('detail_payments').textContent = selectedOption.dataset.payments + ' payment(s)';
        
        detailsDiv.style.display = 'block';
        calculateRefund();
    } else {
        detailsDiv.style.display = 'none';
        document.getElementById('refundSummary').style.display = 'none';
        selectedTotalPaid = 0;
    }
}

function calculateRefund() {
    const penalty = parseFloat(document.getElementById('penalty_amount').value) || 0;
    const forfeited = parseFloat(document.getElementById('amount_forfeited').value) || 0;
    
    if (selectedTotalPaid > 0) {
        const refund = Math.max(0, selectedTotalPaid - penalty - forfeited);
        
        document.getElementById('summary_paid').textContent = 'TZS ' + selectedTotalPaid.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_penalty').textContent = 'TZS ' + penalty.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_forfeited').textContent = 'TZS ' + forfeited.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_refund').textContent = 'TZS ' + refund.toLocaleString('en-US', {minimumFractionDigits: 2});
        
        document.getElementById('refundSummary').style.display = 'block';
    }
}

function viewCancellationDetails(data) {
    document.getElementById('modal_cancellation_number').textContent = data.cancellation_number;
    document.getElementById('modal_date').textContent = new Date(data.cancellation_date).toLocaleDateString();
    document.getElementById('modal_reservation').textContent = data.reservation_number;
    document.getElementById('modal_customer').textContent = data.customer_name;
    document.getElementById('modal_plot').textContent = 'Plot ' + data.plot_number;
    document.getElementById('modal_project').textContent = data.project_name;
    document.getElementById('modal_paid').textContent = 'TZS ' + parseFloat(data.total_amount_paid).toLocaleString();
    document.getElementById('modal_penalty').textContent = 'TZS ' + parseFloat(data.penalty_amount).toLocaleString();
    document.getElementById('modal_forfeited').textContent = 'TZS ' + parseFloat(data.amount_forfeited).toLocaleString();
    document.getElementById('modal_refund').textContent = 'TZS ' + parseFloat(data.refund_amount).toLocaleString();
    
    const statusBadges = {
        'returned_to_market': '<span class="badge badge-returned">Returned to Market</span>',
        'blocked': '<span class="badge badge-blocked">Blocked</span>',
        'reserved_for_other': '<span class="badge badge-reserved">Reserved for Other</span>'
    };
    document.getElementById('modal_plot_status').innerHTML = statusBadges[data.plot_return_status] || data.plot_return_status;
    
    document.getElementById('modal_cancelled_by').textContent = data.cancelled_by || 'N/A';
    document.getElementById('modal_reason').textContent = data.cancellation_reason.replace(/_/g, ' ').toUpperCase();
    document.getElementById('modal_detailed_reason').textContent = data.detailed_reason;
    
    if (data.internal_notes) {
        document.getElementById('modal_notes_section').style.display = 'block';
        document.getElementById('modal_notes').textContent = data.internal_notes;
    } else {
        document.getElementById('modal_notes_section').style.display = 'none';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('cancellationModal'));
    modal.show();
}

function resetForm() {
    document.getElementById('cancellationForm').reset();
    document.getElementById('reservationDetails').style.display = 'none';
    document.getElementById('refundSummary').style.display = 'none';
    selectedTotalPaid = 0;
}

// Auto-dismiss alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
    });
}, 5000);
</script>