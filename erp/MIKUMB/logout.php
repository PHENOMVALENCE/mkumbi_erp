<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);

// Get all plots
$plots = [
    ['plot_id' => 1, 'plot_number' => '123', 'block' => 'A', 'size' => 530, 'price' => 10600000, 'status' => 'Available'],
    ['plot_id' => 2, 'plot_number' => '124', 'block' => 'A', 'size' => 540, 'price' => 10800000, 'status' => 'Reserved'],
    ['plot_id' => 3, 'plot_number' => '125', 'block' => 'B', 'size' => 600, 'price' => 12000000, 'status' => 'Available'],
    ['plot_id' => 4, 'plot_number' => '126', 'block' => 'B', 'size' => 550, 'price' => 11000000, 'status' => 'Sold'],
    ['plot_id' => 5, 'plot_number' => '127', 'block' => 'C', 'size' => 580, 'price' => 11600000, 'status' => 'Available'],
];

$page_title = 'Plots Management';
require_once '../../includes/header.php';
?>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">Plots Management</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="<?php echo APP_URL; ?>/index.php">Home</a></li>
                    <li class="breadcrumb-item active">Plots</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">All Plots</h3>
                        <div class="card-tools">
                            <a href="create.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Add New Plot
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 10px">#</th>
                                        <th>Plot Number</th>
                                        <th>Block</th>
                                        <th>Size (sqm)</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th style="width: 120px">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plots as $index => $plot): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo $plot['plot_number']; ?></td>
                                        <td>Block <?php echo $plot['block']; ?></td>
                                        <td><?php echo number_format($plot['size']); ?> sqm</td>
                                        <td>TSH <?php echo number_format($plot['price']); ?></td>
                                        <td>
                                            <?php
                                            $badge_class = 'bg-success';
                                            if ($plot['status'] == 'Reserved') $badge_class = 'bg-warning';
                                            if ($plot['status'] == 'Sold') $badge_class = 'bg-info';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo $plot['status']; ?></span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?php echo $plot['plot_id']; ?>" class="btn btn-info" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?php echo $plot['plot_id']; ?>" class="btn btn-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="delete.php?id=<?php echo $plot['plot_id']; ?>" class="btn btn-danger" title="Delete" onclick="return confirm('Are you sure?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer clearfix">
                        <ul class="pagination pagination-sm m-0 float-right">
                            <li class="page-item"><a class="page-link" href="#">«</a></li>
                            <li class="page-item"><a class="page-link" href="#">1</a></li>
                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                            <li class="page-item"><a class="page-link" href="#">»</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    .card {
        box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
        border-radius: 0;
    }

    .table {
        font-size: 14px;
    }

    .table thead th {
        background-color: #f8f9fa;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        color: #495057;
    }

    .btn-group-sm>.btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }

    .pagination-sm .page-link {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
</style>

<?php require_once '../../includes/footer.php'; ?>