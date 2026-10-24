<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class RiskRatingReportService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function fetchOne(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $this->conn->error);
        }

        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('Execute failed: ' . $err);
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    private function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $this->conn->error);
        }

        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('Execute failed: ' . $err);
        }

        $rows = [];
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $res->free();
        }

        $stmt->close();
        return $rows;
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function nl(mixed $value): string
    {
        return nl2br($this->e($value));
    }

    private function normalizeCheckState(array $check): array
    {
        $isYes = (int)($check['is_yes'] ?? 0) === 1;
        $isNo  = (int)($check['is_no'] ?? 0) === 1;

        if ($isYes && $isNo) {
            $isNo = false;
        }

        return [$isYes, $isNo];
    }

    private function getReportData(int $riskRatingId): array
    {
        $rating = $this->fetchOne("
            SELECT rr.*, a.*, o.opportunity_title, o.opportunity_type
            FROM risk_ratings rr
            INNER JOIN applications a ON a.application_id = rr.application_id
            LEFT JOIN application_opportunities o ON o.opportunity_id = rr.opportunity_id
            WHERE rr.id = ?
            LIMIT 1
        ", 'i', [$riskRatingId]);

        if (!$rating) {
            throw new Exception('Risk rating not found.');
        }

        $sections = $this->fetchAll("
            SELECT *
            FROM risk_rating_sections
            WHERE risk_rating_id = ?
            ORDER BY FIELD(pillar_key, 'governance','operational','delivery','fiduciary','safeguarding','reputational'), id ASC
        ", 'i', [$riskRatingId]);

        $checks = $this->fetchAll("
            SELECT *
            FROM risk_rating_checks
            WHERE risk_rating_id = ?
            ORDER BY id ASC
        ", 'i', [$riskRatingId]);

        $criteriaScores = $this->fetchAll("
            SELECT rcs.*, rt.name AS review_type_name
            FROM risk_rating_criteria_scores rcs
            LEFT JOIN review_types rt ON rt.review_type_id = rcs.review_type_id
            WHERE rcs.risk_rating_id = ?
            ORDER BY rcs.review_type_id, rcs.criteria_id
        ", 'i', [$riskRatingId]);

        return [
            'rating' => $rating,
            'sections' => $sections,
            'checks' => $checks,
            'criteriaScores' => $criteriaScores,
        ];
    }

    public function generateExcelFromTemplate(int $riskRatingId, int $generatedBy): array
    {
        $data = $this->getReportData($riskRatingId);
        $rating = $data['rating'];
        $sections = $data['sections'];
        $checks = $data['checks'];
        $criteriaScores = $data['criteriaScores'];

        $headerBg    = '1F3B64';
        $subHeaderBg = 'D9E1F2';
        $altRowBg    = 'F4F6FA';
        $borderColor = 'BDBDBD';

        $styleHeader = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 13],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerBg]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true
            ],
        ];

        $styleSubHeader = [
            'font'      => ['bold' => true, 'size' => 10],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $subHeaderBg]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true
            ],
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => $borderColor]
                ]
            ],
        ];

        $styleCell = [
            'font'      => ['size' => 10],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_TOP,
                'wrapText'   => true
            ],
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => $borderColor]
                ]
            ],
        ];

        $styleLabelCell = array_merge_recursive($styleCell, [
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F4F6F8']],
        ]);

        $styleSectionTitle = [
            'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => $headerBg]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $subHeaderBg]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER
            ],
            'borders'   => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color'       => ['rgb' => $headerBg]
                ]
            ],
        ];

        $styleCenter = array_merge($styleCell, [
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER
            ],
        ]);

        $styleRight = array_merge($styleCell, [
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical'   => Alignment::VERTICAL_CENTER
            ],
        ]);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Due Diligence Risk Rating Report')
            ->setSubject((string)($rating['startup_name'] ?? ''))
            ->setCreator('Risk Rating System');

        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Summary');

        $ws->mergeCells('A1:G1');
        $ws->setCellValue('A1', 'DUE DILIGENCE REPORT � MASTERCARD FOUNDATION EDTECH FELLOWSHIP');
        $ws->getStyle('A1')->applyFromArray($styleHeader);
        $ws->getRowDimension(1)->setRowHeight(30);

        $summaryFields = [
            ['Name of Entrepreneur', $rating['founder_names'] ?: ($rating['contact_person'] ?? '')],
            ['Contact',              $rating['phone'] ?? ''],
            ['EdTech Enterprise',    $rating['startup_name'] ?? ''],
            ['Email',                $rating['email'] ?? ''],
            ['Opportunity',          $rating['opportunity_title'] ?? ''],
            ['Overall Risk Rating',  ($rating['overall_rating_label'] ?? '') . ' (' . ($rating['overall_rating'] ?? '') . ')'],
            ['Recommendation',       $rating['recommendation'] ?? ''],
        ];

        $row = 2;
        foreach ($summaryFields as [$label, $value]) {
            $ws->setCellValue('A' . $row, $label);
            $ws->mergeCells('B' . $row . ':G' . $row);
            $ws->setCellValue('B' . $row, (string)$value);
            $ws->getStyle('A' . $row)->applyFromArray($styleLabelCell);
            $ws->getStyle('B' . $row . ':G' . $row)->applyFromArray($styleCell);
            $row++;
        }

        $row++;

        $narrativeFields = [
            'Summary of Findings and Recommendation' => $rating['executive_summary'] ?? '',
            'Introduction'                           => $rating['introduction_notes'] ?? '',
            'Next Steps'                             => $rating['next_steps'] ?? '',
        ];

        foreach ($narrativeFields as $label => $value) {
            $ws->mergeCells('A' . $row . ':G' . $row);
            $ws->setCellValue('A' . $row, $label);
            $ws->getStyle('A' . $row)->applyFromArray($styleSectionTitle);
            $row++;

            $ws->mergeCells('A' . $row . ':G' . $row);
            $ws->setCellValue('A' . $row, (string)$value);
            $ws->getStyle('A' . $row)->applyFromArray($styleCell);
            $ws->getRowDimension($row)->setRowHeight(60);
            $row += 2;
        }

        $ws->getColumnDimension('A')->setWidth(28);
        foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $ws->getColumnDimension($col)->setWidth(22);
        }

        $ws2 = $spreadsheet->createSheet();
        $ws2->setTitle('Risk Sections');

        $ws2->mergeCells('A1:G1');
        $ws2->setCellValue('A1', 'RISK RATING � PILLAR ASSESSMENTS');
        $ws2->getStyle('A1')->applyFromArray($styleHeader);

        $row2 = 2;
        $ws2->mergeCells('A' . $row2 . ':G' . $row2);
        $ws2->setCellValue('A' . $row2, 'Risk Rating Definitions');
        $ws2->getStyle('A' . $row2)->applyFromArray($styleSectionTitle);
        $row2++;

        $defHeaders = ['Rate Definition', 'Score', 'Description'];
        $defData = [
            ['Minor', 1, 'Issue/risk with no or minor effect on objectives or implementation effectiveness.'],
            ['Moderate', 2, 'Issue/risk with moderate effect on objectives or implementation effectiveness.'],
            ['Major', 3, 'Issue/risk with major effect on objectives or significant risk to the program.'],
            ['Severe', 5, 'Issue/risk with severe effect on objectives or severe risk to the program.'],
        ];

        foreach ($defHeaders as $idx => $hdr) {
            $col = chr(65 + $idx);
            $ws2->setCellValue($col . $row2, $hdr);
            $ws2->getStyle($col . $row2)->applyFromArray($styleSubHeader);
        }
        $row2++;

        foreach ($defData as $def) {
            $ws2->setCellValue('A' . $row2, $def[0]);
            $ws2->setCellValue('B' . $row2, $def[1]);
            $ws2->mergeCells('C' . $row2 . ':G' . $row2);
            $ws2->setCellValue('C' . $row2, $def[2]);
            $ws2->getStyle('A' . $row2 . ':G' . $row2)->applyFromArray($styleCell);
            $row2++;
        }

        $row2++;

        foreach ($sections as $index => $sec) {
            $ws2->mergeCells('A' . $row2 . ':G' . $row2);
            $ws2->setCellValue('A' . $row2, ($index + 1) . '. ' . strtoupper((string)($sec['pillar_label'] ?? '')));
            $ws2->getStyle('A' . $row2)->applyFromArray($styleSectionTitle);
            $row2++;

            $ws2->setCellValue('A' . $row2, 'Risk Score');
            $ws2->setCellValue('B' . $row2, $sec['rating_score'] ?? '');
            $ws2->setCellValue('D' . $row2, 'Risk Label');
            $ws2->setCellValue('E' . $row2, $sec['rating_label'] ?? '');
            $ws2->getStyle('A' . $row2)->applyFromArray($styleLabelCell);
            $ws2->getStyle('B' . $row2)->applyFromArray($styleCenter);
            $ws2->getStyle('D' . $row2)->applyFromArray($styleLabelCell);
            $ws2->getStyle('E' . $row2)->applyFromArray($styleCell);
            $row2++;

            $pillarFields = [
                'Key Findings'      => $sec['key_findings'] ?? '',
                'Recommendations'   => $sec['recommendations'] ?? '',
                'Follow Up Actions' => $sec['follow_up_actions'] ?? '',
                'Notes'             => $sec['notes'] ?? '',
            ];

            foreach ($pillarFields as $fieldLabel => $fieldValue) {
                $ws2->setCellValue('A' . $row2, $fieldLabel);
                $ws2->mergeCells('B' . $row2 . ':G' . $row2);
                $ws2->setCellValue('B' . $row2, (string)$fieldValue);
                $ws2->getStyle('A' . $row2)->applyFromArray($styleLabelCell);
                $ws2->getStyle('B' . $row2 . ':G' . $row2)->applyFromArray($styleCell);
                $ws2->getRowDimension($row2)->setRowHeight(50);
                $row2++;
            }

            $row2++;
        }

        $ws2->getColumnDimension('A')->setWidth(22);
        $ws2->getColumnDimension('B')->setWidth(14);
        $ws2->getColumnDimension('C')->setWidth(14);
        $ws2->getColumnDimension('D')->setWidth(16);
        foreach (['E', 'F', 'G'] as $col) {
            $ws2->getColumnDimension($col)->setWidth(24);
        }

        $ws3 = $spreadsheet->createSheet();
        $ws3->setTitle('Documents Checklist');

        $ws3->mergeCells('A1:E1');
        $ws3->setCellValue('A1', 'POLICIES AND DOCUMENTS VERIFIED');
        $ws3->getStyle('A1')->applyFromArray($styleHeader);

        $checkHeaders = ['Code', 'Description', 'YES', 'NO', 'Remarks'];
        $checkColWidths = [8, 45, 8, 8, 35];
        $checkCols = ['A', 'B', 'C', 'D', 'E'];

        foreach ($checkHeaders as $idx => $hdr) {
            $col = $checkCols[$idx];
            $ws3->setCellValue($col . '2', $hdr);
            $ws3->getStyle($col . '2')->applyFromArray($styleSubHeader);
            $ws3->getColumnDimension($col)->setWidth($checkColWidths[$idx]);
        }

        $row3 = 3;
        foreach ($checks as $i => $c) {
            [$isYes, $isNo] = $this->normalizeCheckState($c);

            $bg = ($i % 2 === 0) ? 'FFFFFF' : $altRowBg;
            $rowStyle = array_merge($styleCell, [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            ]);

            $ws3->setCellValue('A' . $row3, (string)($c['item_code'] ?? ''));
            $ws3->setCellValue('B' . $row3, (string)($c['item_label'] ?? ''));
            $ws3->setCellValue('C' . $row3, $isYes ? 'YES' : '');
            $ws3->setCellValue('D' . $row3, $isNo ? 'NO' : '');
            $ws3->setCellValue('E' . $row3, (string)($c['remarks'] ?? ''));

            $ws3->getStyle('A' . $row3 . ':E' . $row3)->applyFromArray($rowStyle);
            $ws3->getStyle('C' . $row3 . ':D' . $row3)->applyFromArray($styleCenter);
            $row3++;
        }

        if (!empty($criteriaScores)) {
            $ws4 = $spreadsheet->createSheet();
            $ws4->setTitle('Criteria Scoring');

            $ws4->mergeCells('A1:G1');
            $ws4->setCellValue('A1', 'DETAILED CRITERIA SCORING');
            $ws4->getStyle('A1')->applyFromArray($styleHeader);

            $csHeaders = ['Review Type', 'Criteria', 'Max', 'Weight', 'Awarded', 'Weighted', 'Comments'];
            $csWidths  = [20, 40, 8, 8, 10, 10, 30];
            $csCols    = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

            foreach ($csHeaders as $idx => $hdr) {
                $col = $csCols[$idx];
                $ws4->setCellValue($col . '2', $hdr);
                $ws4->getStyle($col . '2')->applyFromArray($styleSubHeader);
                $ws4->getColumnDimension($col)->setWidth($csWidths[$idx]);
            }

            $row4 = 3;
            $grandWeighted = 0.0;

            foreach ($criteriaScores as $i => $cs) {
                $bg = ($i % 2 === 0) ? 'FFFFFF' : $altRowBg;
                $rowStyle = array_merge($styleCell, [
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                ]);

                $ws4->setCellValue('A' . $row4, (string)($cs['review_type_name'] ?? ''));
                $ws4->setCellValue('B' . $row4, (string)($cs['criteria_question'] ?? ''));
                $ws4->setCellValue('C' . $row4, $cs['max_score'] ?? '');
                $ws4->setCellValue('D' . $row4, $cs['weight'] ?? '');
                $ws4->setCellValue('E' . $row4, $cs['awarded_score'] ?? '');
                $ws4->setCellValue('F' . $row4, $cs['weighted_score'] ?? '');
                $ws4->setCellValue('G' . $row4, (string)($cs['comments'] ?? ''));

                $ws4->getStyle('A' . $row4 . ':G' . $row4)->applyFromArray($rowStyle);
                $ws4->getStyle('C' . $row4 . ':F' . $row4)->applyFromArray($styleRight);

                $grandWeighted += (float)($cs['weighted_score'] ?? 0);
                $row4++;
            }

            $ws4->mergeCells('A' . $row4 . ':E' . $row4);
            $ws4->setCellValue('A' . $row4, 'Total Weighted Score');
            $ws4->setCellValue('F' . $row4, round($grandWeighted, 2));
            $ws4->getStyle('A' . $row4 . ':G' . $row4)->applyFromArray($styleSubHeader);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $dir = __DIR__ . '/../uploads/risk_reports/';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('Failed to create report directory.');
        }

        $safeName = preg_replace(
            '/[^A-Za-z0-9_\-]/',
            '_',
            (string)($rating['startup_name'] ?: ('application_' . $rating['application_id']))
        );

        $fileName     = 'Risk_Rating_Report_' . $safeName . '_' . date('Ymd_His') . '.xlsx';
        $absolutePath = $dir . $fileName;
        $publicPath   = 'uploads/risk_reports/' . $fileName;

        $writer = new Xlsx($spreadsheet);
        $writer->save($absolutePath);

        return [
            'name'         => $fileName,
            'path'         => $publicPath,
            'generated_by' => $generatedBy,
        ];
    }

    public function generatePdf(int $riskRatingId, int $generatedBy): array
    {
        $data = $this->getReportData($riskRatingId);
        $rating = $data['rating'];
        $sections = $data['sections'];
        $checks = $data['checks'];
        $criteriaScores = $data['criteriaScores'];

        $logoPath = __DIR__ . '/../assets/img/logo.png';
        $logoHtml = '';
        if (file_exists($logoPath)) {
            $logoData = base64_encode((string)file_get_contents($logoPath));
            $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" style="height:70px;">';
        }

        $sectionsHtml = '';
        foreach ($sections as $index => $sec) {
            $sectionsHtml .= '
                <div class="section-block">
                    <h2>' . ($index + 1) . '. ' . strtoupper($this->e($sec['pillar_label'] ?? '')) . '</h2>
                    <div class="risk-badge">
                        Risk Score: <strong>' . $this->e($sec['rating_score'] ?? '') . '</strong> |
                        Rating: <strong>' . $this->e($sec['rating_label'] ?? '') . '</strong>
                    </div>

                    <h3>Key Findings</h3>
                    <div class="text-block">' . $this->nl($sec['key_findings'] ?? '') . '</div>

                    <h3>Recommendations</h3>
                    <div class="text-block">' . $this->nl($sec['recommendations'] ?? '') . '</div>

                    <h3>Follow Up Actions</h3>
                    <div class="text-block">' . $this->nl($sec['follow_up_actions'] ?? '') . '</div>

                    <h3>Notes</h3>
                    <div class="text-block">' . $this->nl($sec['notes'] ?? '') . '</div>
                </div>
            ';
        }

        $checksRowsHtml = '';
        foreach ($checks as $c) {
            [$isYes, $isNo] = $this->normalizeCheckState($c);

            $yesIcon = $isYes ? '<span style="color:#15803d;font-weight:bold;">YES</span>' : '';
            $noIcon  = $isNo  ? '<span style="color:#dc2626;font-weight:bold;">NO</span>' : '';

            $checksRowsHtml .= '
                <tr>
                    <td>' . $this->e($c['item_code'] ?? '') . '</td>
                    <td>' . $this->e($c['item_label'] ?? '') . '</td>
                    <td class="center">' . $yesIcon . '</td>
                    <td class="center">' . $noIcon . '</td>
                    <td>' . $this->e($c['remarks'] ?? '') . '</td>
                </tr>
            ';
        }

        $criteriaHtml = '';
        if (!empty($criteriaScores)) {
            $criteriaRows = '';
            foreach ($criteriaScores as $cs) {
                $criteriaRows .= '
                    <tr>
                        <td>' . $this->e($cs['review_type_name'] ?? '') . '</td>
                        <td>' . $this->e($cs['criteria_question'] ?? '') . '</td>
                        <td class="right">' . $this->e($cs['max_score'] ?? '') . '</td>
                        <td class="right">' . $this->e($cs['weight'] ?? '') . '</td>
                        <td class="right">' . $this->e($cs['awarded_score'] ?? '') . '</td>
                        <td class="right">' . $this->e($cs['weighted_score'] ?? '') . '</td>
                        <td>' . $this->e($cs['comments'] ?? '') . '</td>
                    </tr>
                ';
            }

            $criteriaHtml = '
                <div class="section-block">
                    <h2>Detailed Criteria Scoring</h2>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Review Type</th>
                                <th>Criteria</th>
                                <th>Max</th>
                                <th>Weight</th>
                                <th>Awarded</th>
                                <th>Weighted</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>' . $criteriaRows . '</tbody>
                    </table>
                </div>
            ';
        }

        $prepName    = (string)($rating['prepared_by_name'] ?? '');
        $prepSig     = (string)($rating['prepared_by_signature'] ?? '');
        $revName     = (string)($rating['reviewed_by_name'] ?? '');
        $revSig      = (string)($rating['reviewed_by_signature'] ?? '');
        $revComments = (string)($rating['reviewer_comments'] ?? '');
        $appName     = (string)($rating['approved_by_name'] ?? '');
        $appSig      = (string)($rating['approved_by_signature'] ?? '');

        $renderSigCell = function (string $role, string $name, string $sigDataUrl): string {
            // Signatures saved as files (uploads/risk_rating_signatures/...) are
            // not web-readable; embed them from disk so the PDF never needs a URL.
            if ($sigDataUrl !== '' && !str_starts_with($sigDataUrl, 'data:')) {
                $relative = ltrim(str_replace('\\', '/', $sigDataUrl), '/');
                $sigDataUrl = '';
                $base = realpath(__DIR__ . '/../uploads');
                $file = (!str_contains($relative, '..') && str_starts_with($relative, 'uploads/'))
                    ? realpath(__DIR__ . '/../' . $relative)
                    : false;

                if ($base !== false && $file !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
                    $mime = function_exists('mime_content_type') ? (string)mime_content_type($file) : 'image/png';

                    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                        $sigDataUrl = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($file));
                    }
                }
            }

            $nameHtml = $name !== ''
                ? $this->e($name)
                : '__________________________';

            $sigBlock = $sigDataUrl !== ''
                ? '<img src="' . $this->e($sigDataUrl) . '" style="height:52px;max-width:200px;display:block;margin-top:8px;border:1px solid #eee;padding:2px;">'
                : '<div style="margin-top:24px;border-top:1px solid #444;width:80%;"></div>
                   <span style="font-size:10px;color:#555;">Signature</span>';

            return '
                <td>
                    <strong>' . $this->e($role) . '</strong><br><br>
                    Name: <strong>' . $nameHtml . '</strong>
                    ' . $sigBlock . '
                </td>';
        };

        $signatureBlockHtml = '
            <div class="section-block">
                <h2>Prepared / Reviewed / Approved</h2>
                <table class="signature-grid">
                    <tr>
                        ' . $renderSigCell('Prepared by', $prepName, $prepSig) . '
                        ' . $renderSigCell('Reviewed by', $revName, $revSig) . '
                    </tr>
                    <tr>
                        <td>
                            <strong>Reviewer Comments</strong>
                            <div class="text-block" style="min-height:60px;">' . $this->nl($revComments) . '</div>
                        </td>
                        ' . $renderSigCell('Approved by ED', $appName, $appSig) . '
                    </tr>
                </table>
            </div>
        ';

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Risk Rating Report</title>
            <style>
                @page { margin: 28px 24px; }
                body {
                    font-family: DejaVu Sans, sans-serif;
                    font-size: 11px;
                    color: #222;
                    line-height: 1.45;
                }
                .header {
                    text-align: center;
                    border-bottom: 2px solid #1f3b64;
                    padding-bottom: 10px;
                    margin-bottom: 18px;
                }
                .header h1 {
                    font-size: 20px;
                    margin: 6px 0;
                    color: #1f3b64;
                }
                .header h2 {
                    font-size: 12px;
                    margin: 0;
                    color: #555;
                    font-weight: normal;
                }
                .summary-box {
                    border: 1px solid #bbb;
                    margin-bottom: 18px;
                }
                .summary-box table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .summary-box td {
                    border: 1px solid #bbb;
                    padding: 8px;
                    vertical-align: top;
                }
                .label {
                    width: 180px;
                    font-weight: bold;
                    background: #f4f6f8;
                }
                h2 {
                    font-size: 14px;
                    color: #1f3b64;
                    border-bottom: 1px solid #d0d7de;
                    padding-bottom: 4px;
                    margin-top: 18px;
                    margin-bottom: 8px;
                }
                h3 {
                    font-size: 12px;
                    margin: 10px 0 5px;
                    color: #333;
                }
                .text-block {
                    border: 1px solid #ddd;
                    padding: 8px;
                    min-height: 28px;
                    background: #fff;
                }
                .section-block {
                    margin-bottom: 16px;
                    page-break-inside: avoid;
                }
                .risk-badge {
                    background: #eef4ff;
                    border: 1px solid #c7d8f6;
                    padding: 7px 9px;
                    margin-bottom: 8px;
                }
                .report-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 8px;
                }
                .report-table th, .report-table td {
                    border: 1px solid #bdbdbd;
                    padding: 6px;
                    font-size: 10px;
                    vertical-align: top;
                }
                .report-table th {
                    background: #f0f0f0;
                    font-weight: bold;
                    text-align: left;
                }
                .center { text-align: center; }
                .right { text-align: right; }
                .signature-grid {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 12px;
                }
                .signature-grid td {
                    width: 50%;
                    padding: 8px 10px 20px 0;
                    vertical-align: top;
                }
            </style>
        </head>
        <body>

            <div class="header">
                ' . $logoHtml . '
                <h1>DUE DILIGENCE REPORT</h1>
                <h2>MASTERCARD FOUNDATION EDTECH FELLOWSHIP IN UGANDA</h2>
            </div>

            <div class="summary-box">
                <table>
                    <tr>
                        <td class="label">Name of Entrepreneur</td>
                        <td>' . $this->e($rating['founder_names'] ?: ($rating['contact_person'] ?? '')) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Contact</td>
                        <td>' . $this->e($rating['phone'] ?? '') . '</td>
                    </tr>
                    <tr>
                        <td class="label">EdTech Enterprise</td>
                        <td>' . $this->e($rating['startup_name'] ?? '') . '</td>
                    </tr>
                    <tr>
                        <td class="label">Email</td>
                        <td>' . $this->e($rating['email'] ?? '') . '</td>
                    </tr>
                    <tr>
                        <td class="label">Opportunity</td>
                        <td>' . $this->e($rating['opportunity_title'] ?? '') . '</td>
                    </tr>
                    <tr>
                        <td class="label">Overall Risk Rating</td>
                        <td>' . $this->e($rating['overall_rating_label'] ?? '') . ' (' . $this->e($rating['overall_rating'] ?? '') . ')</td>
                    </tr>
                    <tr>
                        <td class="label">Recommendation</td>
                        <td>' . $this->e($rating['recommendation'] ?? '') . '</td>
                    </tr>
                </table>
            </div>

            <h2>Summary of Findings and Recommendation</h2>
            <div class="text-block">' . $this->nl($rating['executive_summary'] ?? '') . '</div>

            <h2>Introduction</h2>
            <div class="text-block">' . $this->nl($rating['introduction_notes'] ?? '') . '</div>

            <h2>Risk Rating Definitions</h2>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Rate Definition</th>
                        <th>Score</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Minor</td><td>1</td><td>Issue/risk with no or minor effect on objectives or implementation effectiveness.</td></tr>
                    <tr><td>Moderate</td><td>2</td><td>Issue/risk with moderate effect on objectives or implementation effectiveness.</td></tr>
                    <tr><td>Major</td><td>3</td><td>Issue/risk with major effect on objectives or significant risk to the program.</td></tr>
                    <tr><td>Severe</td><td>5</td><td>Issue/risk with severe effect on objectives or severe risk to the program.</td></tr>
                </tbody>
            </table>

            <h2>Next Steps</h2>
            <div class="text-block">' . $this->nl($rating['next_steps'] ?? '') . '</div>

            ' . $sectionsHtml . '

            <div class="section-block">
                <h2>Policies and Documents Verified</h2>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th style="width:8%;">Code</th>
                            <th style="width:45%;">Description</th>
                            <th style="width:8%;">YES</th>
                            <th style="width:8%;">NO</th>
                            <th style="width:31%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>' . $checksRowsHtml . '</tbody>
                </table>
            </div>

            ' . $criteriaHtml . '
            ' . $signatureBlockHtml . '

        </body>
        </html>';

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
require_once __DIR__ . '/../includes/pdf-branding.php';
ims_brand_pdf($dompdf);

        $dir = __DIR__ . '/../uploads/risk_reports/';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('Failed to create report directory.');
        }

        $safeName = preg_replace(
            '/[^A-Za-z0-9_\-]/',
            '_',
            (string)($rating['startup_name'] ?: ('application_' . $rating['application_id']))
        );

        $fileName = 'Risk_Rating_Report_' . $safeName . '_' . date('Ymd_His') . '.pdf';
        $absolutePath = $dir . $fileName;
        $publicPath = 'uploads/risk_reports/' . $fileName;

        file_put_contents($absolutePath, $dompdf->output());

        return [
            'name' => $fileName,
            'path' => $publicPath,
            'generated_by' => $generatedBy,
        ];
    }
}