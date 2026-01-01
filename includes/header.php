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
    
    <!-- Favicon -->
    <link rel="icon" type="image/jpeg" href="<?php echo $base_path; ?>assets/img/logo.jpg">
    
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
            font-size: 13px; 
            line-height: 1.5; 
            color: #374151; 
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .wrapper {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        /* Professional Header */
        .main-header { 
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            z-index: 1030; 
            background: #1e3a8a;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            height: 65px; 
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .navbar { 
            padding: 0; 
            height: 65px;
        }
        
        .navbar-brand { 
            padding: 0 24px; 
            font-size: 18px; 
            line-height: 65px; 
            color: #fff; 
            font-weight: 600;
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .navbar-brand:hover { 
            color: #fff;
            text-decoration: none;
        }
        
        .brand-logo {
            width: 45px;
            height: 45px;
            border-radius: 4px;
            object-fit: cover;
            background: #fff;
            padding: 3px;
        }
        
        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        
        .brand-text-main {
            font-size: 16px;
            font-weight: 600;
            color: #fff;
            letter-spacing: 0.3px;
        }
        
        .brand-text-sub {
            font-size: 10px;
            font-weight: 400;
            color: rgba(255,255,255,0.75);
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        .nav-link { 
            padding: 0 16px; 
            color: rgba(255,255,255,0.9); 
            height: 65px; 
            display: flex; 
            align-items: center;
            position: relative;
            text-decoration: none;
            font-size: 13px;
        }
        
        .nav-link:hover { 
            background: rgba(255,255,255,0.08); 
            color: #fff;
        }
        
        .nav-link i { 
            font-size: 17px;
        }
        
        /* Professional Sidebar */
        .main-sidebar { 
            position: fixed; 
            top: 65px; 
            bottom: 50px;
            left: 0; 
            width: 250px; 
            background: #1f2937;
            overflow-y: auto; 
            z-index: 1020;
            box-shadow: 2px 0 4px rgba(0,0,0,0.08);
        }
        
        .sidebar { padding: 12px 0; }
        
        .nav-sidebar .nav-link { 
            color: #9ca3af; 
            padding: 10px 18px; 
            display: flex; 
            align-items: center; 
            border-left: 3px solid transparent;
            font-weight: 500;
            font-size: 13px;
        }
        
        .nav-sidebar .nav-link:hover { 
            background: rgba(59, 130, 246, 0.08); 
            color: #3b82f6;
            border-left-color: #3b82f6;
            padding-left: 22px;
        }
        
        .nav-sidebar .nav-link.active { 
            color: #fff; 
            background: rgba(59, 130, 246, 0.15);
            border-left-color: #3b82f6;
            font-weight: 600;
        }
        
        .nav-icon { 
            width: 22px; 
            text-align: center; 
            margin-right: 10px; 
            font-size: 15px;
        }
        
        .nav-treeview { 
            list-style: none; 
            padding: 0; 
            margin: 0; 
            display: none; 
            background: #111827;
        }
        
        .nav-treeview .nav-link { 
            padding-left: 50px; 
            font-size: 12px;
            font-weight: 400;
        }
        
        .nav-treeview .nav-link:hover {
            padding-left: 54px;
        }
        
        .nav-item.menu-open > .nav-treeview { 
            display: block;
        }
        
        .nav-link[data-toggle="dropdown"]::after { 
            margin-left: auto; 
            content: "\f105"; 
            font-family: "Font Awesome 6 Free"; 
            font-weight: 900; 
            font-size: 11px;
        }
        
        .nav-item.menu-open > .nav-link[data-toggle="dropdown"]::after { 
            transform: rotate(90deg); 
        }
        
        /* Content Wrapper */
        .content-wrapper { 
            flex: 1;
            margin-left: 250px; 
            margin-top: 65px; 
            margin-bottom: 50px;
            min-height: calc(100vh - 115px); 
            padding: 20px; 
            background: #f3f4f6;
        }
        
        /* Professional Footer */
        .main-footer {
            position: fixed;
            bottom: 0;
            left: 250px;
            right: 0;
            height: 50px;
            background: #1e3a8a;
            border-top: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.85);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            font-size: 12px;
            z-index: 1010;
            box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .footer-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .footer-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .footer-link {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 12px;
        }
        
        .footer-link:hover {
            color: #fff;
        }
        
        .footer-divider {
            height: 20px;
            width: 1px;
            background: rgba(255,255,255,0.2);
        }
        
        /* Enhanced Badges */
        .badge { 
            font-size: 9px; 
            padding: 3px 7px; 
            margin-left: auto;
            border-radius: 10px;
            font-weight: 600;
        }
        
        /* Enhanced Dropdown */
        .dropdown-menu { 
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-radius: 6px;
            padding: 6px;
            margin-top: 6px;
        }
        
        .dropdown-item { 
            padding: 9px 14px;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .dropdown-item:hover { 
            background: #f3f4f6;
            color: #1f2937;
        }
        
        .dropdown-item i { 
            width: 18px;
            text-align: center;
        }
        
        .dropdown-divider { 
            margin: 6px 0;
            opacity: 0.1;
        }
        
        /* Menu Section Headers */
        .nav-header {
            padding: 14px 18px 6px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* User Avatar */
        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 9px;
            font-weight: 600;
            font-size: 13px;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        
        .user-name {
            font-size: 13px;
            font-weight: 500;
            color: #fff;
        }
        
        .user-role {
            font-size: 10px;
            color: rgba(255,255,255,0.7);
        }
        
        /* Dropdown Header Styling */
        .dropdown-header {
            padding: 10px 14px;
            background: #f9fafb;
            border-radius: 4px;
            margin-bottom: 6px;
        }
        
        .dropdown-header strong {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 2px;
        }
        
        /* Notification Badge */
        .notification-badge {
            position: absolute;
            top: 16px;
            right: 8px;
            min-width: 17px;
            height: 17px;
            padding: 0 4px;
            border-radius: 8px;
            background: #ef4444;
            color: #fff;
            font-size: 9px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        
        /* Responsive */
        @media (max-width: 991.98px) {
            .main-sidebar { 
                margin-left: -250px; 
            }
            .main-sidebar.sidebar-open { 
                margin-left: 0;
                box-shadow: 4px 0 12px rgba(0,0,0,0.15);
            }
            .content-wrapper { 
                margin-left: 0; 
            }
            .main-footer {
                left: 0;
            }
            .brand-logo {
                width: 38px;
                height: 38px;
            }
            .brand-text-sub {
                display: none;
            }
            .user-info {
                display: none;
            }
            .footer-left span:not(:first-child),
            .footer-divider {
                display: none;
            }
        }
        
        /* Scrollbar Styling */
        .main-sidebar::-webkit-scrollbar { 
            width: 5px; 
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
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">

    <!-- Professional Navbar -->
    <nav class="main-header navbar navbar-expand">
        <ul class="navbar-nav">
            <li class="nav-item d-lg-none">
                <a class="nav-link" href="#" onclick="document.getElementById('mainSidebar').classList.toggle('sidebar-open')">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item">
                <a href="<?php echo $base_path; ?>index.php" class="navbar-brand">
                    <img src="<?php echo $base_path; ?>assets/img/logo.jpg" alt="Logo" class="brand-logo">
                    <div class="brand-text">
                        <span class="brand-text-main">Mkumbi Investment ERP</span>
                        <span class="brand-text-sub">Real Estate Management</span>
                    </div>
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
                    <h6 class="dropdown-header">
                        <strong>Quick Actions</strong>
                    </h6>
                    <a href="<?php echo $base_path; ?>modules/sales/create.php" class="dropdown-item">
                        <i class="fas fa-plus-circle me-2 text-success"></i> New Reservation
                    </a>
                    <a href="<?php echo $base_path; ?>modules/payments/record.php" class="dropdown-item">
                        <i class="fas fa-money-bill-wave me-2 text-info"></i> Record Payment
                    </a>
                    <a href="<?php echo $base_path; ?>modules/marketing/create-lead.php" class="dropdown-item">
                        <i class="fas fa-user-plus me-2 text-primary"></i> Add Lead
                    </a>
                    <a href="<?php echo $base_path; ?>modules/titledeed/initiate.php" class="dropdown-item">
                        <i class="fas fa-certificate me-2 text-warning"></i> Title Processing
                    </a>
                </div>
            </li>
            
            <!-- Notifications Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link position-relative" data-bs-toggle="dropdown" href="#" title="Notifications">
                    <i class="far fa-bell"></i>
                    <span class="notification-badge" style="background: #f59e0b;">8</span>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 320px;">
                    <h6 class="dropdown-header">
                        <strong>Notifications</strong>
                        <p class="mb-0 small text-muted">You have 8 new notifications</p>
                    </h6>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-check-circle me-2 text-success"></i> New payment received
                        <small class="float-end text-muted">5m ago</small>
                    </a>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-exclamation-triangle me-2 text-warning"></i> 3 payments overdue
                        <small class="float-end text-muted">1h ago</small>
                    </a>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-certificate me-2 text-info"></i> Title deed ready
                        <small class="float-end text-muted">2h ago</small>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item text-center small text-primary">View All Notifications</a>
                </div>
            </li>
            
            <!-- Tasks Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link position-relative" data-bs-toggle="dropdown" href="#" title="Tasks">
                    <i class="fas fa-tasks"></i>
                    <span class="notification-badge" style="background: #3b82f6;">6</span>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 280px;">
                    <h6 class="dropdown-header">
                        <strong>My Tasks</strong>
                        <p class="mb-0 small text-muted">6 tasks pending</p>
                    </h6>
                    <a href="<?php echo $base_path; ?>modules/tasks/my-tasks.php" class="dropdown-item">
                        <i class="fas fa-list me-2 text-primary"></i> View My Tasks
                    </a>
                    <a href="<?php echo $base_path; ?>modules/approvals/pending.php" class="dropdown-item">
                        <i class="fas fa-check-double me-2 text-success"></i> Pending Approvals
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo $base_path; ?>modules/tasks/index.php" class="dropdown-item text-center small text-primary">All Tasks</a>
                </div>
            </li>
            
            <!-- User Profile Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link d-flex align-items-center" data-bs-toggle="dropdown" href="#" title="Profile">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?php echo $_SESSION['full_name'] ?? 'User'; ?></span>
                        <span class="user-role">Administrator</span>
                    </div>
                    <i class="fas fa-chevron-down ms-2" style="font-size: 10px; color: rgba(255,255,255,0.7);"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end" style="min-width: 230px;">
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
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo $base_path; ?>logout.php" class="dropdown-item text-danger">
                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                    </a>
                </div>
            </li>
        </ul>
    </nav>

    <!-- Professional Sidebar -->
    <aside class="main-sidebar" id="mainSidebar">
        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" role="menu">

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
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/projects/creditors.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Project Creditors</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/projects/statements.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Creditor Statements</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/plots/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Plots</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/plots/create.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Add Plot</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/plots/inventory.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Plot Inventory</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/plots/movements.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Stock Movement</span></a></li>
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
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/online-bookings.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Online Bookings</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/campaigns.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Campaigns</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/quotations.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Sales Quotations</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/marketing/booking-link.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Booking Link</span></a></li>
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
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/customers/statements.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Customer Statements</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/customers/debtors.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Debtors / AR</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/customers/aging.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Debtors Aging</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/customers/writeoffs.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Write-offs</span></a></li>
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
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sales/templates.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Contract Templates</span></a></li>
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
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/services/quotations.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Service Quotations</span></a></li>
                        </ul>
                    </li>
                    
                    <!-- Title Deed Processing -->
                    <li class="nav-item <?php echo ($current_module == 'titledeed') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-certificate"></i>
                            <span>Title Deed Processing</span>
                            <span class="badge bg-info">12</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/titledeed/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Processes</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/titledeed/eligible.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Eligible Customers</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/titledeed/initiate.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Initiate Processing</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/titledeed/stages.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Processing Stages</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/titledeed/costs.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Processing Costs</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/titledeed/completed.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Completed</span></a></li>
                        </ul>
                    </li>

                    <!-- FINANCIAL SECTION -->
                    <li class="nav-header">FINANCIAL MANAGEMENT</li>

                    <!-- Receipts/Income -->
                    <li class="nav-item <?php echo ($current_module == 'payments') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-money-bill-wave"></i>
                            <span>Receipts/Income</span>
                            <span class="badge bg-success">5</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Receipts</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/record.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Record Receipt</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/schedule.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Payment Schedules</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/refunds.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Refunds</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/payments/vouchers.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Vouchers & Receipts</span></a></li>
                        </ul>
                    </li>

                    <!-- Payments/Expenses -->
                    <li class="nav-item <?php echo ($current_module == 'expenses' || $current_module == 'payments') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-receipt"></i>
                            <span>Payments/Expenses</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Expenses</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/create_claim.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Submit Claim</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/claims.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Expense Claims</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/direct.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Direct Expenses</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/approvals.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Approvals</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/expenses/categories.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Categories</span></a></li>
                        </ul>
                    </li>

                    <!-- Petty Cash -->
                    <li class="nav-item <?php echo ($current_module == 'petty_cash') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-cash-register"></i>
                            <span>Petty Cash</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/petty_cash/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Dashboard</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/petty_cash/request.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Request Cash</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/petty_cash/approvals.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Approvals</span></a></li>
                        </ul>
                    </li>

                    <!-- Assets -->
                    <li class="nav-item <?php echo ($current_module == 'assets') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-laptop"></i>
                            <span>Fixed Assets</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Dashboard</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/add.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Add Asset</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/list.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Asset Register</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/assets/depreciation.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Depreciation</span></a></li>
                        </ul>
                    </li>

                    <!-- Loans -->
                    <li class="nav-item <?php echo ($current_module == 'loans') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-hand-holding-usd"></i>
                            <span>Loans</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Dashboard</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/apply.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Apply for Loan</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/my-loans.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>My Loans</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/approvals.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Approvals</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/loans/loan-types.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Loan Types</span></a></li>
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
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/finance/cash_book.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Cash Book</span></a></li>
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
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tax/computation.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Tax Computation</span></a></li>
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
                        </ul>
                    </li>

                    <!-- Human Resources -->
                    <li class="nav-item <?php echo (in_array($current_module, ['hr', 'leave'])) ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <span>Human Resources</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/employees.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Employees</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/attendance.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Attendance</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/hr/payroll.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Payroll</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Management</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/apply.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Apply for Leave</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/my-leaves.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>My Leaves</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/balance.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Balance</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/approvals.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Approvals</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/leave-types.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Types</span></a></li>
                        </ul>
                    </li>

                    <!-- WORKFLOW SECTION -->
                    <li class="nav-header">WORKFLOW & COMMUNICATION</li>

                    <!-- Task Management -->
                    <li class="nav-item <?php echo ($current_module == 'tasks') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-tasks"></i>
                            <span>Task Management</span>
                            <span class="badge bg-primary">12</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tasks/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>All Tasks</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tasks/my-tasks.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>My Tasks</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tasks/assigned.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Tasks I Assigned</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tasks/create.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Create Task</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/tasks/pending-approval.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Pending Approval</span></a></li>
                        </ul>
                    </li>

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

                    <!-- Bulk SMS -->
                    <li class="nav-item <?php echo ($current_module == 'sms') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-sms"></i>
                            <span>Bulk SMS</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sms/campaigns.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>SMS Campaigns</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sms/send.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Send SMS</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sms/history.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>SMS History</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sms/templates.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>SMS Templates</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sms/groups.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Contact Groups</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/sms/settings.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>SMS Settings</span></a></li>
                        </ul>
                    </li>

                    <!-- ANALYTICS SECTION -->
                    <li class="nav-header">REPORTS & ANALYTICS</li>

                    <!-- Financial Reports -->
                    <li class="nav-item <?php echo ($current_module == 'reports' && in_array($current_page, ['income.php','balance.php','cashflow.php','equity.php'])) ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-file-invoice"></i>
                            <span>Financial Statements</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/income.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Income Statement</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/balance.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Balance Sheet</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/cashflow.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Cash Flow Statement</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/equity.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Changes in Equity</span></a></li>
                        </ul>
                    </li>

                    <!-- Reports -->
                    <li class="nav-item <?php echo ($current_module == 'reports') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <span>Reports</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/reports/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Reports Hub</span></a></li>
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