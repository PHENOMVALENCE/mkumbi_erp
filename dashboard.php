<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ERP System</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #212529;
            background-color: #f4f6f9;
        }

        /* Main Header */
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            background-color: #ffffff;
            border-bottom: 1px solid #dee2e6;
            height: 57px;
        }

        .navbar {
            padding: 0;
            height: 57px;
        }

        .navbar-brand {
            padding: 0 15px;
            font-size: 20px;
            line-height: 57px;
            color: #343a40;
            text-decoration: none;
            font-weight: 300;
            display: inline-block;
        }

        .navbar-brand b {
            font-weight: 700;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
        }

        .nav-item {
            list-style: none;
        }

        .nav-link {
            padding: 0 15px;
            color: #212529;
            display: flex;
            align-items: center;
            height: 57px;
            text-decoration: none;
            font-size: 14px;
        }

        .nav-link:hover {
            background-color: #f8f9fa;
            color: #000000;
        }

        .nav-link .badge {
            font-size: 10px;
            padding: 2px 5px;
            margin-left: 5px;
        }

        /* Dropdown */
        .dropdown-menu {
            font-size: 14px;
            border-radius: 0;
            border: 1px solid rgba(0,0,0,0.15);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }

        .dropdown-item {
            padding: 0.5rem 1rem;
            font-size: 14px;
        }

        .dropdown-item:hover {
            background-color: #f8f9fa;
        }

        /* Sidebar */
        .main-sidebar {
            position: fixed;
            top: 57px;
            bottom: 0;
            left: 0;
            width: 250px;
            background-color: #343a40;
            overflow-y: auto;
            z-index: 1020;
        }

        .nav-sidebar {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .nav-sidebar .nav-item {
            margin: 0;
        }

        .nav-sidebar .nav-link {
            color: #c2c7d0;
            padding: 12px 15px;
            display: flex;
            align-items: center;
            font-size: 14px;
            border-left: 3px solid transparent;
        }

        .nav-sidebar .nav-link:hover {
            background-color: #3f474e;
            color: #ffffff;
        }

        .nav-sidebar .nav-link.active {
            color: #ffffff;
            background-color: #007bff;
            border-left-color: #007bff;
        }

        .nav-sidebar .nav-link i {
            width: 20px;
            text-align: center;
            margin-right: 10px;
            font-size: 14px;
        }

        .nav-sidebar .badge {
            margin-left: auto;
            font-size: 10px;
        }

        /* Content Wrapper */
        .content-wrapper {
            margin-left: 250px;
            margin-top: 57px;
            min-height: calc(100vh - 57px);
            padding: 15px;
            background-color: #f4f6f9;
        }

        /* Content Header */
        .content-header {
            padding: 15px;
            margin-bottom: 15px;
        }

        .content-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 400;
            color: #212529;
        }

        .breadcrumb {
            background: transparent;
            padding: 0;
            margin: 8px 0 0;
            font-size: 13px;
        }

        .breadcrumb-item + .breadcrumb-item::before {
            content: ">";
            color: #6c757d;
        }

        /* Small Box (Stats Cards) */
        .small-box {
            border-radius: 0;
            box-shadow: 0 0 1px rgba(0,0,0,0.125), 0 1px 3px rgba(0,0,0,0.2);
            display: block;
            margin-bottom: 20px;
            position: relative;
            background-color: #ffffff;
        }

        .small-box .inner {
            padding: 15px;
        }

        .small-box h3 {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 10px;
            padding: 0;
            white-space: nowrap;
            color: #212529;
        }

        .small-box p {
            font-size: 14px;
            margin: 0;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .small-box .icon {
            color: rgba(0,0,0,0.05);
            font-size: 70px;
            position: absolute;
            right: 15px;
            top: 15px;
            transition: transform 0.3s linear;
        }

        .small-box:hover .icon {
            transform: scale(1.1);
        }

        .small-box .small-box-footer {
            background-color: rgba(0,0,0,0.1);
            color: rgba(0,0,0,0.8);
            display: block;
            padding: 8px 15px;
            position: relative;
            text-align: center;
            text-decoration: none;
            z-index: 10;
            font-size: 13px;
        }

        .small-box .small-box-footer:hover {
            background-color: rgba(0,0,0,0.15);
            color: #000000;
        }

        .bg-info { background-color: #17a2b8 !important; color: #fff; }
        .bg-success { background-color: #28a745 !important; color: #fff; }
        .bg-warning { background-color: #ffc107 !important; color: #212529; }
        .bg-danger { background-color: #dc3545 !important; color: #fff; }

        /* Card */
        .card {
            box-shadow: 0 0 1px rgba(0,0,0,0.125), 0 1px 3px rgba(0,0,0,0.2);
            margin-bottom: 20px;
            border-radius: 0;
            border: 0;
            background-color: #ffffff;
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid rgba(0,0,0,0.125);
            padding: 12px 15px;
            position: relative;
            border-radius: 0;
        }

        .card-title {
            float: left;
            font-size: 16px;
            font-weight: 400;
            margin: 0;
            color: #212529;
        }

        .card-tools {
            float: right;
            margin-right: -8px;
        }

        .card-tools .btn {
            padding: 4px 8px;
            font-size: 12px;
        }

        .card-body {
            padding: 15px;
        }

        /* Table */
        .table {
            width: 100%;
            margin-bottom: 0;
            font-size: 14px;
        }

        .table thead th {
            border-bottom: 2px solid #dee2e6;
            border-top: 0;
            font-weight: 600;
            padding: 12px;
            vertical-align: bottom;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
        }

        .table tbody td {
            padding: 12px;
            vertical-align: middle;
            border-top: 1px solid #dee2e6;
        }

        .table-hover tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* Buttons */
        .btn {
            border-radius: 0;
            font-size: 14px;
            padding: 6px 12px;
            font-weight: 400;
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }

        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }

        .btn-primary:hover {
            background-color: #0069d9;
            border-color: #0062cc;
        }

        .btn-dark {
            background-color: #343a40;
            border-color: #343a40;
            color: #ffffff;
        }

        .btn-dark:hover {
            background-color: #23272b;
            border-color: #1d2124;
        }

        /* Badge */
        .badge {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 3px;
        }

        /* List Group */
        .list-group-item {
            border-radius: 0;
            font-size: 14px;
            padding: 12px 15px;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .main-sidebar {
                margin-left: -250px;
                transition: margin-left 0.3s;
            }

            .main-sidebar.sidebar-open {
                margin-left: 0;
            }

            .content-wrapper {
                margin-left: 0;
            }

            .sidebar-toggle {
                display: block !important;
            }
        }

        .sidebar-toggle {
            display: none;
            cursor: pointer;
            padding: 0 15px;
            height: 57px;
            line-height: 57px;
        }

        /* Utility Classes */
        .clearfix::after {
            display: block;
            clear: both;
            content: "";
        }

        .float-right {
            float: right;
        }

        .text-sm {
            font-size: 13px;
        }

        .mt-3 {
            margin-top: 1rem;
        }

        /* Scrollbar */
        .main-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .main-sidebar::-webkit-scrollbar-track {
            background: #3f474e;
        }

        .main-sidebar::-webkit-scrollbar-thumb {
            background: #6c757d;
            border-radius: 3px;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand">
            <!-- Left navbar links -->
            <ul class="navbar-nav">
                <li class="nav-item sidebar-toggle d-lg-none">
                    <a class="nav-link" href="#" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </a>
                </li>
                <li class="nav-item d-none d-sm-inline-block">
                    <a href="#" class="navbar-brand">
                        <b>ERP</b> System
                    </a>
                </li>
            </ul>

            <!-- Right navbar links -->
            <ul class="navbar-nav ms-auto">
                <!-- Notifications Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link" data-bs-toggle="dropdown" href="#">
                        <i class="far fa-bell"></i>
                        <span class="badge bg-warning">5</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <span class="dropdown-item dropdown-header">5 Notifications</span>
                        <div class="dropdown-divider"></div>
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-envelope me-2"></i> 3 new messages
                        </a>
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-file me-2"></i> 2 pending approvals
                        </a>
                    </div>
                </li>
                
                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link" data-bs-toggle="dropdown" href="#">
                        <i class="far fa-user"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-user me-2"></i> Profile
                        </a>
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-cog me-2"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </li>
            </ul>
        </nav>

        <!-- Main Sidebar -->
        <aside class="main-sidebar" id="mainSidebar">
            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Sidebar Menu -->
                <nav class="mt-2">
                    <ul class="nav nav-sidebar flex-column" data-widget="treeview" role="menu">
                        <li class="nav-item">
                            <a href="#" class="nav-link active">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-map-marked-alt"></i>
                                <span>Plots & Projects</span>
                                <span class="badge bg-info">12</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-users"></i>
                                <span>Customers</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-file-contract"></i>
                                <span>Sales</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-money-bill-wave"></i>
                                <span>Payments</span>
                                <span class="badge bg-success">5</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-calculator"></i>
                                <span>Accounting</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-user-tie"></i>
                                <span>HR</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-chart-bar"></i>
                                <span>Reports</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-cog"></i>
                                <span>Settings</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <!-- Content Header -->
            <div class="content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h1>Dashboard</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="#">Home</a></li>
                                <li class="breadcrumb-item active">Dashboard</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main content -->
            <section class="content">
                <div class="container-fluid">
                    <!-- Small boxes (Stats cards) -->
                    <div class="row">
                        <div class="col-lg-3 col-6">
                            <div class="small-box bg-info">
                                <div class="inner">
                                    <h3>45</h3>
                                    <p>Available Plots</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-map-marked-alt"></i>
                                </div>
                                <a href="#" class="small-box-footer">
                                    More info <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>

                        <div class="col-lg-3 col-6">
                            <div class="small-box bg-success">
                                <div class="inner">
                                    <h3>TSH 450M</h3>
                                    <p>Total Revenue</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <a href="#" class="small-box-footer">
                                    More info <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>

                        <div class="col-lg-3 col-6">
                            <div class="small-box bg-warning">
                                <div class="inner">
                                    <h3>234</h3>
                                    <p>Customers</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <a href="#" class="small-box-footer">
                                    More info <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>

                        <div class="col-lg-3 col-6">
                            <div class="small-box bg-danger">
                                <div class="inner">
                                    <h3>12</h3>
                                    <p>Overdue Payments</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <a href="#" class="small-box-footer">
                                    More info <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Main row -->
                    <div class="row">
                        <!-- Recent Payments -->
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header clearfix">
                                    <h3 class="card-title">Recent Payments</h3>
                                    <div class="card-tools">
                                        <a href="#" class="btn btn-dark btn-sm">View All</a>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Customer</th>
                                                    <th>Plot</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>2025-11-29</td>
                                                    <td>John Mwamba</td>
                                                    <td>Plot 123, Block A</td>
                                                    <td>TSH 2,000,000</td>
                                                    <td><span class="badge bg-success">Paid</span></td>
                                                </tr>
                                                <tr>
                                                    <td>2025-11-29</td>
                                                    <td>Mary Kimaro</td>
                                                    <td>Plot 45, Block B</td>
                                                    <td>TSH 430,000</td>
                                                    <td><span class="badge bg-success">Paid</span></td>
                                                </tr>
                                                <tr>
                                                    <td>2025-11-28</td>
                                                    <td>Peter Ndege</td>
                                                    <td>Plot 78, Block C</td>
                                                    <td>TSH 430,000</td>
                                                    <td><span class="badge bg-warning">Pending</span></td>
                                                </tr>
                                                <tr>
                                                    <td>2025-11-28</td>
                                                    <td>Grace Moshi</td>
                                                    <td>Plot 12, Block A</td>
                                                    <td>TSH 1,500,000</td>
                                                    <td><span class="badge bg-success">Paid</span></td>
                                                </tr>
                                                <tr>
                                                    <td>2025-11-27</td>
                                                    <td>James Bakari</td>
                                                    <td>Plot 56, Block D</td>
                                                    <td>TSH 430,000</td>
                                                    <td><span class="badge bg-danger">Overdue</span></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pending Approvals -->
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Pending Approvals</h3>
                                </div>
                                <div class="card-body p-0">
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Payment Approval
                                            <span class="badge bg-warning">3</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Refund Requests
                                            <span class="badge bg-info">2</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Contract Cancellations
                                            <span class="badge bg-danger">1</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Purchase Orders
                                            <span class="badge bg-success">5</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('mainSidebar');
            sidebar.classList.toggle('sidebar-open');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('mainSidebar');
            const toggle = document.querySelector('.sidebar-toggle');
            
            if (window.innerWidth < 992) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('sidebar-open');
                }
            }
        });
    </script>
</body>
</html>