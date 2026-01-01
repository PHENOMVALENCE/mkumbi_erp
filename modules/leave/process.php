<?php
/**
 * Leave Request Processing Handler
 * Mkumbi Investments ERP System - Approval/Rejection/Cancellation Operations
 */

define('APP_ACCESS', true);
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

// Get parameters
$leave_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['leave_id']) ? (int)$_POST['leave_id'] : 0);
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

if (!$leave_id || !in_array($action, ['approve', 'reject', 'cancel'])) {
    $_SESSION['error_message'] = "Invalid request.";
    header('Location: index.php');
    exit;
}

// Fetch leave application with comprehensive data
$sql = "SELECT la.*, e.user_id as employee_user_id, e.employee_id, u.full_name as employee_name, u.email as employee_email
        FROM leave_applications la
        JOIN employees e ON la.employee_id = e.employee_id
        JOIN users u ON e.user_id = u.user_id
        WHERE la.leave_id = ? AND la.company_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$leave_id, $company_id]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    $_SESSION['error_message'] = "Leave application not found.";
    header('Location: index.php');
    exit;
}

// Check permissions
$employee_data = getEmployeeByUserId($conn, $user_id, $company_id);
$is_owner = ($employee_data['employee_id'] ?? 0) == $leave['employee_id'];
$is_admin = isAdmin($conn, $user_id);
$is_management = isManagement($conn, $user_id);

// Check if the leave applicant is an admin
$applicant_is_admin = isAdmin($conn, $leave['employee_user_id']);

// Validate action permissions
if ($action === 'cancel') {
    if (!$is_owner || $leave['status'] !== 'pending') {
        $_SESSION['error_message'] = "You cannot cancel this leave application.";
        header('Location: my-leaves.php');
        exit;
    }
} else {
    // Access control: Admin approves employee leaves, Management approves admin and super admin leaves
    if ($applicant_is_admin) {
        // Admin or Super Admin leave - only management can approve/reject
        if (!$is_management) {
            $_SESSION['error_message'] = "You don't have permission to approve/reject admin or super admin leave requests. Only management can approve these leaves.";
            header('Location: index.php');
            exit;
        }
    } else {
        // Employee leave - only admin can approve/reject
        if (!$is_admin) {
            $_SESSION['error_message'] = "You don't have permission to approve/reject employee leave requests. Only admin can approve employee leaves.";
            header('Location: index.php');
            exit;
        }
    }
    
    if ($leave['status'] !== 'pending') {
        $_SESSION['error_message'] = "This leave application has already been processed.";
        header('Location: approvals.php');
        exit;
    }
}

try {
    $conn->beginTransaction();
    
    $old_values = [
        'status' => $leave['status'],
        'approved_by' => $leave['approved_by'],
        'approved_at' => $leave['approved_at']
    ];
    
    switch ($action) {
        case 'approve':
            $update_sql = "UPDATE leave_applications 
                           SET status = 'approved', approved_by = ?, approved_at = NOW()
                           WHERE leave_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([$user_id, $leave_id]);
            
            $new_status = 'approved';
            $message = "Leave request approved successfully.";
            
            // Send notification to employee
            sendNotification('email', $leave['employee_email'], 
                'Leave Request Approved',
                "Your leave request from {$leave['start_date']} to {$leave['end_date']} has been approved.",
                ['leave_id' => $leave_id]
            );
            break;
            
        case 'reject':
            $rejection_reason = sanitize($_POST['rejection_reason'] ?? '');
            
            if (empty($rejection_reason)) {
                throw new Exception("Rejection reason is required.");
            }
            
            $update_sql = "UPDATE leave_applications 
                           SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
                           WHERE leave_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([$user_id, $rejection_reason, $leave_id]);
            
            $new_status = 'rejected';
            $message = "Leave request rejected.";
            
            // Send notification to employee
            sendNotification('email', $leave['employee_email'],
                'Leave Request Rejected',
                "Your leave request from {$leave['start_date']} to {$leave['end_date']} has been rejected. Reason: $rejection_reason",
                ['leave_id' => $leave_id]
            );
            break;
            
        case 'cancel':
            $update_sql = "UPDATE leave_applications SET status = 'cancelled' WHERE leave_id = ? AND company_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([$leave_id, $company_id]);
            
            $new_status = 'cancelled';
            $message = "Leave application cancelled successfully.";
            break;
    }
    
    // Log audit
    logAudit($conn, $company_id, $user_id, $action === 'cancel' ? 'cancel' : 'update', 'leave', 'leave_applications', $leave_id, $old_values, [
        'status' => $new_status,
        'approved_by' => $action !== 'cancel' ? $user_id : null,
        'action' => $action
    ]);
    
    $conn->commit();
    $_SESSION['success_message'] = $message;
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Leave process error: " . $e->getMessage());
    $_SESSION['error_message'] = $e->getMessage();
}

// Redirect based on action
if ($action === 'cancel') {
    header('Location: my-leaves.php');
} else {
    header('Location: approvals.php');
}
exit;
