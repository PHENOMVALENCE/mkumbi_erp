<?php
/**
 * Complete Functions Library
 * Mkumbi Investments ERP System
 * 
 * Add these functions to your includes/functions.php file
 * CRITICAL: Includes null-safe formatCurrency() and all loan/leave module requirements
 */

// ============================================================================
// CRITICAL FUNCTION #1: formatCurrency() - NULL SAFE VERSION
// ============================================================================
/**
 * Format currency for display - HANDLES NULL VALUES
 * @param mixed $amount Amount to format (can be null)
 * @param string $symbol Currency symbol (default: TSH)
 * @param int $decimals Number of decimal places (default: 2)
 * @return string Formatted currency string
 */
function formatCurrency($amount = 0, $symbol = 'TSH', $decimals = 2) {
    // Handle null or empty values
    if ($amount === null || $amount === '' || !is_numeric($amount)) {
        $amount = 0;
    }
    
    $amount = (float)$amount;
    return $symbol . ' ' . number_format($amount, $decimals);
}

// ============================================================================
// CRITICAL FUNCTION #2: getOrCreateEmployeeForSuperAdmin()
// ============================================================================
/**
 * Get or create employee record for super admin
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param int $company_id Company ID
 * @return array|false Employee data or false if not found and not super admin
 */
function getOrCreateEmployeeForSuperAdmin($conn, $user_id, $company_id) {
    $employee = getEmployeeByUserId($conn, $user_id, $company_id);
    
    if ($employee) {
        return $employee;
    }
    
    // Check if user is super admin
    $sql = "SELECT is_super_admin FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !$user['is_super_admin']) {
        return false;
    }
    
    // Create a minimal employee record for super admin
    try {
        $conn->beginTransaction();
        
        // Check if employee number already exists
        $employee_number = 'SA-' . str_pad($user_id, 6, '0', STR_PAD_LEFT);
        $check_sql = "SELECT COUNT(*) as count FROM employees WHERE employee_number = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$employee_number]);
        $exists = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($exists > 0) {
            $employee_number = 'SA-' . str_pad($user_id, 6, '0', STR_PAD_LEFT) . '-' . time();
        }
        
        // Create employee record for super admin
        $insert_employee_sql = "INSERT INTO employees 
            (company_id, user_id, employee_number, hire_date, employment_status, is_active, created_at)
            VALUES (?, ?, ?, CURDATE(), 'active', 1, NOW())";
        
        $insert_stmt = $conn->prepare($insert_employee_sql);
        $insert_stmt->execute([$company_id, $user_id, $employee_number]);
        
        $conn->commit();
        
        return getEmployeeByUserId($conn, $user_id, $company_id);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error creating employee record for super admin: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error creating employee record for super admin: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// FUNCTION #3: getEmployeeByUserId()
// ============================================================================
/**
 * Get employee details by user ID
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param int|null $company_id Company ID (optional)
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

// ============================================================================
// FUNCTION #4: isAdmin()
// ============================================================================
/**
 * Check if user is an admin
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return bool True if user is admin
 */
function isAdmin($conn, $user_id) {
    return hasPermission($conn, $user_id, ['COMPANY_ADMIN', 'SUPER_ADMIN']);
}

// ============================================================================
// FUNCTION #5: isManagement()
// ============================================================================
/**
 * Check if user is management
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return bool True if user is management
 */
function isManagement($conn, $user_id) {
    return hasPermission($conn, $user_id, ['MANAGER', 'SUPER_ADMIN']);
}

// ============================================================================
// FUNCTION #6: hasPermission()
// ============================================================================
/**
 * Check user permission/role
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param array|string $required_roles Required role(s)
 * @return bool True if user has required role
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
    
    return ($result['has_role'] ?? 0) > 0;
}

// ============================================================================
// FUNCTION #7: sanitize()
// ============================================================================
/**
 * Sanitize input
 * @param mixed $input Input value (string or array)
 * @return mixed Sanitized value
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ============================================================================
// FUNCTION #8: logAudit()
// ============================================================================
/**
 * Log audit trail
 * @param PDO $conn Database connection
 * @param int $company_id Company ID
 * @param int $user_id User ID
 * @param string $action_type Type of action (create, update, delete, approve, reject)
 * @param string $module_name Module name (leave, loan)
 * @param string|null $table_name Table name
 * @param int|null $record_id Record ID
 * @param array|null $old_values Old values
 * @param array|null $new_values New values
 * @return bool Success
 */
function logAudit($conn, $company_id, $user_id, $action_type, $module_name, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
    try {
        $sql = "INSERT INTO audit_logs (company_id, user_id, action_type, module_name, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        return $stmt->execute([
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
    } catch (Exception $e) {
        error_log("Error logging audit: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// FUNCTION #9: getStatusBadge()
// ============================================================================
/**
 * Get status badge HTML
 * @param string $status Status value
 * @return string HTML badge
 */
function getStatusBadge($status) {
    $status = strtolower($status ?? '');
    
    $badges = [
        'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>',
        'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
        'disbursed' => '<span class="badge bg-info">Disbursed</span>',
        'active' => '<span class="badge bg-primary">Active</span>',
        'completed' => '<span class="badge bg-success">Completed</span>',
        'draft' => '<span class="badge bg-light text-dark">Draft</span>',
    ];
    
    return $badges[$status] ?? '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
}

// ============================================================================
// FUNCTION #10: calculateLoanSchedule()
// ============================================================================
/**
 * Calculate loan repayment schedule
 * @param float $principal Principal amount
 * @param float $annualRate Annual interest rate (percentage)
 * @param int $months Repayment period in months
 * @param string $startDate Start date (YYYY-MM-DD)
 * @return array Array of payment schedules
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

// ============================================================================
// FUNCTION #11: sendNotification()
// ============================================================================
/**
 * Send notification (placeholder for email/SMS integration)
 * @param string $type Type of notification (email, sms)
 * @param string $recipient Recipient email/phone
 * @param string $subject Subject/Title
 * @param string $message Message content
 * @param array $data Additional data
 * @return bool Success
 */
function sendNotification($type, $recipient, $subject, $message, $data = []) {
    try {
        // Log notification for now - can be extended for email/SMS
        error_log("Notification [$type] to $recipient: $subject - $message");
        
        // TODO: Implement actual email/SMS sending
        // For now, just return true
        return true;
    } catch (Exception $e) {
        error_log("Error sending notification: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// ADDITIONAL HELPER FUNCTIONS (Optional but useful)
// ============================================================================

/**
 * Get business days between two dates
 * @param string $start_date Start date (YYYY-MM-DD)
 * @param string $end_date End date (YYYY-MM-DD)
 * @return int Number of business days
 */
function getBusinessDays($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day');
    
    $days = 0;
    while ($start < $end) {
        $dow = $start->format('w');
        if ($dow > 0 && $dow < 6) {
            $days++;
        }
        $start->modify('+1 day');
    }
    
    return $days;
}

/**
 * Generate unique reference number
 * @param string $prefix Prefix for the number
 * @return string Unique reference number
 */
function generateReferenceNumber($prefix = 'REF') {
    return $prefix . date('YmdHis') . rand(1000, 9999);
}

/**
 * Convert value to boolean safely
 * @param mixed $value Value to convert
 * @return bool Boolean value
 */
function toBool($value) {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

// ============================================================================
// END OF FUNCTIONS
// ============================================================================
?>
