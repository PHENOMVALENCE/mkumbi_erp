<?php
// modules/projects/delete.php
// FORCE DELETE — FIXED VERSION (handles missing tables gracefully)

define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid project ID.";
    header("Location: index.php");
    exit();
}

$project_id = (int)$_GET['id'];
$company_id = $_SESSION['company_id'];

$db = Database::getInstance();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // Get project name for message
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        throw new Exception("Project not found or access denied.");
    }
    $project_name = $project['project_name'];

    // === FORCE DELETE EVERYTHING (with error handling for missing tables) ===

    // 1. Service requests (if table exists)
    try {
        $conn->prepare("DELETE FROM service_requests WHERE project_id = ?")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'service_requests' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 2. Commissions
    try {
        $conn->prepare("
            DELETE c FROM commissions c
            INNER JOIN reservations r ON c.reservation_id = r.reservation_id
            INNER JOIN plots p ON r.plot_id = p.plot_id
            WHERE p.project_id = ?
        ")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'commissions' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 3. Payment approvals
    try {
        $conn->prepare("
            DELETE pa FROM payment_approvals pa
            INNER JOIN payments p ON pa.payment_id = p.payment_id
            INNER JOIN reservations r ON p.reservation_id = r.reservation_id
            INNER JOIN plots pl ON r.plot_id = pl.plot_id
            WHERE pl.project_id = ?
        ")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'payment_approvals' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 4. Payments
    try {
        $conn->prepare("
            DELETE p FROM payments p
            INNER JOIN reservations r ON p.reservation_id = r.reservation_id
            INNER JOIN plots pl ON r.plot_id = pl.plot_id
            WHERE pl.project_id = ?
        ")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'payments' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 5. Payment schedules
    try {
        $conn->prepare("
            DELETE ps FROM payment_schedules ps
            INNER JOIN reservations r ON ps.reservation_id = r.reservation_id
            INNER JOIN plots pl ON r.plot_id = pl.plot_id
            WHERE pl.project_id = ?
        ")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'payment_schedules' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 6. Refunds
    try {
        $conn->prepare("
            DELETE rf FROM refunds rf
            INNER JOIN reservations r ON rf.reservation_id = r.reservation_id
            INNER JOIN plots pl ON r.plot_id = pl.plot_id
            WHERE pl.project_id = ?
        ")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'refunds' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 7. Reservation cancellations
    try {
        $conn->prepare("
            DELETE rc FROM reservation_cancellations rc
            INNER JOIN reservations r ON rc.reservation_id = r.reservation_id
            INNER JOIN plots pl ON r.plot_id = pl.plot_id
            WHERE pl.project_id = ?
        ")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'reservation_cancellations' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 8. Reservations
    $conn->prepare("DELETE FROM reservations WHERE plot_id IN (SELECT plot_id FROM plots WHERE project_id = ?)")->execute([$project_id]);

    // 9. Project costs
    $conn->prepare("DELETE FROM project_costs WHERE project_id = ?")->execute([$project_id]);

    // 10. Project sellers
    try {
        $conn->prepare("DELETE FROM project_sellers WHERE project_id = ?")->execute([$project_id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Table 'project_sellers' doesn't exist") !== false) {
            // Table doesn't exist, skip
        } else {
            throw $e;
        }
    }

    // 11. Delete all plots
    $conn->prepare("DELETE FROM plots WHERE project_id = ?")->execute([$project_id]);

    // 12. Finally delete the project
    $conn->prepare("DELETE FROM projects WHERE project_id = ?")->execute([$project_id]);

    $conn->commit();

    $_SESSION['success'] = "Project <strong>\"$project_name\"</strong> and <u>ALL its data</u> (plots, reservations, payments, commissions, refunds, etc.) have been <strong>PERMANENTLY DELETED</strong>.";

} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['error'] = "Force delete failed: " . $e->getMessage();
} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Force delete error (Project ID: $project_id): " . $e->getMessage());
    $_SESSION['error'] = "Database error during force delete: " . $e->getMessage();
}

header("Location: index.php");
exit();