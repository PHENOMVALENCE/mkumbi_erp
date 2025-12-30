<?php
defined('APP_ACCESS') or die('Direct access not permitted');

// Calculate the base path dynamically based on current location
$current_path = dirname($_SERVER['PHP_SELF']);
$depth = substr_count($current_path, '/') - 1; // -1 because root is already counted
$base_path = $depth > 0 ? str_repeat('../', $depth) : '';

// Get current page for active menu
$current_page = basename($_SERVER['PHP_SELF']);
$current_module = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            font-size: 14px; 
            line-height: 1.6; 
            color: #1f2937; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
            min-height: 100vh;
        }
        
        /* Enhanced Header */
        .main-header { 
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            z-index: 1030; 
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-bottom: none;
            height: 64px; 
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .navbar { padding: 0; height: 64px; }
        
        .navbar-brand { 
            padding: 0 20px; 
            font-size: 22px; 
            line-height: 64px; 
            color: #fff; 
            font-weight: 600;
            letter-spacing: -0.5px;
            transition: opacity 0.3s;
        }
        
        .navbar-brand:hover { opacity: 0.9; color: #fff; }
        .navbar-brand b { font-weight: 700; }
        
        .nav-link { 
            padding: 0 16px; 
            color: rgba(255,255,255,0.9); 
            height: 64px; 
            display: flex; 
            align-items: center;
            transition: all 0.3s;
            position: relative;
        }
        
        .nav-link:hover { 
            background: rgba(255,255,255,0.1); 
            color: #fff;
        }
        
        .nav-link i { font-size: 18px; }
        
        /* Enhanced Sidebar */
        .main-sidebar { 
            position: fixed; 
            top: 64px; 
            bottom: 0; 
            left: 0; 
            width: 260px; 
            background: #1f2937;
            overflow-y: auto; 
            z-index: 1020; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 2px 0 8px rgba(0,0,0,0.1);
        }
        
        .sidebar { padding: 16px 0; }
        
        .nav-sidebar .nav-link { 
            color: #9ca3af; 
            padding: 12px 20px; 
            display: flex; 
            align-items: center; 
            border-left: 3px solid transparent;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 14px;
        }
        
        .nav-sidebar .nav-link:hover { 
            background: rgba(59, 130, 246, 0.1); 
            color: #3b82f6;
            border-left-color: #3b82f6;
            padding-left: 24px;
        }
        
        .nav-sidebar .nav-link.active { 
            color: #fff; 
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0.05) 100%);
            border-left-color: #3b82f6;
            font-weight: 600;
        }
        
        .nav-icon { 
            width: 24px; 
            text-align: center; 
            margin-right: 12px; 
            font-size: 16px;
        }
        
        .nav-treeview { 
            list-style: none; 
            padding: 0; 
            margin: 0; 
            display: none; 
            background: #111827;
        }
        
        .nav-treeview .nav-link { 
            padding-left: 56px; 
            font-size: 13px;
            font-weight: 400;
        }
        
        .nav-treeview .nav-link:hover {
            padding-left: 60px;
        }
        
        .nav-item.menu-open > .nav-treeview { 
            display: block;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .nav-link[data-toggle="dropdown"]::after { 
            margin-left: auto; 
            content: "\f105"; 
            font-family: "Font Awesome 6 Free"; 
            font-weight: 900; 
            transition: transform 0.3s;
            font-size: 12px;
        }
        
        .nav-item.menu-open > .nav-link[data-toggle="dropdown"]::after { 
            transform: rotate(90deg); 
        }
        
        /* Content Wrapper */
        .content-wrapper { 
            margin-left: 260px; 
            margin-top: 64px; 
            min-height: calc(100vh - 64px); 
            padding: 24px; 
            background: transparent;
            transition: margin-left 0.3s;
        }
        
        /* Enhanced Badges */
        .badge { 
            font-size: 10px; 
            padding: 4px 8px; 
            margin-left: auto;
            border-radius: 12px;
            font-weight: 600;
        }
        
        /* Enhanced Dropdown */
        .dropdown-menu { 
            border: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border-radius: 8px;
            padding: 8px;
            margin-top: 8px;
        }
        
        .dropdown-item { 
            padding: 10px 16px;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .dropdown-item:hover { 
            background: #f3f4f6;
            color: #1f2937;
        }
        
        .dropdown-item i { 
            width: 20px;
            text-align: center;
        }
        
        .dropdown-divider { 
            margin: 8px 0;
            opacity: 0.1;
        }
        
        /* Menu Section Headers */
        .nav-header {
            padding: 16px 20px 8px;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Responsive */
        @media (max-width: 991.98px) {
            .main-sidebar { 
                margin-left: -260px; 
            }
            .main-sidebar.sidebar-open { 
                margin-left: 0;
                box-shadow: 4px 0 12px rgba(0,0,0,0.15);
            }
            .content-wrapper { 
                margin-left: 0; 
            }
        }
        
        /* Scrollbar Styling */
        .main-sidebar::-webkit-scrollbar { 
            width: 6px; 
        }
        
        .main-sidebar::-webkit-scrollbar-track {
            background: #111827;
        }
        
        .main-sidebar::-webkit-scrollbar-thumb { 
            background: #374151;
            border-radius: 3px;
        }
        
        .main-sidebar::-webkit-scrollbar-thumb:hover {
            background: #4b5563;
        }
        
        /* Notification Badge Animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .nav-link .badge {
            animation: pulse 2s infinite;
        }
        
        /* User Avatar */
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            font-weight: 600;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">

    <!-- Enhanced Navbar -->
    <nav class="main-header navbar navbar-expand">
        <ul class="navbar-nav">
            <li class="nav-item d-lg-none">
                <a class="nav-link" href="#" onclick="document.getElementById('mainSidebar').classList.toggle('sidebar-open')">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item">
                <a href="<?php echo $base_path; ?>index.php" class="navbar-brand">
                    <i class="fas fa-building me-2"></i><b>ERP</b> System
                </a>
            </li>
        </ul>
        <ul class="navbar-nav ms-auto">
            <!-- Quick Actions Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" title="Quick Actions">
                    <i class="fas fa-bolt"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 220px;">
                    <h6 class="dropdown-header">Quick Actions</h6>
                    <a href="<?php echo $base_path; ?>modules/sales/create.php" class="dropdown-item">
                        <i class="fas fa-plus-circle me-2 text-success"></i> New Reservation
                    </a>
                    <a href="<?php echo $base_path; ?>modules/expenses/create_claim.php" class="dropdown-item">
                        <i class="fas fa-receipt me-2 text-danger"></i> Submit Expense
                    </a>
                    <a href="<?php echo $base_path; ?>modules/payments/record.php" class="dropdown-item">
                        <i class="fas fa-money-bill-wave me-2 text-info"></i> Record Payment
                    </a>
                    <a href="<?php echo $base_path; ?>modules/petty_cash/disbursement.php" class="dropdown-item">
                        <i class="fas fa-cash-register me-2 text-warning"></i> Petty Cash Out
                    </a>
                </div>
            </li>
            
            <!-- Messages Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" title="Messages">
                    <i class="far fa-envelope"></i>
                    <span class="badge bg-danger">3</span>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 280px;">
                    <h6 class="dropdown-header">New Messages</h6>
                    <a href="#" class="dropdown-item">
                        <div class="d-flex align-items-center">
                            <div class="user-avatar bg-primary text-white">JD</div>
                            <div class="flex-grow-1">
                                <strong>John Doe</strong>
                                <p class="mb-0 small text-muted">Payment inquiry...</p>
                            </div>
                        </div>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item text-center small">View All Messages</a>
                </div>
            </li>
            
            <!-- Notifications Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" title="Notifications">
                    <i class="far fa-bell"></i>
                    <span class="badge bg-warning">8</span>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 320px;">
                    <h6 class="dropdown-header">Notifications</h6>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-check-circle me-2 text-success"></i> New payment received
                        <small class="float-end text-muted">5m ago</small>
                    </a>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-exclamation-triangle me-2 text-warning"></i> 3 expenses pending approval
                        <small class="float-end text-muted">1h ago</small>
                    </a>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-hand-holding-usd me-2 text-info"></i> Loan application received
                        <small class="float-end text-muted">2h ago</small>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item text-center small">View All Notifications</a>
                </div>
            </li>
            
            <!-- Tasks Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" title="Tasks">
                    <i class="fas fa-tasks"></i>
                    <span class="badge bg-info">6</span>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 280px;">
                    <h6 class="dropdown-header">Pending Tasks</h6>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-file-signature me-2 text-primary"></i> Review contract #1234
                    </a>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-check-double me-2 text-success"></i> Approve 2 expense claims
                    </a>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-cash-register me-2 text-warning"></i> Petty cash reconciliation
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item text-center small">View All Tasks</a>
                </div>
            </li>
            
            <!-- User Profile Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" title="Profile">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <span class="d-none d-md-inline"><?php echo $_SESSION['full_name'] ?? 'User'; ?></span>
                    <i class="fas fa-chevron-down ms-2" style="font-size: 10px;"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 220px;">
                    <div class="dropdown-header">
                        <strong><?php echo $_SESSION['full_name'] ?? 'User'; ?></strong>
                        <p class="mb-0 small text-muted"><?php echo $_SESSION['email'] ?? 'user@example.com'; ?></p>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo $base_path; ?>modules/settings/profile.php" class="dropdown-item">
                        <i class="fas fa-user me-2"></i> My Profile
                    </a>
                    <a href="<?php echo $base_path; ?>modules/settings/index.php" class="dropdown-item">
                        <i class="fas fa-cog me-2"></i> Settings
                    </a>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-question-circle me-2"></i> Help & Support
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo $base_path; ?>logout.php" class="dropdown-item text-danger">
                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                    </a>
                </div>
            </li>
        </ul>
    </nav>

    <!-- Enhanced Sidebar -->
    <aside class="main-sidebar" id="mainSidebar">
        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-sidebar flex-column">

                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a href="<?php echo $base_path; ?>index.php" class="nav-link <?php echo ($current_page == 'index.php' && $current_module != 'modules') ? 'active' : ''; ?>">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>

                    <!-- MAIN MODULES -->
                    <li class="nav-header">CORE MODULES</li>

                    <!-- Projects & Plots -->
                    <li class="nav-item <?php echo in_array($current_module, ['plots','projects']) ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-map-marked-alt"></i>
                            <span>Projects & Plots</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/projects/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Projects</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/projects/create.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Add Project</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/projects/costs.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Project Costs</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/plots/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Plots</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/plots/create.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Add Plot</span></a></li>
                        </ul>
                    </li>

                    <!-- Marketing & Leads -->
                    <li class="nav-item <?php echo ($current_module == 'marketing') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <span>Marketing & Leads</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/leads.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Leads</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/create-lead.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Add Lead</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/campaigns.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Campaigns</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/quotations.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Sales Quotations</span></a></li>
                        </ul>
                    </li>

                    <!-- Customers -->
                    <li class="nav-item <?php echo ($current_module == 'customers') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-users"></i>
                            <span>Customers</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/customers/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Customers</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/customers/create.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Add Customer</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/customers/debtors.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Debtors / AR</span></a></li>
                        </ul>
                    </li>

                    <!-- Sales & Reservations -->
                    <li class="nav-item <?php echo ($current_module == 'sales') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-file-contract"></i>
                            <span>Sales & Reservations</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sales/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Reservations</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sales/create.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>New Reservation</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sales/contracts.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Contracts</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sales/cancellations.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Cancellations</span></a></li>
                        </ul>
                    </li>

                    <!-- Land Services -->
                    <li class="nav-item <?php echo ($current_module == 'services') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-tools"></i>
                            <span>Land Services</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/services/types.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Service Catalog</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/services/requests.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Service Requests</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/services/create.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>New Service Request</span></a></li>
                        </ul>
                    </li>

                    <!-- FINANCIAL SECTION -->
                    <li class="nav-header">FINANCIAL MANAGEMENT</li>

                    <!-- Payments -->
                    <li class="nav-item <?php echo ($current_module == 'payments') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-money-bill-wave"></i>
                            <span>Payments</span>
                            <span class="badge bg-success">5</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Payments</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/record.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Record Payment</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/schedule.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Payment Schedules</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/refunds.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Refunds</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/vouchers.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Vouchers & Receipts</span></a></li>
                        </ul>
                    </li>

                    <!-- Expenses -->
                    <li class="nav-item <?php echo ($current_module == 'expenses') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-receipt"></i>
                            <span>Expenses</span>
                            <span class="badge bg-danger">8</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/claims.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Expense Claims</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/create_claim.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Submit Claim</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/direct.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Direct Expenses</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/categories.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Expense Categories</span></a></li>
                        </ul>
                    </li>

                    <!-- Petty Cash -->
                    <li class="nav-item <?php echo ($current_module == 'petty_cash') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-cash-register"></i>
                            <span>Petty Cash</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/petty_cash/accounts.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Petty Cash Accounts</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/petty_cash/transactions.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Transactions</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/petty_cash/disbursement.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>New Disbursement</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/petty_cash/replenishment.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Request Replenishment</span></a></li>
                        </ul>
                    </li>

                    <!-- Assets -->
                    <li class="nav-item <?php echo ($current_module == 'assets') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-laptop"></i>
                            <span>Fixed Assets</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/register.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Asset Register</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/add.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Add Asset</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/categories.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Asset Categories</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/depreciation.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Depreciation</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/maintenance.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Maintenance</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/disposal.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Asset Disposal</span></a></li>
                        </ul>
                    </li>

                    <!-- Loans -->
                    <li class="nav-item <?php echo ($current_module == 'loans') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-hand-holding-usd"></i>
                            <span>Loans</span>
                            <span class="badge bg-warning">4</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/employee_loans.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Employee Loans</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/apply.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Apply for Loan</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/repayments.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Repayment Schedule</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/company_loans.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Company Loans</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/types.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Loan Types</span></a></li>
                        </ul>
                    </li>

                    <!-- Commissions -->
                    <li class="nav-item <?php echo ($current_module == 'commissions') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-percent"></i>
                            <span>Commissions</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/commissions/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Commissions</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/commissions/structures.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Commission Structures</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/commissions/pay.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Pay Commissions</span></a></li>
                        </ul>
                    </li>

                    <!-- Accounting -->
                    <li class="nav-item <?php echo ($current_module == 'accounting') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-book"></i>
                            <span>Accounting</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/accounting/accounts.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Chart of Accounts</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/accounting/accounts_comprehensive.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Accounts by Level</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/accounting/journal.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Journal Entries</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/accounting/ledger.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>General Ledger</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/accounting/trial.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Trial Balance</span></a></li>
                        </ul>
                    </li>

                    <!-- Finance & Banking -->
                    <li class="nav-item <?php echo in_array($current_module, ['finance','bank']) ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-university"></i>
                            <span>Finance & Banking</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/finance/bank_accounts.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Bank Accounts</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/finance/bank_reconciliation.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Bank Reconciliation</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/finance/budgets.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Budgets</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/finance/creditors.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Creditors / AP</span></a></li>
                        </ul>
                    </li>

                    <!-- Tax Management -->
                    <li class="nav-item <?php echo ($current_module == 'tax') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                            <span>Tax Management</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tax/types.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Tax Types</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tax/transactions.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Tax Transactions</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tax/reports.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Tax Reports</span></a></li>
                        </ul>
                    </li>

                    <!-- OPERATIONS SECTION -->
                    <li class="nav-header">OPERATIONS</li>

                    <!-- Procurement -->
                    <li class="nav-item <?php echo ($current_module == 'procurement') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-shopping-cart"></i>
                            <span>Procurement</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/procurement/requisitions.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Purchase Requisitions</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/procurement/orders.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Purchase Orders</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/procurement/suppliers.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Suppliers</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/procurement/contracts.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Supplier Contracts</span></a></li>
                        </ul>
                    </li>

                    <!-- Inventory -->
                    <li class="nav-item <?php echo ($current_module == 'inventory') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-warehouse"></i>
                            <span>Inventory</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/inventory/stores.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Store Locations</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/inventory/stock.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Stock Levels</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/inventory/movements.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Stock Movements</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/inventory/audit.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Stock Audit</span></a></li>
                        </ul>
                    </li>

                    <!-- Human Resources -->
                    <li class="nav-item <?php echo ($current_module == 'hr') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <span>Human Resources</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/employees.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Employees</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/attendance.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Attendance</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/leave.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Management</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/payroll.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Payroll</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/recruitment.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Recruitment</span></a></li>
                        </ul>
                    </li>

                    <!-- WORKFLOW SECTION -->
                    <li class="nav-header">WORKFLOW</li>

                    <!-- Approvals -->
                    <li class="nav-item <?php echo ($current_module == 'approvals') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-check-double"></i>
                            <span>Approvals</span>
                            <span class="badge bg-warning">15</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/approvals/pending.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Pending Approvals</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/approvals/workflows.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Workflows</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/approvals/history.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Approval History</span></a></li>
                        </ul>
                    </li>

                    <!-- Documents -->
                    <li class="nav-item <?php echo ($current_module == 'documents') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-folder-open"></i>
                            <span>Documents</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/documents/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Documents</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/documents/templates.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Templates</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/documents/shared.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Shared Documents</span></a></li>
                        </ul>
                    </li>

                    <!-- ANALYTICS SECTION -->
                    <li class="nav-header">ANALYTICS</li>

                    <!-- Reports -->
                    <li class="nav-item <?php echo ($current_module == 'reports') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <span>Reports</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/sales.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Sales Reports</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/financial.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Financial Reports</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/customers.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Customer Reports</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/hr.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>HR Reports</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/inventory.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Inventory Reports</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/custom.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Custom Reports</span></a></li>
                        </ul>
                    </li>

                    <!-- Analytics Dashboard -->
                    <li class="nav-item <?php echo ($current_module == 'analytics') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <span>Analytics</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/analytics/overview.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Overview</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/analytics/performance.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Performance Metrics</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/analytics/forecasting.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Forecasting</span></a></li>
                        </ul>
                    </li>

                    <!-- SYSTEM SECTION -->
                    <li class="nav-header">SYSTEM</li>

                    <!-- Settings -->
                    <li class="nav-item <?php echo ($current_module == 'settings') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/settings/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>General Settings</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/settings/users.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>User Management</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/settings/company.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Company Profile</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/settings/roles.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Roles & Permissions</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/settings/integrations.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Integrations</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/settings/backup.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Backup & Restore</span></a></li>
                        </ul>
                    </li>

                    <!-- Audit Trail -->
                    <li class="nav-item">
                        <a href="<?php echo $base_path; ?>modules/audit/index.php" class="nav-link">
                            <i class="nav-icon fas fa-history"></i>
                            <span>Audit Trail</span>
                        </a>
                    </li>

                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Your page content goes here -->