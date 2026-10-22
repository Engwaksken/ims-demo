<?php
// includes/db.php never existed: use the shared config + an auth check.
require_once __DIR__ . '/includes/config.php';
check_role(['Administrator', 'MEAL Lead', 'Programs Lead', 'Executive Director', 'Program Director']);
foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;

// --- Validate survey ID ---
$surveyId = (int)($_GET['id'] ?? 0);
$exportType = $_GET['type'] ?? 'excel'; // 'excel' or 'pdf'

if ($surveyId <= 0) die("Invalid survey ID.");

// --- Fetch survey info ---
$stmt = $conn->prepare("SELECT survey_name FROM surveys WHERE survey_id=?");
$stmt->bind_param("i", $surveyId);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$surveyName = $survey['survey_name'] ?? 'Survey_'.$surveyId;

// --- Fetch answers grouped by question ---
$stmt = $conn->prepare("
    SELECT q.question_id, q.question_text, q.question_type,
           a.answer_text, COUNT(a.answer_text) as count
    FROM survey_questions q
    LEFT JOIN survey_answers a ON q.question_id = a.question_id
    WHERE q.survey_id = ?
    GROUP BY q.question_id, a.answer_text
    ORDER BY q.question_order, a.answer_text
");
$stmt->bind_param("i", $surveyId);
$stmt->execute();
$result = $stmt->get_result();

// --- Build per-question summary ---
$questions = [];
while ($row = $result->fetch_assoc()) {
    $qid = $row['question_id'];
    if (!isset($questions[$qid])) {
        $questions[$qid] = [
            'text' => $row['question_text'],
            'type' => $row['question_type'],
            'answers' => []
        ];
    }
    if ($row['answer_text'] !== null) {
        $questions[$qid]['answers'][$row['answer_text']] = (int)$row['count'];
    }
}
$stmt->close();

// --- Export PDF with charts ---
if ($exportType === 'pdf') {
    $html = "<h2>".htmlspecialchars($surveyName)."</h2>";

    foreach ($questions as $q) {
        $html .= "<h4>".htmlspecialchars($q['text'])."</h4>";

        if (!empty($q['answers']) && in_array($q['type'], ['radio','checkbox','select'])) {
            // Generate chart as base64 PNG using QuickChart API
            $labels = array_map('htmlspecialchars', array_keys($q['answers']));
            $data   = array_values($q['answers']);

            $chartConfig = [
                'type' => 'bar',
                'data' => [
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Responses',
                            'data' => $data,
                            'backgroundColor' => 'rgba(54, 162, 235, 0.6)'
                        ]
                    ]
                ],
                'options' => [
                    'plugins' => [
                        'legend' => ['display' => false]
                    ],
                    'scales' => [
                        'y' => ['beginAtZero' => true]
                    ]
                ]
            ];

            $chartUrl = 'https://quickchart.io/chart?c=' . urlencode(json_encode($chartConfig));

            // Fetch chart image as base64
            $imgData = base64_encode(file_get_contents($chartUrl));

            $html .= "<img src='data:image/png;base64,{$imgData}' style='width:100%; max-width:500px;' />";
        }

        // List all answers as table
        if (!empty($q['answers'])) {
            $html .= "<table border='1' cellpadding='5' cellspacing='0' width='100%' style='margin-bottom:20px;'>";
            $html .= "<thead><tr><th>Answer</th><th>Count</th></tr></thead><tbody>";
            foreach ($q['answers'] as $ans => $cnt) {
                $html .= "<tr><td>".htmlspecialchars($ans)."</td><td>{$cnt}</td></tr>";
            }
            $html .= "</tbody></table>";
        } else {
            $html .= "<p class='text-muted'>No responses yet.</p>";
        }
    }

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(preg_replace('/\W/','_',$surveyName).".pdf", ["Attachment" => true]);
    exit;
}

// --- Export Excel ---
if ($exportType === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($surveyName,0,31));

    // Headers
    $sheet->setCellValue('A1','Question');
    $sheet->setCellValue('B1','Answer');
    $sheet->setCellValue('C1','Count');

    $rowNum = 2;
    foreach ($questions as $q) {
        foreach ($q['answers'] as $ans => $cnt) {
            $sheet->setCellValue("A$rowNum", $q['text']);
            $sheet->setCellValue("B$rowNum", $ans);
            $sheet->setCellValue("C$rowNum", $cnt);
            $rowNum++;
        }
    }

    foreach (range('A','C') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.preg_replace('/\W/','_',$surveyName).'.xlsx"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

die("Invalid export type. Use ?type=excel or ?type=pdf");