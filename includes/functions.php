<?php
/**
 * Common Functions Helper File
 * Mkumbi Investments ERP System
 */

defined('APP_ACCESS') or die('Direct access not permitted');

/**
 * Generate unique reference numbers
 */
function generateReference($prefix, $conn, $company_id, $table, $column) {
    $year = date('Y');
    $sql = "SELECT MAX(CAST(SUBSTRING($column, LENGTH(?) + 1) AS UNSIGNED)) as max_num 
            FROM $table WHERE company_id = ? AND $column LIKE ?";
    $pattern = $prefix . $year . '%';
    $stmt = $conn->prepare($sql);
    $stmt->execute([$prefix . $year . '-', $company_id, $pattern]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ($result['max_num'] ?? 0) + 1;
    return $prefix . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

/**
 * Format currency for Tanzania Shillings
 */
function formatCurrency($amount, $symbol = 'TZS') {
    return $symbol . ' ' . number_format($amount, 2);
}

/**
 * Calculate business days between two dates
 */
function getBusinessDays($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $days = 0;
    
    while ($start <= $end) {
        $dayOfWeek = $start->format('N');
        if ($dayOfWeek < 6) { // Monday to Friday
            $days++;
        }
        $start->modify('+1 day');
    }
    
    return $days;
}

/**
 * Log audit trail
 */
function logAudit($conn, $company_id, $user_id, $action_type, $module_name, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
    $sql = "INSERT INTO audit_logs (company_id, user_id, action_type, module_name, table_name, record_id, old_values, new_values, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $company_id,
        $user_id,
        $action_type,
        $module_name,
        $table_name,
        $record_id,
        $old_values ? json_encode($old_values) : null,
        $new_values ? json_encode($new_values) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

/**
 * Check user permission/role
 */
function hasPermission($conn, $user_id, $required_roles) {
    if (!is_array($required_roles)) {
        $required_roles = [$required_roles];
    }
    
    // Super admin always has access
    $sql = "SELECT is_super_admin FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['is_super_admin']) {
        return true;
    }
    
    // Check role assignment
    $placeholders = implode(',', array_fill(0, count($required_roles), '?'));
    $sql = "SELECT COUNT(*) as has_role FROM user_roles ur 
            JOIN system_roles sr ON ur.role_id = sr.role_id 
            WHERE ur.user_id = ? AND sr.role_code IN ($placeholders)";
    $params = array_merge([$user_id], $required_roles);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['has_role'] > 0;
}

/**
 * Get employee details by user ID
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param int|null $company_id Optional company ID for multi-tenant security
 * @return array|false Employee data or false if not found
 */
function getEmployeeByUserId($conn, $user_id, $company_id = null) {
    $sql = "SELECT e.*, u.full_name, u.email, u.phone1, d.department_name, p.position_title
            FROM employees e
            JOIN users u ON e.user_id = u.user_id
            LEFT JOIN departments d ON e.department_id = d.department_id
            LEFT JOIN positions p ON e.position_id = p.position_id
            WHERE e.user_id = ? AND e.is_active = 1";
    $params = [$user_id];
    
    if ($company_id !== null) {
        $sql .= " AND e.company_id = ?";
        $params[] = $company_id;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Send notification (placeholder for email/SMS integration)
 */
function sendNotification($type, $recipient, $subject, $message, $data = []) {
    // Log notification for now - can be extended for email/SMS
    error_log("Notification [$type] to $recipient: $subject - $message");
    return true;
}

/**
 * Calculate PAYE Tax for Tanzania
 */
function calculatePAYE($grossSalary) {
    // Tanzania PAYE rates (2024/2025)
    if ($grossSalary <= 270000) {
        return 0;
    } elseif ($grossSalary <= 520000) {
        return ($grossSalary - 270000) * 0.08;
    } elseif ($grossSalary <= 760000) {
        return 20000 + ($grossSalary - 520000) * 0.20;
    } elseif ($grossSalary <= 1000000) {
        return 68000 + ($grossSalary - 760000) * 0.25;
    } else {
        return 128000 + ($grossSalary - 1000000) * 0.30;
    }
}

/**
 * Calculate NSSF contribution
 */
function calculateNSSF($grossSalary) {
    // Employee contribution: 10% (capped at TZS 10% of 2,000,000)
    $cap = 2000000;
    $rate = 0.10;
    $salary = min($grossSalary, $cap);
    return $salary * $rate;
}

/**
 * Calculate NHIF contribution
 */
function calculateNHIF($grossSalary) {
    // NHIF rates based on salary brackets
    if ($grossSalary <= 150000) return 5000;
    if ($grossSalary <= 250000) return 7500;
    if ($grossSalary <= 350000) return 10000;
    if ($grossSalary <= 450000) return 12500;
    if ($grossSalary <= 600000) return 15000;
    if ($grossSalary <= 800000) return 20000;
    if ($grossSalary <= 1000000) return 25000;
    if ($grossSalary <= 1500000) return 30000;
    return 40000;
}

/**
 * Sanitize input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate date format
 */
function isValidDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>',
        'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
        'active' => '<span class="badge bg-primary">Active</span>',
        'completed' => '<span class="badge bg-info">Completed</span>',
        'paid' => '<span class="badge bg-success">Paid</span>',
        'disbursed' => '<span class="badge bg-success">Disbursed</span>',
        'draft' => '<span class="badge bg-light text-dark">Draft</span>',
        'overdue' => '<span class="badge bg-danger">Overdue</span>',
    ];
    
    return $badges[strtolower($status)] ?? '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
}

/**
 * Calculate loan repayment schedule
 */
function calculateLoanSchedule($principal, $annualRate, $months, $startDate) {
    $schedule = [];
    $monthlyRate = ($annualRate / 100) / 12;
    
    if ($monthlyRate > 0) {
        $monthlyPayment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $months)) / (pow(1 + $monthlyRate, $months) - 1);
    } else {
        $monthlyPayment = $principal / $months;
    }
    
    $balance = $principal;
    $date = new DateTime($startDate);
    
    for ($i = 1; $i <= $months; $i++) {
        $date->modify('+1 month');
        $interest = $balance * $monthlyRate;
        $principalPart = $monthlyPayment - $interest;
        $balance -= $principalPart;
        
        if ($balance < 0) $balance = 0;
        
        $schedule[] = [
            'installment_number' => $i,
            'due_date' => $date->format('Y-m-d'),
            'principal_amount' => round($principalPart, 2),
            'interest_amount' => round($interest, 2),
            'total_amount' => round($monthlyPayment, 2),
            'balance_outstanding' => round($balance, 2)
        ];
    }
    
    return $schedule;
}

/**
 * Calculate depreciation (Straight Line)
 */
function calculateStraightLineDepreciation($cost, $salvageValue, $usefulLife) {
    return ($cost - $salvageValue) / $usefulLife;
}

/**
 * Calculate depreciation (Declining Balance)
 */
function calculateDecliningBalanceDepreciation($bookValue, $rate) {
    return $bookValue * ($rate / 100);
}
