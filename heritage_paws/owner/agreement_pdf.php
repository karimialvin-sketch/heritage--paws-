<?php
// Generates and downloads the agreement as a PDF. Only the two owners and the admin can open it.
require_once __DIR__ . '/../includes/agreement_helpers.php';
require_role('buyer', 'seller', 'admin');
$user = current_user();

$a = agreement_load($pdo, (int)($_GET['id'] ?? 0));
if (!$a || !agreement_can_view($a, $user)) {
    http_response_code(404);
    die('Agreement not found.');
}

$pdf = agreement_pdf($a);

while (ob_get_level()) ob_end_clean();     // nothing else may be sent before the PDF bytes
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['view']) ? 'inline' : 'attachment') . '; filename="' . agreement_file_name($a) . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
