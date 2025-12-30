<?php
// Shared lightweight stub renderer for report placeholders
function render_report_stub($title, $subtitle = '') {
    $page_title = $title;
    if (!defined('APP_ACCESS')) {
        define('APP_ACCESS', true);
    }
    require_once '../../config/database.php';
    require_once '../../config/auth.php';
    session_start();

    $auth = new Auth();
    $auth->requireLogin();

    $db = Database::getInstance();
    $db->setCompanyId($_SESSION['company_id']);
    $conn = $db->getConnection();
    $company_id = $_SESSION['company_id'];

    require_once '../../includes/header.php';
    ?>
    <div class="content-header mb-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8"><h1 class="m-0 fw-bold"><?php echo htmlspecialchars($title); ?></h1></div>
            </div>
        </div>
    </div>
    <div class="container-fluid">
        <div class="alert alert-info"><strong>Heads up:</strong> This report page is available but not yet customized. Add filters and output as needed.</div>
        <?php if (!empty($subtitle)): ?><p class="text-muted"><?php echo htmlspecialchars($subtitle); ?></p><?php endif; ?>
    </div>
    <?php
    require_once '../../includes/footer.php';
}
