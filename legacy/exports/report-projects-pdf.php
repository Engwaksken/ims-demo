<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;

check_role(['Administrator','Programs Lead','MEAL Lead']);

$html = '<h2 style="text-align:center">Projects Report</h2>';
$html .= '<table width="100%" border="1" cellspacing="0" cellpadding="6">';
$html .= '
<tr style="background:#f4f4f4">
<th>Code</th><th>Project</th><th>Donor</th>
<th>Status</th><th>Start</th><th>End</th><th>Budget</th>
</tr>';

$res = $conn->query("
SELECT p.project_code,p.project_name,d.donor_name,p.status,
p.start_date,p.end_date,p.budget,p.currency
FROM projects p
LEFT JOIN donors d ON p.donor_id=d.donor_id
ORDER BY p.start_date DESC
");

while ($r = $res->fetch_assoc()) {
    $html .= '<tr>
        <td>'.$r['project_code'].'</td>
        <td>'.$r['project_name'].'</td>
        <td>'.($r['donor_name'] ?? 'N/A').'</td>
        <td>'.$r['status'].'</td>
        <td>'.$r['start_date'].'</td>
        <td>'.$r['end_date'].'</td>
        <td>'.$r['currency'].' '.number_format($r['budget']).'</td>
    </tr>';
}
$html .= '</table>';

$pdf = new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper('A4','landscape');
$pdf->render();
require_once __DIR__ . '/../includes/pdf-branding.php';
ims_brand_pdf($pdf);
$pdf->stream('projects_report.pdf', ['Attachment'=>1]);
