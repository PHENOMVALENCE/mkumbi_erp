<?php
// Simple passthrough for quick generator form
require_once __DIR__ . '/_stub_template.php';
$type = $_GET['type'] ?? '';
$title = 'Generated Report';
$subtitle = '';
if ($type === 'payroll') { $title = 'Payroll Summary'; }
elseif ($type === 'leave') { $title = 'Leave Report'; }
elseif ($type === 'loans') { $title = 'Loan Report'; }
elseif ($type === 'assets') { $title = 'Asset Report'; }
elseif ($type === 'petty_cash') { $title = 'Petty Cash Report'; }
$subtitle = 'Generated placeholder for ' . ($type ?: 'custom') . ' report. Add logic to filter and export.';
render_report_stub($title, $subtitle);
