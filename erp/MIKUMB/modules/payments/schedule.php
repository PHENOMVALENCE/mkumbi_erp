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

// Get all active reservations
$query = "
    SELECT 
        r.reservation_id,
        r.reservation_number,
        r.total_amount,
        r.down_payment,
        r.payment_periods,
        r.reservation_date,
        c.full_name as customer_name,
        COALESCE(c.phone, c.phone1, c.alternative_phone) as phone,
        pl.plot_number,
        pr.project_name
    FROM reservations r
    INNER JOIN customers c ON r.customer_id = c.customer_id
    INNER JOIN plots pl ON r.plot_id = pl.plot_id
    INNER JOIN projects pr ON pl.project_id = pr.project_id
    WHERE r.company_id = ? AND r.status IN ('active','completed')
    ORDER BY r.reservation_number
";

$stmt = $conn->prepare($query);
$stmt->execute([$company_id]);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$schedules = [];
$sn = 1;

foreach ($reservations as $r) {
    $installment_amount = round(($r['total_amount'] - $r['down_payment']) / $r['payment_periods'], 2);

    // DOWN PAYMENT ROW (always shown)
    $down_paid = 0;
    $down_payment = $conn->prepare("
        SELECT amount, payment_date, status 
        FROM payments 
        WHERE reservation_id = ? AND payment_type = 'down_payment' AND status = 'approved'
        LIMIT 1
    ");
    $down_payment->execute([$r['reservation_id']]);
    $dp = $down_payment->fetch(PDO::FETCH_ASSOC);
    if ($dp) {
        $down_paid = $dp['amount'];
    }

    $down_balance = $r['down_payment'] - $down_paid;

    $schedules[] = [
        'sn' => $sn++,
        'due_date' => $r['reservation_date'],
        'customer_name' => $r['customer_name'],
        'phone' => $r['phone'] ?? '',
        'reservation_number' => $r['reservation_number'],
        'plot_number' => $r['plot_number'],
        'project_name' => $r['project_name'],
        'installment_number' => 'DP',
        'payment_periods' => $r['payment_periods'],
        'installment_amount' => $r['down_payment'],
        'paid_amount' => $down_paid,
        'balance' => $down_balance,
        'late_fee' => 0,
        'days_overdue' => 0,
        'row_class' => ($down_paid >= $r['down_payment']) ? 'table-success' : 'table-danger',
        'status_text' => ($down_paid >= $r['down_payment']) ? 'paid' : 'not paid',
        'badge' => ($down_paid >= $r['down_payment']) ? 'paid' : 'overdue',
        'is_downpayment' => true
    ];

    // INSTALLMENTS
    for ($i = 1; $i <= $r['payment_periods']; $i++) {
        $due_date = date('Y-m-d', strtotime($r['reservation_date'] . " + $i months"));

        $paid = 0;
        $payment = $conn->prepare("
            SELECT amount, payment_date, status 
            FROM payments 
            WHERE reservation_id = ? AND payment_type = 'installment' 
            ORDER BY payment_date ASC LIMIT 1 OFFSET " . ($i - 1)
        );
        $payment->execute([$r['reservation_id']]);
        $p = $payment->fetch(PDO::FETCH_ASSOC);

        if ($p && $p['status'] === 'approved') {
            $paid = $p['amount'];
        }

        $balance = $installment_amount - $paid;
        $days_overdue = (strtotime($due_date) < time()) ? (int)((time() - strtotime($due_date)) / 86400) : 0;
        $is_overdue = $days_overdue > 0 && $balance > 0;

        if ($paid >= $installment_amount) {
            $row_class = 'table-success';
            $status = 'paid';
            $badge = 'paid';
        } elseif ($paid > 0) {
            $row_class = 'table-warning';
            $status = 'partially paid';
            $badge = 'partially-paid';
        } elseif ($is_overdue) {
            $row_class = 'table-danger';
            $status = 'overdue';
            $badge = 'overdue';
        } else {
            $row_class = '';
            $status = 'pending';
            $badge = 'pending';
        }

        $schedules[] = [
            'sn' => $sn++,
            'due_date' => $due_date,
            'customer_name' => $r['customer_name'],
            'phone' => $r['phone'] ?? '',
            'reservation_number' => $r['reservation_number'],
            'plot_number' => $r['plot_number'],
            'project_name' => $r['project_name'],
            'installment_number' => $i,
            'payment_periods' => $r['payment_periods'],
            'installment_amount' => $installment_amount,
            'paid_amount' => $paid,
            'balance' => $balance,
            'late_fee' => 0,
            'days_overdue' => $days_overdue,
            'row_class' => $row_class,
            'status_text' => $status,
            'badge' => $badge,
            'is_downpayment' => false
        ];
    }
}

// Totals
$total_expected = array_sum(array_column($schedules, 'installment_amount'));
$total_collected = array_sum(array_column($schedules, 'paid_amount'));
$total_outstanding = $total_expected - $total_collected;
$total_overdue = 0;
foreach ($schedules as $s) {
    if ($s['days_overdue'] > 0 && $s['balance'] > 0) {
        $total_overdue += $s['balance'];
    }
}

$page_title = 'Payment Recovery';
require_once '../../includes/header.php';
?>

<style>
.stats-card { background:white; border-radius:12px; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,0.1); border-left:6px solid; margin-bottom:1rem; }
.stats-card.primary { border-left-color:#007bff; }
.stats-card.success { border-left-color:#28a745; }
.stats-card.danger { border-left-color:#dc3545; }
.stats-card.warning { border-left-color:#ffc107; }

.legend-container { display:flex; gap:25px; flex-wrap:wrap; padding:18px; background:#f8f9fa; border-radius:12px; margin:20px 0; font-weight:600; }
.legend-item { display:flex; align-items:center; gap:12px; }
.legend-color { width:30px; height:24px; border-radius:6px; }
.legend-color.paid { background:#d4edda; }
.legend-color.partially { background:#fff3cd; }
.legend-color.overdue { background:#f8d7da; }
.legend-color.pending { background:#fff; border:2px solid #dee2e6; }

.status-badge { padding:7px 16px; border-radius:30px; font-size:0.85rem; font-weight:700; text-transform:uppercase; }
.status-badge.paid { background:#28a745; color:white; }
.status-badge.partially-paid { background:#ffc107; color:black; }
.status-badge.overdue { background:#dc3545; color:white; }
.status-badge.pending { background:#6c757d; color:white; }

.dp-badge { 
    background:#007bff; color:white; padding:4px 10px; border-radius:20px; 
    font-size:0.8rem; font-weight:bold; 
}
.installment-circle { 
    display:inline-block; width:38px; height:38px; line-height:38px; 
    text-align:center; border-radius:50%; background:#007bff; color:white; 
    font-weight:bold; font-size:1rem; 
}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <h1 class="m-0 fw-bold text-primary">
            Payment Recovery (Down Payments + Installments)
        </h1>
        <p class="text-muted mb-0">All down payments and installments are displayed</p>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <!-- Stats -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-6"><div class="stats-card primary"><div class="stats-number">TSH <?=number_format($total_expected/1000000,1)?>M</div><div class="stats-label">Total Expected</div></div></div>
            <div class="col-lg-3 col-6"><div class="stats-card success"><div class="stats-number">TSH <?=number_format($total_collected/1000000,1)?>M</div><div class="stats-label">Collected</div></div></div>
            <div class="col-lg-3 col-6"><div class="stats-card danger"><div class="stats-number">TSH <?=number_format($total_outstanding/1000000,1)?>M</div><div class="stats-label">Outstanding</div></div></div>
            <div class="col-lg-3 col-6"><div class="stats-card warning"><div class="stats-number">TSH <?=number_format($total_overdue/1000000,1)?>M</div><div class="stats-label">Overdue Amount</div></div></div>
        </div>

        <!-- Legend -->
        <div class="legend-container">
            <div class="legend-item"><div class="legend-color paid"></div><span>Paid (Approved)</span></div>
            <div class="legend-item"><div class="legend-color partially"></div><span>Partially Paid</span></div>
            <div class="legend-item"><div class="legend-color overdue"></div><span>Overdue / Not Paid</span></div>
            <div class="legend-item"><div class="legend-color pending"></div><span>Pending</span></div>
            <div class="legend-item"><span class="dp-badge">DP</span><span>Down Payment</span></div>
        </div>

        <!-- Table -->
        <div class="card shadow-lg border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th>SN</th>
                                <th>Date</th>
                                <th>Name</th>
                                <th>Reservation</th>
                                <th>Inst Amount & Penalty</th>
                                <th>Inst Amount</th>
                                <th>Inst #</th>
                                <th>Penalty</th>
                                <th>Amount Paid</th>
                                <th>Outstanding</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $s): ?>
                            <tr class="<?=$s['row_class']?>">
                                <td class="text-center fw-bold"><?=$s['sn']?></td>
                                <td><?=date('d-m-Y', strtotime($s['due_date']))?></td>
                                <td>
                                    <div class="fw-bold"><?=htmlspecialchars($s['customer_name'])?></div>
                                    <small class="text-muted"><?=$s['phone']?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-primary"><?=$s['reservation_number']?></div>
                                    <small>Plot <?=$s['plot_number']?> - <?=$s['project_name']?></small>
                                </td>
                                <td class="text-end fw-bold"><?=number_format($s['installment_amount'] + $s['late_fee'])?></td>
                                <td class="text-end fw-bold"><?=number_format($s['installment_amount'])?></td>
                                <td class="text-center">
                                    <?php if ($s['is_downpayment']): ?>
                                        <span class="dp-badge">DP</span>
                                    <?php else: ?>
                                        <div class="installment-circle"><?=$s['installment_number']?></div>
                                        <small class="d-block text-muted">of <?=$s['payment_periods']?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-danger fw-bold"><?=number_format($s['late_fee'])?></td>
                                <td class="text-end text-success fw-bold"><?=number_format($s['paid_amount'])?></td>
                                <td class="text-end text-danger fw-bold"><?=number_format($s['balance'])?></td>
                                <td>
                                    <span class="status-badge <?=$s['badge']?>"><?=$s['status_text']?></span>
                                    <?php if ($s['days_overdue'] > 0 && !$s['is_downpayment']): ?>
                                        <div class="mt-1"><span class="badge bg-danger"><?=$s['days_overdue']?> days overdue</span></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>