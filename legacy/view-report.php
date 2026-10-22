<?php
declare(strict_types=1);

/*
 * view-report.php
 * Fixed version: no broken if/elseif/endif chain.
 */

$pdf_mode = defined('REPORT_PDF_RENDER') || !empty($_GET['pdf_render']);

if (!$pdf_mode) {
    $page_title = 'View Report';
    require_once __DIR__ . '/includes/header.php';
    check_role([
        'Administrator',
        'Programs Lead',
        'MEAL Lead',
        'Operations/Admin',
        'Donors/Partners',
        'Project Officer'
    ]);
} else {
    if (!defined('REPORT_PDF_RENDER')) {
        // ?pdf_render=1 requested directly over HTTP: same access rules as
        // the normal page (previously this branch skipped authentication).
        require_once __DIR__ . '/includes/config.php';
        check_role([
            'Administrator',
            'Programs Lead',
            'MEAL Lead',
            'Operations/Admin',
            'Donors/Partners',
            'Project Officer'
        ]);
    }
}

if (!function_exists('h')) {
    function h(mixed $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('safe_money')) {
    function safe_money(mixed $amount, string $currency = 'UGX'): string {
        if (function_exists('format_currency')) {
            return format_currency((float)$amount, $currency);
        }
        return $currency . ' ' . number_format((float)$amount, 0);
    }
}

if (!function_exists('ach_color')) {
    function ach_color(float $pct): string {
        if ($pct >= 100) return '#15803d';
        if ($pct >= 50) return '#b45309';
        return '#b91c1c';
    }
}

if (!function_exists('prog_class')) {
    function prog_class(float $pct): string {
        if ($pct >= 75) return 'high';
        if ($pct >= 50) return 'mid';
        return 'low';
    }
}

if (!function_exists('status_badge_cls')) {
    function status_badge_cls(string $status): string {
        return match (strtolower(trim($status))) {
            'active', 'ongoing', 'completed', 'attended' => 'b-green',
            'pending', 'in progress', 'registered'      => 'b-amber',
            'cancelled', 'delayed', 'rejected'           => 'b-red',
            default                                      => 'b-slate',
        };
    }
}

if (!function_exists('web_badge_cls')) {
    function web_badge_cls(string $status): string {
        return match (strtolower(trim($status))) {
            'active', 'ongoing'     => 'badge-available',
            'completed'             => 'badge-assigned',
            'pending', 'in progress'=> 'badge-pending',
            'cancelled', 'rejected' => 'badge-rejected',
            default                 => 'badge-returned',
        };
    }
}

$report_type = isset($_GET['type']) ? sanitize_input($_GET['type']) : '';
$start_date  = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-01');
$end_date    = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');

// Dates are interpolated into SQL below: accept strict YYYY-MM-DD only.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}
$project_id  = !empty($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$program_id  = !empty($_GET['program_id']) ? (int)$_GET['program_id'] : null;

$valid_types = [
    'program_summary',
    'project_summary',
    'participant_report',
    'indicator_performance',
    'events_report',
    'hub_operations',
    'donor_report',
    'pwd_report',
    'quarterly_report',
    'annual_report',
];

if (!$report_type || !in_array($report_type, $valid_types, true)) {
    if (!$pdf_mode) {
        header('Location: generate-report');
        exit;
    }

    echo '<p>Invalid report type.</p>';
    return;
}

$sd = $start_date;
$ed = $end_date;

/*
 * All report filters are bound as prepared-statement parameters.
 * SQL below uses named markers (:sd, :ed, :program_id, :project_id) that
 * vr_query() turns into "?" placeholders, binding values in order.
 */
$vr_params = [
    'sd'         => $start_date,
    'ed'         => $end_date,
    'program_id' => (int)($program_id ?? 0),
    'project_id' => (int)($project_id ?? 0),
];

if (!function_exists('vr_query')) {
    function vr_query(mysqli $conn, string $sql, array $params): ?mysqli_result
    {
        $types = '';
        $values = [];

        $sql = (string)preg_replace_callback(
            '/:(sd|ed|program_id|project_id)\b/',
            static function (array $m) use ($params, &$types, &$values): string {
                $value = $params[$m[1]];
                $types .= is_int($value) ? 'i' : 's';
                $values[] = $value;
                return '?';
            },
            $sql
        );

        try {
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                return null;
            }

            if ($values) {
                $stmt->bind_param($types, ...$values);
            }

            if (!$stmt->execute()) {
                return null;
            }

            $result = $stmt->get_result();

            return $result instanceof mysqli_result ? $result : null;
        } catch (Throwable $e) {
            error_log('view-report query failed: ' . $e->getMessage());
            return null;
        }
    }
}

$context_name = 'Organisation-wide';
$context_type = 'All';

if ($program_id) {
    $res = vr_query($conn, "SELECT program_name, program_code FROM programs WHERE id = :program_id LIMIT 1", $vr_params);
    if ($res && $row = $res->fetch_assoc()) {
        $context_name = h(($row['program_code'] ?? '') . ' — ' . ($row['program_name'] ?? ''));
        $context_type = 'Program';
    }
} elseif ($project_id) {
    $res = vr_query($conn, "SELECT project_name, project_code FROM projects WHERE project_id = :project_id LIMIT 1", $vr_params);
    if ($res && $row = $res->fetch_assoc()) {
        $context_name = h(($row['project_code'] ?? '') . ' — ' . ($row['project_name'] ?? ''));
        $context_type = 'Project';
    }
}

$report_labels = [
    'program_summary'       => 'Program Summary Report',
    'project_summary'       => 'Project Summary Report',
    'participant_report'    => 'Participants Analysis Report',
    'indicator_performance' => 'Indicator Performance Report',
    'events_report'         => 'Events & Training Report',
    'hub_operations'        => 'Hub Operations Report',
    'donor_report'          => 'Donor Report',
    'pwd_report'            => 'Persons with Disabilities Report',
    'quarterly_report'      => 'Quarterly Performance Report',
    'annual_report'         => 'Annual Report',
];

$report_title = $report_labels[$report_type] ?? 'Custom Report';
$data = [];

/* =========================
   DATA FETCHING
========================= */

switch ($report_type) {
    case 'program_summary':
        $w = $program_id ? "AND p.id = :program_id" : '';

        $res = vr_query($conn, "
            SELECT 
                p.*,
                d.donor_name,
                (SELECT COUNT(*) FROM programs_beneficiaries pb WHERE pb.program_id = p.id) AS beneficiary_count,
                (SELECT COUNT(*) FROM indicators i WHERE i.program_id = p.id) AS indicator_count,
                (SELECT COUNT(*) FROM documents doc WHERE doc.program_id = p.id) AS document_count,
                (
                    SELECT AVG(
                        CASE 
                            WHEN i.target_value > 0 
                            THEN i.current_value / i.target_value * 100 
                            ELSE 0 
                        END
                    )
                    FROM indicators i 
                    WHERE i.program_id = p.id
                ) AS avg_achievement
            FROM programs p
            LEFT JOIN donors d ON d.donor_id = p.donor_id
            WHERE p.start_date <= :ed
              AND (p.end_date >= :sd OR p.end_date IS NULL)
              $w
            ORDER BY p.status, p.program_name
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['programs'][] = $row;
        }
        break;

    case 'project_summary':
        $w = $project_id ? "AND p.project_id = :project_id" : '';

        $res = vr_query($conn, "
            SELECT 
                p.*,
                d.donor_name,
                (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.project_id = p.project_id) AS beneficiary_count,
                (SELECT COUNT(*) FROM indicators i WHERE i.project_id = p.project_id) AS indicator_count,
                (SELECT COUNT(*) FROM documents doc WHERE doc.project_id = p.project_id) AS document_count,
                (
                    SELECT AVG(
                        CASE 
                            WHEN i.target_value > 0 
                            THEN i.current_value / i.target_value * 100 
                            ELSE 0 
                        END
                    )
                    FROM indicators i 
                    WHERE i.project_id = p.project_id
                ) AS avg_achievement
            FROM projects p
            LEFT JOIN donors d ON d.donor_id = p.donor_id
            WHERE p.start_date <= :ed
              AND p.end_date >= :sd
              $w
            ORDER BY p.status, p.project_name
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['projects'][] = $row;
        }
        break;

    case 'participant_report':
        if ($program_id) {
            $q = "
                SELECT 
                    b.*,
                    pr.program_name,
                    pr.program_code,
                    NULL AS project_name,
                    NULL AS project_code,
                    prb.enrollment_date,
                    prb.participation_type,
                    prb.status AS enrollment_status,
                    'Program' AS context_type
                FROM beneficiaries b
                INNER JOIN programs_beneficiaries prb ON prb.beneficiary_id = b.beneficiary_id
                INNER JOIN programs pr ON pr.id = prb.program_id
                WHERE prb.program_id = :program_id
                  AND prb.enrollment_date BETWEEN :sd AND :ed
                ORDER BY prb.enrollment_date DESC
            ";
        } elseif ($project_id) {
            $q = "
                SELECT 
                    b.*,
                    NULL AS program_name,
                    NULL AS program_code,
                    p.project_name,
                    p.project_code,
                    pb.enrollment_date,
                    pb.participation_type,
                    pb.status AS enrollment_status,
                    'Project' AS context_type
                FROM beneficiaries b
                INNER JOIN project_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                INNER JOIN projects p ON p.project_id = pb.project_id
                WHERE pb.project_id = :project_id
                  AND pb.enrollment_date BETWEEN :sd AND :ed
                ORDER BY pb.enrollment_date DESC
            ";
        } else {
            $q = "
                SELECT 
                    b.*,
                    pr.program_name,
                    pr.program_code,
                    NULL AS project_name,
                    NULL AS project_code,
                    prb.enrollment_date,
                    prb.participation_type,
                    prb.status AS enrollment_status,
                    'Program' AS context_type
                FROM beneficiaries b
                INNER JOIN programs_beneficiaries prb ON prb.beneficiary_id = b.beneficiary_id
                INNER JOIN programs pr ON pr.id = prb.program_id
                WHERE prb.enrollment_date BETWEEN :sd AND :ed

                UNION

                SELECT 
                    b.*,
                    NULL AS program_name,
                    NULL AS program_code,
                    p.project_name,
                    p.project_code,
                    pb.enrollment_date,
                    pb.participation_type,
                    pb.status AS enrollment_status,
                    'Project' AS context_type
                FROM beneficiaries b
                INNER JOIN project_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                INNER JOIN projects p ON p.project_id = pb.project_id
                WHERE pb.enrollment_date BETWEEN :sd AND :ed

                ORDER BY enrollment_date DESC
            ";
        }

        $res = vr_query($conn, $q, $vr_params);
        while ($row = $res?->fetch_assoc()) {
            $data['participants'][] = $row;
        }

        $gq = $program_id
            ? "
                SELECT b.gender, COUNT(DISTINCT b.beneficiary_id) AS cnt
                FROM beneficiaries b
                INNER JOIN programs_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                WHERE pb.program_id = :program_id
                  AND pb.enrollment_date BETWEEN :sd AND :ed
                GROUP BY b.gender
            "
            : ($project_id
                ? "
                    SELECT b.gender, COUNT(DISTINCT b.beneficiary_id) AS cnt
                    FROM beneficiaries b
                    INNER JOIN project_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                    WHERE pb.project_id = :project_id
                      AND pb.enrollment_date BETWEEN :sd AND :ed
                    GROUP BY b.gender
                "
                : "
                    SELECT gender, COUNT(DISTINCT beneficiary_id) AS cnt
                    FROM (
                        SELECT b.beneficiary_id, b.gender
                        FROM beneficiaries b
                        INNER JOIN programs_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                        WHERE pb.enrollment_date BETWEEN :sd AND :ed

                        UNION

                        SELECT b.beneficiary_id, b.gender
                        FROM beneficiaries b
                        INNER JOIN project_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                        WHERE pb.enrollment_date BETWEEN :sd AND :ed
                    ) x
                    GROUP BY gender
                "
            );

        $res = vr_query($conn, $gq, $vr_params);
        while ($row = $res?->fetch_assoc()) {
            $data['gender'][$row['gender'] ?: 'Not specified'] = (int)$row['cnt'];
        }

        $pq = $program_id
            ? "
                SELECT 
                    COALESCE(SUM(b.is_pwd), 0) AS pwd,
                    COUNT(DISTINCT b.beneficiary_id) AS total
                FROM beneficiaries b
                INNER JOIN programs_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                WHERE pb.program_id = :program_id
                  AND pb.enrollment_date BETWEEN :sd AND :ed
            "
            : ($project_id
                ? "
                    SELECT 
                        COALESCE(SUM(b.is_pwd), 0) AS pwd,
                        COUNT(DISTINCT b.beneficiary_id) AS total
                    FROM beneficiaries b
                    INNER JOIN project_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                    WHERE pb.project_id = :project_id
                      AND pb.enrollment_date BETWEEN :sd AND :ed
                "
                : "
                    SELECT 
                        COALESCE(SUM(is_pwd), 0) AS pwd,
                        COUNT(DISTINCT beneficiary_id) AS total
                    FROM (
                        SELECT DISTINCT b.beneficiary_id, b.is_pwd
                        FROM beneficiaries b
                        INNER JOIN programs_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                        WHERE pb.enrollment_date BETWEEN :sd AND :ed

                        UNION

                        SELECT DISTINCT b.beneficiary_id, b.is_pwd
                        FROM beneficiaries b
                        INNER JOIN project_beneficiaries pb ON pb.beneficiary_id = b.beneficiary_id
                        WHERE pb.enrollment_date BETWEEN :sd AND :ed
                    ) x
                "
            );

        $data['pwd'] = vr_query($conn, $pq, $vr_params)?->fetch_assoc() ?? ['pwd' => 0, 'total' => 0];
        break;

    case 'indicator_performance':
        $w = $program_id ? "i.program_id = :program_id" : ($project_id ? "i.project_id = :project_id" : "1=1");

        $res = vr_query($conn, "
            SELECT 
                i.*,
                CASE 
                    WHEN i.project_id IS NOT NULL THEN 'Project'
                    WHEN i.program_id IS NOT NULL THEN 'Program'
                    ELSE 'General'
                END AS context_type,
                CASE 
                    WHEN i.project_id IS NOT NULL THEN p.project_code
                    WHEN i.program_id IS NOT NULL THEN pr.program_code
                    ELSE 'N/A'
                END AS context_code,
                CASE 
                    WHEN i.project_id IS NOT NULL THEN p.project_name
                    WHEN i.program_id IS NOT NULL THEN pr.program_name
                    ELSE 'N/A'
                END AS context_name,
                CASE 
                    WHEN i.target_value > 0 
                    THEN ROUND(i.current_value / i.target_value * 100, 1)
                    ELSE 0
                END AS achievement_pct,
                (
                    SELECT COUNT(*) 
                    FROM indicator_progress ip 
                    WHERE ip.indicator_id = i.indicator_id
                      AND ip.recorded_date BETWEEN :sd AND :ed
                ) AS updates
            FROM indicators i
            LEFT JOIN projects p ON p.project_id = i.project_id
            LEFT JOIN programs pr ON pr.id = i.program_id
            WHERE $w
            ORDER BY context_type, context_name, i.indicator_type, i.indicator_name
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['indicators'][] = $row;
        }
        break;

    case 'events_report':
        $w = "e.event_date BETWEEN :sd AND :ed";
        if ($program_id) $w .= " AND e.program_id = :program_id";
        if ($project_id) $w .= " AND e.project_id = :project_id";

        $res = vr_query($conn, "
            SELECT 
                e.*,
                CASE 
                    WHEN e.project_id IS NOT NULL THEN 'Project'
                    WHEN e.program_id IS NOT NULL THEN 'Program'
                    ELSE 'General'
                END AS context_type,
                CASE 
                    WHEN e.project_id IS NOT NULL THEN p.project_code
                    WHEN e.program_id IS NOT NULL THEN pr.program_code
                    ELSE 'N/A'
                END AS context_code,
                (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.event_id) AS registered,
                (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.event_id AND er.attendance_status = 'Attended') AS attended,
                (SELECT AVG(er.feedback_score) FROM event_registrations er WHERE er.event_id = e.event_id AND er.feedback_score IS NOT NULL) AS avg_feedback
            FROM hub_events e
            LEFT JOIN projects p ON p.project_id = e.project_id
            LEFT JOIN programs pr ON pr.id = e.program_id
            WHERE $w
            ORDER BY e.event_date DESC
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['events'][] = $row;
        }
        break;

    case 'hub_operations':
        $data['visitor_stats'] = vr_query($conn, "
            SELECT COUNT(*) AS total, COUNT(DISTINCT visit_date) AS days 
            FROM hub_visitors 
            WHERE visit_date BETWEEN :sd AND :ed
        ", $vr_params)?->fetch_assoc() ?? ['total' => 0, 'days' => 0];

        $res = vr_query($conn, "
            SELECT purpose, COUNT(*) AS cnt 
            FROM hub_visitors 
            WHERE visit_date BETWEEN :sd AND :ed
            GROUP BY purpose 
            ORDER BY cnt DESC
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['purposes'][$row['purpose'] ?: 'Not specified'] = (int)$row['cnt'];
        }

        $res = vr_query($conn, "
            SELECT
                COALESCE(member_type, 'Unspecified') AS membership_type,
                COALESCE(membership_status, 'Pending') AS status,
                COUNT(*) AS cnt,
                SUM(COALESCE(subscription_amount, 0)) AS revenue
            FROM members
            WHERE COALESCE(subscription_start_date, registration_date) <= :ed
              AND (subscription_end_date >= :sd OR membership_status = 'Active')
            GROUP BY member_type, membership_status
            ORDER BY member_type, membership_status
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['memberships'][] = $row;
        }
        break;

    case 'donor_report':
        $res = vr_query($conn, "
            SELECT 
                d.donor_name,
                d.email AS donor_email,
                (SELECT COUNT(*) FROM programs p WHERE p.donor_id = d.donor_id AND p.start_date <= :ed AND (p.end_date >= :sd OR p.end_date IS NULL)) AS prog_count,
                (SELECT COUNT(*) FROM projects p WHERE p.donor_id = d.donor_id AND p.start_date <= :ed AND p.end_date >= :sd) AS proj_count,
                (SELECT SUM(budget) FROM programs p WHERE p.donor_id = d.donor_id AND p.start_date <= :ed AND (p.end_date >= :sd OR p.end_date IS NULL)) AS prog_budget,
                (SELECT SUM(budget) FROM projects p WHERE p.donor_id = d.donor_id AND p.start_date <= :ed AND p.end_date >= :sd) AS proj_budget,
                (SELECT COUNT(DISTINCT pb.beneficiary_id) FROM programs pr INNER JOIN programs_beneficiaries pb ON pb.program_id = pr.id WHERE pr.donor_id = d.donor_id) AS prog_bens,
                (SELECT COUNT(DISTINCT pb.beneficiary_id) FROM projects pr INNER JOIN project_beneficiaries pb ON pb.project_id = pr.project_id WHERE pr.donor_id = d.donor_id) AS proj_bens
            FROM donors d
            HAVING (prog_count + proj_count) > 0
            ORDER BY (COALESCE(prog_budget, 0) + COALESCE(proj_budget, 0)) DESC
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['donors'][] = $row;
        }
        break;

    case 'pwd_report':
        $res = vr_query($conn, "
            SELECT 
                b.*,
                (SELECT COUNT(*) FROM programs_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id) AS prog_count,
                (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id) AS proj_count,
                (SELECT COUNT(*) FROM event_registrations er WHERE er.beneficiary_id = b.beneficiary_id) AS event_count
            FROM beneficiaries b
            WHERE b.is_pwd = 1
              AND DATE(b.created_at) BETWEEN :sd AND :ed
            ORDER BY b.last_name, b.first_name
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['pwd_list'][] = $row;
        }

        $res = vr_query($conn, "
            SELECT pwd_type, COUNT(*) AS cnt
            FROM beneficiaries
            WHERE is_pwd = 1
              AND pwd_type IS NOT NULL
              AND DATE(created_at) BETWEEN :sd AND :ed
            GROUP BY pwd_type
            ORDER BY cnt DESC
        ", $vr_params);

        while ($row = $res?->fetch_assoc()) {
            $data['pwd_types'][] = $row;
        }
        break;

    case 'quarterly_report':
    case 'annual_report':
        $res = vr_query($conn, "SELECT status, COUNT(*) AS cnt FROM programs GROUP BY status", $vr_params);
        while ($row = $res?->fetch_assoc()) {
            $data['prog_summary'][$row['status'] ?: 'Not specified'] = (int)$row['cnt'];
        }

        $res = vr_query($conn, "SELECT status, COUNT(*) AS cnt FROM projects GROUP BY status", $vr_params);
        while ($row = $res?->fetch_assoc()) {
            $data['proj_summary'][$row['status'] ?: 'Not specified'] = (int)$row['cnt'];
        }

        $res = vr_query($conn, "SELECT gender, COUNT(*) AS cnt FROM beneficiaries GROUP BY gender", $vr_params);
        while ($row = $res?->fetch_assoc()) {
            $data['gender'][$row['gender'] ?: 'Not specified'] = (int)$row['cnt'];
        }

        $data['ind_summary'] = vr_query($conn, "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN target_value > 0 AND (current_value / target_value * 100) >= 100 THEN 1 ELSE 0 END) AS achieved,
                SUM(CASE WHEN target_value > 0 AND (current_value / target_value * 100) BETWEEN 50 AND 99.999 THEN 1 ELSE 0 END) AS partial,
                SUM(CASE WHEN target_value > 0 AND (current_value / target_value * 100) < 50 THEN 1 ELSE 0 END) AS low
            FROM indicators
            WHERE target_value > 0
        ", $vr_params)?->fetch_assoc() ?? [];

        $data['events_summary'] = vr_query($conn, "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN event_date BETWEEN :sd AND :ed THEN 1 ELSE 0 END) AS period
            FROM hub_events
        ", $vr_params)?->fetch_assoc() ?? [];
        break;
}

if (!$pdf_mode && isset($_SESSION['user_id']) && function_exists('log_action')) {
    log_action((int)$_SESSION['user_id'], 'View Report', 'reports', null, "Viewed {$report_type}: {$sd} to {$ed}");
}

/* =========================
   PDF MODE
========================= */

if ($pdf_mode):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($report_title) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:12px;color:#1f2937;background:#fff;line-height:1.5;padding:22px}
h1{font-size:21px;color:#fff}
.cover{background:#ff6b35;color:#fff;padding:20px;border-radius:6px;margin-bottom:18px}
.meta-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.meta-pill{background:rgba(255,255,255,.15);padding:5px 9px;border-radius:4px;font-size:10px}
.stats-row{display:table;width:100%;border-spacing:8px 0;margin-bottom:16px}
.stat-c{display:table-cell;background:#f3f4f6;border:1px solid #e5e7eb;padding:10px;text-align:center}
.stat-c .v{font-size:22px;font-weight:800;color:#111827}
.stat-c .l{font-size:10px;color:#6b7280;text-transform:uppercase}
.section{border:1px solid #d1d5db;border-radius:5px;margin-bottom:15px;overflow:hidden}
.section-head{background:#f3f4f6;padding:8px 10px;font-size:11px;font-weight:800;text-transform:uppercase}
.section-body{padding:10px}
table{width:100%;border-collapse:collapse;font-size:10.5px}
th{background:#f9fafb;color:#4b5563;padding:6px;border-bottom:1px solid #e5e7eb;text-align:left}
td{padding:6px;border-bottom:1px solid #f3f4f6}
.badge{display:inline-block;padding:2px 6px;border-radius:999px;font-size:9px;font-weight:700}
.b-green{background:#f0fdf4;color:#15803d}
.b-amber{background:#fffbeb;color:#b45309}
.b-red{background:#fef2f2;color:#b91c1c}
.b-blue{background:#eff6ff;color:#1d4ed8}
.b-slate{background:#f1f5f9;color:#475569}
.two-col{display:table;width:100%;border-spacing:10px 0}
.col{display:table-cell;width:50%;vertical-align:top}
.total-row td{font-weight:800;background:#f3f4f6}
.pdf-foot{text-align:center;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:8px;margin-top:20px;font-size:10px}
</style>
</head>
<body>

<div class="cover">
    <h1><?= h($report_title) ?></h1>
    <div class="meta-row">
        <div class="meta-pill">Period: <?= date('d M Y', strtotime($start_date)) ?> — <?= date('d M Y', strtotime($end_date)) ?></div>
        <div class="meta-pill">Scope: <?= $context_name ?></div>
        <div class="meta-pill">Generated: <?= date('d M Y H:i') ?></div>
        <?php if (!empty($_SESSION['full_name'])): ?>
            <div class="meta-pill">By: <?= h($_SESSION['full_name']) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php
switch ($report_type):
    case 'program_summary':
        $rows = $data['programs'] ?? [];
        $active = count(array_filter($rows, fn($r) => in_array($r['status'] ?? '', ['Active', 'Ongoing'], true)));
        $budget = array_sum(array_column($rows, 'budget'));
        $bens = array_sum(array_column($rows, 'beneficiary_count'));
?>
<div class="stats-row">
    <div class="stat-c"><div class="v"><?= count($rows) ?></div><div class="l">Programs</div></div>
    <div class="stat-c"><div class="v"><?= $active ?></div><div class="l">Active</div></div>
    <div class="stat-c"><div class="v"><?= safe_money($budget) ?></div><div class="l">Budget</div></div>
    <div class="stat-c"><div class="v"><?= number_format($bens) ?></div><div class="l">Participants</div></div>
</div>
<div class="section"><div class="section-head">Programs Detail</div><div class="section-body">
<table>
<tr><th>Code</th><th>Name</th><th>Donor</th><th>Budget</th><th>Participants</th><th>Indicators</th><th>Achievement</th><th>Status</th></tr>
<?php foreach ($rows as $r): $ach = (float)($r['avg_achievement'] ?? 0); ?>
<tr>
<td><strong><?= h($r['program_code'] ?? '') ?></strong></td>
<td><?= h($r['program_name'] ?? '') ?></td>
<td><?= h($r['donor_name'] ?? 'N/A') ?></td>
<td><?= safe_money($r['budget'] ?? 0, $r['currency'] ?? 'UGX') ?></td>
<td><?= (int)($r['beneficiary_count'] ?? 0) ?></td>
<td><?= (int)($r['indicator_count'] ?? 0) ?></td>
<td><strong style="color:<?= ach_color($ach) ?>"><?= number_format($ach, 1) ?>%</strong></td>
<td><span class="badge <?= status_badge_cls($r['status'] ?? '') ?>"><?= h($r['status'] ?? 'N/A') ?></span></td>
</tr>
<?php endforeach; ?>
</table>
</div></div>
<?php
        break;

    case 'project_summary':
        $rows = $data['projects'] ?? [];
        $ongoing = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'Ongoing'));
        $budget = array_sum(array_column($rows, 'budget'));
        $bens = array_sum(array_column($rows, 'beneficiary_count'));
?>
<div class="stats-row">
    <div class="stat-c"><div class="v"><?= count($rows) ?></div><div class="l">Projects</div></div>
    <div class="stat-c"><div class="v"><?= $ongoing ?></div><div class="l">Ongoing</div></div>
    <div class="stat-c"><div class="v"><?= safe_money($budget) ?></div><div class="l">Budget</div></div>
    <div class="stat-c"><div class="v"><?= number_format($bens) ?></div><div class="l">Participants</div></div>
</div>
<div class="section"><div class="section-head">Projects Detail</div><div class="section-body">
<table>
<tr><th>Code</th><th>Name</th><th>Donor</th><th>Budget</th><th>Participants</th><th>Indicators</th><th>Achievement</th><th>Status</th></tr>
<?php foreach ($rows as $r): $ach = (float)($r['avg_achievement'] ?? 0); ?>
<tr>
<td><strong><?= h($r['project_code'] ?? '') ?></strong></td>
<td><?= h($r['project_name'] ?? '') ?></td>
<td><?= h($r['donor_name'] ?? 'N/A') ?></td>
<td><?= safe_money($r['budget'] ?? 0, $r['currency'] ?? 'UGX') ?></td>
<td><?= (int)($r['beneficiary_count'] ?? 0) ?></td>
<td><?= (int)($r['indicator_count'] ?? 0) ?></td>
<td><strong style="color:<?= ach_color($ach) ?>"><?= number_format($ach, 1) ?>%</strong></td>
<td><span class="badge <?= status_badge_cls($r['status'] ?? '') ?>"><?= h($r['status'] ?? 'N/A') ?></span></td>
</tr>
<?php endforeach; ?>
</table>
</div></div>
<?php
        break;

    case 'participant_report':
        $rows = $data['participants'] ?? [];
        $gender = $data['gender'] ?? [];
        $pwd = $data['pwd'] ?? ['pwd' => 0, 'total' => 0];
        $pwd_pct = ((int)$pwd['total'] > 0) ? round((int)$pwd['pwd'] / (int)$pwd['total'] * 100, 1) : 0;
?>
<div class="stats-row">
    <div class="stat-c"><div class="v"><?= count($rows) ?></div><div class="l">Participants</div></div>
    <div class="stat-c"><div class="v"><?= (int)$pwd['pwd'] ?></div><div class="l">PWD</div></div>
    <div class="stat-c"><div class="v"><?= $pwd_pct ?>%</div><div class="l">PWD Rate</div></div>
</div>
<div class="section"><div class="section-head">Participants</div><div class="section-body">
<table>
<tr><th>Name</th><th>Gender</th><th>District</th><th>Context</th><th>Type</th><th>Enrolled</th><th>Status</th></tr>
<?php foreach (array_slice($rows, 0, 150) as $r): ?>
<tr>
<td><strong><?= h(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?></strong></td>
<td><?= h($r['gender'] ?? 'N/A') ?></td>
<td><?= h($r['district'] ?? 'N/A') ?></td>
<td><?= h($r['context_type'] ?? 'N/A') ?></td>
<td><?= h($r['participation_type'] ?? 'N/A') ?></td>
<td><?= !empty($r['enrollment_date']) ? date('d M Y', strtotime($r['enrollment_date'])) : 'N/A' ?></td>
<td><span class="badge <?= status_badge_cls($r['enrollment_status'] ?? '') ?>"><?= h($r['enrollment_status'] ?? 'N/A') ?></span></td>
</tr>
<?php endforeach; ?>
</table>
</div></div>
<?php
        break;

    case 'indicator_performance':
        $rows = $data['indicators'] ?? [];
?>
<div class="section"><div class="section-head">Indicators</div><div class="section-body">
<table>
<tr><th>Context</th><th>Indicator</th><th>Type</th><th>Target</th><th>Current</th><th>Achievement</th></tr>
<?php foreach ($rows as $r): $pct = (float)($r['achievement_pct'] ?? 0); ?>
<tr>
<td><?= h($r['context_code'] ?? 'N/A') ?></td>
<td><?= h($r['indicator_name'] ?? '') ?></td>
<td><?= h($r['indicator_type'] ?? '') ?></td>
<td><?= number_format((float)($r['target_value'] ?? 0), 2) ?></td>
<td><?= number_format((float)($r['current_value'] ?? 0), 2) ?></td>
<td><strong style="color:<?= ach_color($pct) ?>"><?= number_format($pct, 1) ?>%</strong></td>
</tr>
<?php endforeach; ?>
</table>
</div></div>
<?php
        break;

    case 'events_report':
        $rows = $data['events'] ?? [];
        $reg = array_sum(array_column($rows, 'registered'));
        $att = array_sum(array_column($rows, 'attended'));
        $rate = $reg > 0 ? round($att / $reg * 100, 1) : 0;
?>
<div class="stats-row">
    <div class="stat-c"><div class="v"><?= count($rows) ?></div><div class="l">Events</div></div>
    <div class="stat-c"><div class="v"><?= $reg ?></div><div class="l">Registered</div></div>
    <div class="stat-c"><div class="v"><?= $att ?></div><div class="l">Attended</div></div>
    <div class="stat-c"><div class="v"><?= $rate ?>%</div><div class="l">Attendance</div></div>
</div>
<div class="section"><div class="section-head">Events Detail</div><div class="section-body">
<table>
<tr><th>Event</th><th>Type</th><th>Date</th><th>Context</th><th>Registered</th><th>Attended</th><th>Rate</th></tr>
<?php foreach ($rows as $r): $rr = ((int)$r['registered'] > 0) ? round((int)$r['attended'] / (int)$r['registered'] * 100, 1) : 0; ?>
<tr>
<td><?= h($r['event_name'] ?? $r['event_title'] ?? '') ?></td>
<td><?= h($r['event_type'] ?? '') ?></td>
<td><?= !empty($r['event_date']) ? date('d M Y', strtotime($r['event_date'])) : 'N/A' ?></td>
<td><?= h($r['context_code'] ?? 'N/A') ?></td>
<td><?= (int)($r['registered'] ?? 0) ?></td>
<td><?= (int)($r['attended'] ?? 0) ?></td>
<td><?= $rr ?>%</td>
</tr>
<?php endforeach; ?>
</table>
</div></div>
<?php
        break;

    case 'hub_operations':
?>
<div class="stats-row">
    <div class="stat-c"><div class="v"><?= (int)($data['visitor_stats']['total'] ?? 0) ?></div><div class="l">Visitors</div></div>
    <div class="stat-c"><div class="v"><?= (int)($data['visitor_stats']['days'] ?? 0) ?></div><div class="l">Active Days</div></div>
</div>
<div class="section"><div class="section-head">Visitor Purposes</div><div class="section-body">
<table><tr><th>Purpose</th><th>Count</th></tr>
<?php foreach (($data['purposes'] ?? []) as $purpose => $count): ?>
<tr><td><?= h($purpose) ?></td><td><?= number_format((int)$count) ?></td></tr>
<?php endforeach; ?>
</table>
</div></div>
<?php if (!empty($data['memberships'])): ?>
<div class="section"><div class="section-head">Memberships</div><div class="section-body">
<table><tr><th>Member Type</th><th>Status</th><th>Members</th><th>Subscription Fees</th></tr>
<?php foreach ($data['memberships'] as $m): ?>
<tr><td><?= h($m['membership_type']) ?></td><td><?= h($m['status']) ?></td><td><?= number_format((int)$m['cnt']) ?></td><td><?= h(safe_money((float)($m['revenue'] ?? 0))) ?></td></tr>
<?php endforeach; ?>
</table>
</div></div>
<?php endif; ?>
<?php
        break;

    case 'donor_report':
        $rows = $data['donors'] ?? [];
?>
<div class="section"><div class="section-head">Donor Summary</div><div class="section-body">
<table>
<tr><th>Donor</th><th>Email</th><th>Programs</th><th>Projects</th><th>Total Budget</th><th>Participants</th></tr>
<?php foreach ($rows as $r):
    $total = (float)($r['prog_budget'] ?? 0) + (float)($r['proj_budget'] ?? 0);
    $bens = (int)($r['prog_bens'] ?? 0) + (int)($r['proj_bens'] ?? 0);
?>
<tr>
<td><?= h($r['donor_name'] ?? '') ?></td>
<td><?= h($r['donor_email'] ?? 'N/A') ?></td>
<td><?= (int)($r['prog_count'] ?? 0) ?></td>
<td><?= (int)($r['proj_count'] ?? 0) ?></td>
<td><?= safe_money($total) ?></td>
<td><?= number_format($bens) ?></td>
</tr>
<?php endforeach; ?>
</table>
</div></div>
<?php
        break;

    case 'pwd_report':
        $rows = $data['pwd_list'] ?? [];
?>
<div class="stats-row">
    <div class="stat-c"><div class="v"><?= count($rows) ?></div><div class="l">PWD Participants</div></div>
</div>
<div class="section"><div class="section-head">PWD Participants</div><div class="section-body">
<table>
<tr><th>Name</th><th>Gender</th><th>District</th><th>Disability</th><th>Programs</th><th>Projects</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<td><?= h(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?></td>
<td><?= h($r['gender'] ?? '') ?></td>
<td><?= h($r['district'] ?? 'N/A') ?></td>
<td><?= h($r['pwd_type'] ?? 'Not specified') ?></td>
<td><?= (int)($r['prog_count'] ?? 0) ?></td>
<td><?= (int)($r['proj_count'] ?? 0) ?></td>
</tr>
<?php endforeach; ?>
</table>
</div></div>
<?php
        break;

    case 'quarterly_report':
    case 'annual_report':
        $p_sum = $data['prog_summary'] ?? [];
        $pr_sum = $data['proj_summary'] ?? [];
        $gender = $data['gender'] ?? [];
        $ind = $data['ind_summary'] ?? [];
        $ev = $data['events_summary'] ?? [];
?>
<div class="stats-row">
    <div class="stat-c"><div class="v"><?= number_format(array_sum($p_sum)) ?></div><div class="l">Programs</div></div>
    <div class="stat-c"><div class="v"><?= number_format(array_sum($pr_sum)) ?></div><div class="l">Projects</div></div>
    <div class="stat-c"><div class="v"><?= number_format(array_sum($gender)) ?></div><div class="l">Participants</div></div>
    <div class="stat-c"><div class="v"><?= number_format((int)($ind['total'] ?? 0)) ?></div><div class="l">Indicators</div></div>
    <div class="stat-c"><div class="v"><?= number_format((int)($ev['period'] ?? 0)) ?></div><div class="l">Events</div></div>
</div>
<div class="two-col">
<div class="col section"><div class="section-head">Programs by Status</div><div class="section-body">
<table><tr><th>Status</th><th>Count</th></tr>
<?php foreach ($p_sum as $s => $c): ?><tr><td><?= h($s) ?></td><td><?= number_format((int)$c) ?></td></tr><?php endforeach; ?>
</table>
</div></div>
<div class="col section"><div class="section-head">Projects by Status</div><div class="section-body">
<table><tr><th>Status</th><th>Count</th></tr>
<?php foreach ($pr_sum as $s => $c): ?><tr><td><?= h($s) ?></td><td><?= number_format((int)$c) ?></td></tr><?php endforeach; ?>
</table>
</div></div>
</div>
<?php
        break;
endswitch;
?>

<div class="pdf-foot">Confidential · <?= h($report_title) ?> · <?= date('Y') ?></div>
</body>
</html>
<?php
return;
endif;
?>

<link rel="stylesheet" href="css/reports.css">

<style>
.report-wrap{display:flex;flex-direction:column;gap:20px;padding:24px;max-width:1400px;margin:0 auto}
.report-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--brand-700,#c2410c) 0%,var(--brand-500,#f97316) 55%,var(--brand-400,#fb923c) 100%);color:#fff;border-radius:18px;padding:28px 36px;box-shadow:0 16px 40px rgba(234,88,12,.28)}
.report-hero-btn{min-height:40px;background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.35);box-shadow:none}
.report-hero-btn:hover{background:rgba(255,255,255,.24);color:#fff}
.report-hero-btn.solid{background:#fff;color:var(--brand-700,#c2410c);border-color:#fff}
.report-hero-btn.solid:hover{background:var(--brand-50,#fff4ed);color:var(--brand-700,#c2410c)}
.report-hero h1{font-size:clamp(18px,2.8vw,26px);font-weight:800;margin-bottom:6px}
.report-hero p{font-size:13px;opacity:.85}
.report-hero-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.meta-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:5px 12px;font-size:11px;font-weight:600;color:#fff}
.summary-box{background:var(--ink-50,#f8fafc);border:1px solid var(--ink-100,#e5e7eb);border-radius:16px;padding:20px 22px;margin-bottom:18px}
.summary-box-title{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-400,#64748b);margin-bottom:14px}
.summary-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px}
.sum-stat-val{font-size:26px;font-weight:800;line-height:1;color:var(--ink-700,#1f2937)}
.sum-stat-lbl{font-size:11px;color:var(--ink-300,#94a3b8);margin-top:3px}
.report-section{background:var(--surface-card,#fff);border:1px solid rgba(0,0,0,.05);border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.06);overflow:hidden;margin-bottom:20px}
.report-section-head{padding:14px 20px;border-bottom:1px solid var(--ink-50,#f1f5f9);display:flex;justify-content:space-between;align-items:center;background:var(--surface-card,#fff)}
.report-section-head h3{font-size:14px;font-weight:800;color:var(--ink-700,#1f2937);display:flex;align-items:center;gap:8px}
.report-section-head h3 i{color:var(--brand-500,#f97316)}
.report-section-body{padding:18px 20px}
.type-heading{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-400,#64748b);margin:18px 0 10px;padding-bottom:7px;border-bottom:1px solid var(--ink-50,#f1f5f9)}
.type-heading:first-child{margin-top:0}
.table-wrap{width:100%;overflow:auto}
.table{width:100%;border-collapse:collapse}
.table th{font-size:11px;text-align:left;color:var(--ink-400,#64748b);background:var(--ink-50,#f8fafc);padding:10px;border-bottom:1px solid var(--ink-100,#e5e7eb)}
.table td{font-size:13px;color:var(--ink-600,#334155);padding:10px;border-bottom:1px solid var(--ink-50,#f1f5f9)}
.no-data{display:flex;flex-direction:column;align-items:center;gap:10px;padding:40px 24px;text-align:center;color:var(--ink-300,#94a3b8)}
.no-data i{font-size:32px}
.prog-track{height:7px;background:var(--ink-100,#e5e7eb);border-radius:999px;overflow:hidden;margin-top:4px}
.prog-fill{height:100%;border-radius:999px}
.prog-fill.high{background:var(--green-fg,#15803d)}
.prog-fill.mid{background:var(--amber-fg,#b45309)}
.prog-fill.low{background:var(--red-fg,#b91c1c)}
.prog-fill.hub-share{background:var(--brand-500,#f97316)}
@media print{.no-print,.hero-actions{display:none!important}.report-wrap{padding:0}body{background:#fff}}
@media(max-width:700px){.report-wrap{padding:12px}.report-hero{padding:20px}.summary-stats{grid-template-columns:1fr 1fr}}
</style>

<div class="report-wrap">

    <div class="report-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
            <div>
                <h1><?= h($report_title) ?></h1>
                <p>Hive Colab IMS — Generated <?= date('d M Y H:i') ?></p>
                <div class="report-hero-meta">
                    <span class="meta-chip">
                        <i class="fas fa-calendar-alt"></i>
                        <?= date('d M Y', strtotime($start_date)) ?> — <?= date('d M Y', strtotime($end_date)) ?>
                    </span>
                    <span class="meta-chip">
                        <i class="fas <?= $context_type === 'Program' ? 'fa-sitemap' : ($context_type === 'Project' ? 'fa-project-diagram' : 'fa-globe') ?>"></i>
                        <?= $context_name ?>
                    </span>
                </div>
            </div>

            <div class="hero-actions no-print" style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="generate-report" class="btn report-hero-btn">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <button type="button" onclick="window.print()" class="btn report-hero-btn">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="report-process.php?type=<?= urlencode($report_type) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&project_id=<?= (int)($project_id ?? 0) ?>&program_id=<?= (int)($program_id ?? 0) ?>&export=pdf" target="_blank" rel="noopener" class="btn report-hero-btn solid">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
            </div>
        </div>
    </div>

<?php
switch ($report_type):
    case 'program_summary':
        $rows = $data['programs'] ?? [];
        $active = count(array_filter($rows, fn($r) => in_array($r['status'] ?? '', ['Active', 'Ongoing'], true)));
        $budget = array_sum(array_column($rows, 'budget'));
        $bens = array_sum(array_column($rows, 'beneficiary_count'));
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-sitemap"></i> Programs Overview</h3>
            <span class="badge badge-available"><?= count($rows) ?> program<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="report-section-body">
            <div class="summary-box">
                <div class="summary-box-title">Summary</div>
                <div class="summary-stats">
                    <div><div class="sum-stat-val"><?= number_format(count($rows)) ?></div><div class="sum-stat-lbl">Total Programs</div></div>
                    <div><div class="sum-stat-val" style="color:var(--green-fg,#15803d);"><?= number_format($active) ?></div><div class="sum-stat-lbl">Active</div></div>
                    <div><div class="sum-stat-val"><?= safe_money($budget) ?></div><div class="sum-stat-lbl">Total Budget</div></div>
                    <div><div class="sum-stat-val"><?= number_format($bens) ?></div><div class="sum-stat-lbl">Participants</div></div>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="no-data"><i class="fas fa-folder-open"></i><span>No programs found.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Code</th><th>Name</th><th>Donor</th><th>Budget</th><th>Participants</th><th>Indicators</th><th>Achievement</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): $ach = (float)($r['avg_achievement'] ?? 0); ?>
                            <tr>
                                <td><strong><?= h($r['program_code'] ?? '') ?></strong></td>
                                <td><?= h($r['program_name'] ?? '') ?></td>
                                <td><?= h($r['donor_name'] ?? 'N/A') ?></td>
                                <td><?= safe_money($r['budget'] ?? 0, $r['currency'] ?? 'UGX') ?></td>
                                <td><?= number_format((int)($r['beneficiary_count'] ?? 0)) ?></td>
                                <td><?= number_format((int)($r['indicator_count'] ?? 0)) ?></td>
                                <td><strong style="color:<?= ach_color($ach) ?>"><?= number_format($ach, 1) ?>%</strong></td>
                                <td><span class="badge <?= web_badge_cls($r['status'] ?? '') ?>"><?= h($r['status'] ?? 'N/A') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'project_summary':
        $rows = $data['projects'] ?? [];
        $ongoing = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'Ongoing'));
        $budget = array_sum(array_column($rows, 'budget'));
        $bens = array_sum(array_column($rows, 'beneficiary_count'));
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-project-diagram"></i> Projects Overview</h3>
            <span class="badge badge-available"><?= count($rows) ?> project<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="report-section-body">
            <div class="summary-box">
                <div class="summary-box-title">Summary</div>
                <div class="summary-stats">
                    <div><div class="sum-stat-val"><?= number_format(count($rows)) ?></div><div class="sum-stat-lbl">Total Projects</div></div>
                    <div><div class="sum-stat-val" style="color:var(--green-fg,#15803d);"><?= number_format($ongoing) ?></div><div class="sum-stat-lbl">Ongoing</div></div>
                    <div><div class="sum-stat-val"><?= safe_money($budget) ?></div><div class="sum-stat-lbl">Total Budget</div></div>
                    <div><div class="sum-stat-val"><?= number_format($bens) ?></div><div class="sum-stat-lbl">Participants</div></div>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="no-data"><i class="fas fa-folder-open"></i><span>No projects found.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Code</th><th>Name</th><th>Donor</th><th>Budget</th><th>Participants</th><th>Indicators</th><th>Achievement</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): $ach = (float)($r['avg_achievement'] ?? 0); ?>
                            <tr>
                                <td><strong><?= h($r['project_code'] ?? '') ?></strong></td>
                                <td><?= h($r['project_name'] ?? '') ?></td>
                                <td><?= h($r['donor_name'] ?? 'N/A') ?></td>
                                <td><?= safe_money($r['budget'] ?? 0, $r['currency'] ?? 'UGX') ?></td>
                                <td><?= number_format((int)($r['beneficiary_count'] ?? 0)) ?></td>
                                <td><?= number_format((int)($r['indicator_count'] ?? 0)) ?></td>
                                <td><strong style="color:<?= ach_color($ach) ?>"><?= number_format($ach, 1) ?>%</strong></td>
                                <td><span class="badge <?= web_badge_cls($r['status'] ?? '') ?>"><?= h($r['status'] ?? 'N/A') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'participant_report':
        $rows = $data['participants'] ?? [];
        $gender = $data['gender'] ?? [];
        $pwd = $data['pwd'] ?? ['pwd' => 0, 'total' => 0];
        $pwd_pct = ((int)$pwd['total'] > 0) ? round((int)$pwd['pwd'] / (int)$pwd['total'] * 100, 1) : 0;
        $gtotal = array_sum($gender);
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-users"></i> Participants Analysis</h3>
            <span class="badge badge-available"><?= count($rows) ?> participant<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="report-section-body">
            <div class="summary-box">
                <div class="summary-box-title">Summary</div>
                <div class="summary-stats">
                    <div><div class="sum-stat-val"><?= number_format(count($rows)) ?></div><div class="sum-stat-lbl">Participants</div></div>
                    <div><div class="sum-stat-val"><?= number_format((int)$pwd['pwd']) ?></div><div class="sum-stat-lbl">PWD</div></div>
                    <div><div class="sum-stat-val"><?= $pwd_pct ?>%</div><div class="sum-stat-lbl">PWD Rate</div></div>
                    <div><div class="sum-stat-val"><?= number_format($gtotal) ?></div><div class="sum-stat-lbl">Gender Records</div></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:18px;">
                <div class="summary-box">
                    <div class="summary-box-title">Gender Distribution</div>
                    <?php foreach ($gender as $g => $c): ?>
                        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ink-100,#e5e7eb);">
                            <span><?= h($g) ?></span>
                            <strong><?= number_format((int)$c) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="summary-box">
                    <div class="summary-box-title">PWD Statistics</div>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ink-100,#e5e7eb);">
                        <span>Persons with Disabilities</span>
                        <strong><?= number_format((int)$pwd['pwd']) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ink-100,#e5e7eb);">
                        <span>Non-PWD</span>
                        <strong><?= number_format((int)$pwd['total'] - (int)$pwd['pwd']) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0 0;">
                        <strong>Inclusion Rate</strong>
                        <strong><?= $pwd_pct ?>%</strong>
                    </div>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="no-data"><i class="fas fa-users"></i><span>No participants found.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Name</th><th>Gender</th><th>District</th><th>Context</th><th>Type</th><th>Enrolled</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong><?= h(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?></strong></td>
                                <td><?= h($r['gender'] ?? 'N/A') ?></td>
                                <td><?= h($r['district'] ?? 'N/A') ?></td>
                                <td><?= h($r['context_type'] ?? 'N/A') ?></td>
                                <td><?= h($r['participation_type'] ?? 'N/A') ?></td>
                                <td><?= !empty($r['enrollment_date']) ? date('d M Y', strtotime($r['enrollment_date'])) : 'N/A' ?></td>
                                <td><span class="badge <?= web_badge_cls($r['enrollment_status'] ?? '') ?>"><?= h($r['enrollment_status'] ?? 'N/A') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'indicator_performance':
        $rows = $data['indicators'] ?? [];
        $achieved = count(array_filter($rows, fn($r) => (float)($r['achievement_pct'] ?? 0) >= 100));
        $partial = count(array_filter($rows, fn($r) => (float)($r['achievement_pct'] ?? 0) >= 50 && (float)($r['achievement_pct'] ?? 0) < 100));
        $low = count($rows) - $achieved - $partial;
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-chart-line"></i> Indicator Performance</h3>
            <span class="badge badge-available"><?= count($rows) ?> indicator<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="report-section-body">
            <div class="summary-box">
                <div class="summary-box-title">Summary</div>
                <div class="summary-stats">
                    <div><div class="sum-stat-val"><?= number_format(count($rows)) ?></div><div class="sum-stat-lbl">Total</div></div>
                    <div><div class="sum-stat-val"><?= number_format($achieved) ?></div><div class="sum-stat-lbl">Achieved</div></div>
                    <div><div class="sum-stat-val"><?= number_format($partial) ?></div><div class="sum-stat-lbl">Partial</div></div>
                    <div><div class="sum-stat-val"><?= number_format($low) ?></div><div class="sum-stat-lbl">Below Target</div></div>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="no-data"><i class="fas fa-chart-line"></i><span>No indicators found.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Context</th><th>Indicator</th><th>Type</th><th>Baseline</th><th>Target</th><th>Current</th><th>Achievement</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): $pct = (float)($r['achievement_pct'] ?? 0); ?>
                            <tr>
                                <td><?= h($r['context_code'] ?? 'N/A') ?></td>
                                <td><strong><?= h($r['indicator_name'] ?? '') ?></strong></td>
                                <td><?= h($r['indicator_type'] ?? '') ?></td>
                                <td><?= number_format((float)($r['baseline_value'] ?? 0), 2) ?></td>
                                <td><?= number_format((float)($r['target_value'] ?? 0), 2) ?></td>
                                <td><?= number_format((float)($r['current_value'] ?? 0), 2) ?></td>
                                <td>
                                    <strong style="color:<?= ach_color($pct) ?>"><?= number_format($pct, 1) ?>%</strong>
                                    <div class="prog-track">
                                        <div class="prog-fill <?= prog_class($pct) ?>" style="width:<?= min($pct, 100) ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'events_report':
        $rows = $data['events'] ?? [];
        $reg = array_sum(array_column($rows, 'registered'));
        $att = array_sum(array_column($rows, 'attended'));
        $rate = $reg > 0 ? round($att / $reg * 100, 1) : 0;
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-calendar-check"></i> Events & Training</h3>
            <span class="badge badge-available"><?= count($rows) ?> event<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="report-section-body">
            <div class="summary-box">
                <div class="summary-box-title">Summary</div>
                <div class="summary-stats">
                    <div><div class="sum-stat-val"><?= number_format(count($rows)) ?></div><div class="sum-stat-lbl">Events</div></div>
                    <div><div class="sum-stat-val"><?= number_format($reg) ?></div><div class="sum-stat-lbl">Registered</div></div>
                    <div><div class="sum-stat-val"><?= number_format($att) ?></div><div class="sum-stat-lbl">Attended</div></div>
                    <div><div class="sum-stat-val"><?= $rate ?>%</div><div class="sum-stat-lbl">Attendance Rate</div></div>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="no-data"><i class="fas fa-calendar"></i><span>No events found.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Event</th><th>Type</th><th>Date</th><th>Context</th><th>Registered</th><th>Attended</th><th>Rate</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): $rr = ((int)$r['registered'] > 0) ? round((int)$r['attended'] / (int)$r['registered'] * 100, 1) : 0; ?>
                            <tr>
                                <td><strong><?= h($r['event_name'] ?? $r['event_title'] ?? '') ?></strong></td>
                                <td><?= h($r['event_type'] ?? '') ?></td>
                                <td><?= !empty($r['event_date']) ? date('d M Y', strtotime($r['event_date'])) : 'N/A' ?></td>
                                <td><?= h($r['context_code'] ?? 'N/A') ?></td>
                                <td><?= number_format((int)($r['registered'] ?? 0)) ?></td>
                                <td><?= number_format((int)($r['attended'] ?? 0)) ?></td>
                                <td><strong style="color:<?= ach_color($rr) ?>"><?= $rr ?>%</strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'hub_operations':
        $visitor_stats = $data['visitor_stats'] ?? ['total' => 0, 'days' => 0];
        $hub_visitors  = (int)($visitor_stats['total'] ?? 0);
        $hub_days      = (int)($visitor_stats['days'] ?? 0);
        $hub_avg       = $hub_days > 0 ? round($hub_visitors / $hub_days, 1) : 0;
        $hub_purposes  = $data['purposes'] ?? [];
        $hub_members   = $data['memberships'] ?? [];
        $hub_active    = 0;
        $hub_revenue   = 0.0;
        foreach ($hub_members as $m) {
            if (($m['status'] ?? '') === 'Active') {
                $hub_active  += (int)$m['cnt'];
                $hub_revenue += (float)($m['revenue'] ?? 0);
            }
        }
?>
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-user-check" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Total Visitors</span><span class="stat-value"><?= number_format($hub_visitors) ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Active Days</span><span class="stat-value"><?= number_format($hub_days) ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Avg Visitors / Day</span><span class="stat-value"><?= number_format($hub_avg, 1) ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-id-card" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Active Memberships</span><span class="stat-value"><?= number_format($hub_active) ?></span></div>
        </div>
    </div>

    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-clipboard-list" aria-hidden="true"></i> Visitor Purposes</h3>
        </div>
        <div class="report-section-body">
        <?php if (!$hub_purposes): ?>
            <div class="no-data"><i class="fas fa-user-slash" aria-hidden="true"></i><span>No visitors recorded in this period.</span></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Purpose</th><th class="text-nowrap">Visitors</th><th style="width:40%">Share</th></tr></thead>
                    <tbody>
                    <?php foreach ($hub_purposes as $purpose => $count):
                        $share = $hub_visitors > 0 ? round((int)$count / $hub_visitors * 100, 1) : 0; ?>
                        <tr>
                            <td><?= h($purpose) ?></td>
                            <td><?= number_format((int)$count) ?></td>
                            <td>
                                <span class="cell-muted" style="margin:0"><?= number_format($share, 1) ?>%</span>
                                <div class="prog-track"><div class="prog-fill hub-share" style="width:<?= min(100, $share) ?>%"></div></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-id-card" aria-hidden="true"></i> Memberships</h3>
            <?php if ($hub_revenue > 0): ?>
                <span class="cell-muted" style="margin:0">Active subscription fees: <strong><?= h(safe_money($hub_revenue)) ?></strong></span>
            <?php endif; ?>
        </div>
        <div class="report-section-body">
        <?php if (!$hub_members): ?>
            <div class="no-data"><i class="fas fa-id-card" aria-hidden="true"></i><span>No memberships overlap this period.</span></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Member Type</th><th>Status</th><th>Members</th><th>Subscription Fees</th></tr></thead>
                    <tbody>
                    <?php foreach ($hub_members as $m): ?>
                        <tr>
                            <td><?= h($m['membership_type']) ?></td>
                            <td><span class="badge <?= h(web_badge_cls((string)$m['status'])) ?>"><?= h($m['status']) ?></span></td>
                            <td><?= number_format((int)$m['cnt']) ?></td>
                            <td><?= h(safe_money((float)($m['revenue'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'donor_report':
        $rows = $data['donors'] ?? [];
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-handshake"></i> Donor Report</h3>
            <span class="badge badge-available"><?= count($rows) ?> donor<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="report-section-body">
            <?php if (empty($rows)): ?>
                <div class="no-data"><i class="fas fa-handshake"></i><span>No donor data found.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Donor</th><th>Email</th><th>Programs</th><th>Projects</th><th>Total Budget</th><th>Participants</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $total = (float)($r['prog_budget'] ?? 0) + (float)($r['proj_budget'] ?? 0);
                            $bens = (int)($r['prog_bens'] ?? 0) + (int)($r['proj_bens'] ?? 0);
                        ?>
                            <tr>
                                <td><strong><?= h($r['donor_name'] ?? '') ?></strong></td>
                                <td><?= h($r['donor_email'] ?? 'N/A') ?></td>
                                <td><?= number_format((int)($r['prog_count'] ?? 0)) ?></td>
                                <td><?= number_format((int)($r['proj_count'] ?? 0)) ?></td>
                                <td><?= safe_money($total) ?></td>
                                <td><?= number_format($bens) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'pwd_report':
        $rows = $data['pwd_list'] ?? [];
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-wheelchair"></i> Persons with Disabilities</h3>
            <span class="badge badge-available"><?= count($rows) ?> participant<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="report-section-body">
            <?php if (!empty($data['pwd_types'])): ?>
                <div class="type-heading">Disability Types</div>
                <div class="table-wrap" style="margin-bottom:18px;">
                    <table class="table">
                        <thead><tr><th>Type</th><th>Count</th></tr></thead>
                        <tbody>
                        <?php foreach ($data['pwd_types'] as $t): ?>
                            <tr><td><?= h($t['pwd_type'] ?? '') ?></td><td><?= number_format((int)($t['cnt'] ?? 0)) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (empty($rows)): ?>
                <div class="no-data"><i class="fas fa-wheelchair"></i><span>No PWD participants found.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Name</th><th>Gender</th><th>District</th><th>Disability</th><th>Programs</th><th>Projects</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong><?= h(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?></strong></td>
                                <td><?= h($r['gender'] ?? '') ?></td>
                                <td><?= h($r['district'] ?? 'N/A') ?></td>
                                <td><?= h($r['pwd_type'] ?? 'Not specified') ?></td>
                                <td><?= number_format((int)($r['prog_count'] ?? 0)) ?></td>
                                <td><?= number_format((int)($r['proj_count'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
        break;

    case 'quarterly_report':
    case 'annual_report':
        $p_sum = $data['prog_summary'] ?? [];
        $pr_sum = $data['proj_summary'] ?? [];
        $gender = $data['gender'] ?? [];
        $ind = $data['ind_summary'] ?? [];
        $ev = $data['events_summary'] ?? [];
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3>
                <i class="fas fa-<?= $report_type === 'annual_report' ? 'file-invoice' : 'calendar-check' ?>"></i>
                <?= h($report_title) ?>
            </h3>
        </div>
        <div class="report-section-body">
            <div class="summary-box">
                <div class="summary-box-title">Period Overview</div>
                <div class="summary-stats">
                    <div><div class="sum-stat-val"><?= number_format(array_sum($p_sum)) ?></div><div class="sum-stat-lbl">Programs</div></div>
                    <div><div class="sum-stat-val"><?= number_format(array_sum($pr_sum)) ?></div><div class="sum-stat-lbl">Projects</div></div>
                    <div><div class="sum-stat-val"><?= number_format(array_sum($gender)) ?></div><div class="sum-stat-lbl">Participants</div></div>
                    <div><div class="sum-stat-val"><?= number_format((int)($ind['total'] ?? 0)) ?></div><div class="sum-stat-lbl">Indicators</div></div>
                    <div><div class="sum-stat-val"><?= number_format((int)($ev['period'] ?? 0)) ?></div><div class="sum-stat-lbl">Events in Period</div></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
                <div>
                    <div class="type-heading">Programs by Status</div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Status</th><th>Count</th></tr></thead>
                            <tbody>
                            <?php foreach ($p_sum as $s => $c): ?>
                                <tr><td><?= h($s) ?></td><td><?= number_format((int)$c) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="type-heading" style="margin-top:16px;">Participants by Gender</div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Gender</th><th>Count</th></tr></thead>
                            <tbody>
                            <?php foreach ($gender as $g => $c): ?>
                                <tr><td><?= h($g) ?></td><td><?= number_format((int)$c) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <div class="type-heading">Projects by Status</div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Status</th><th>Count</th></tr></thead>
                            <tbody>
                            <?php foreach ($pr_sum as $s => $c): ?>
                                <tr><td><?= h($s) ?></td><td><?= number_format((int)$c) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="type-heading" style="margin-top:16px;">Indicators Performance</div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Category</th><th>Count</th></tr></thead>
                            <tbody>
                            <tr><td>Achieved (=100%)</td><td><strong style="color:var(--green-fg,#15803d);"><?= number_format((int)($ind['achieved'] ?? 0)) ?></strong></td></tr>
                            <tr><td>Partial (50–99%)</td><td><strong style="color:var(--amber-fg,#b45309);"><?= number_format((int)($ind['partial'] ?? 0)) ?></strong></td></tr>
                            <tr><td>Low (&lt;50%)</td><td><strong style="color:var(--red-fg,#b91c1c);"><?= number_format((int)($ind['low'] ?? 0)) ?></strong></td></tr>
                            <tr style="background:var(--ink-50,#f8fafc);font-weight:800;">
                                <td>Total</td>
                                <td><?= number_format((int)($ind['total'] ?? 0)) ?></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
        break;

    default:
?>
    <div class="report-section">
        <div class="report-section-head">
            <h3><i class="fas fa-file-alt"></i> Report</h3>
        </div>
        <div class="report-section-body">
            <div class="no-data"><i class="fas fa-tools"></i><span>This report type is under development.</span></div>
        </div>
    </div>
<?php
        break;
endswitch;
?>

    <div class="report-section no-print" style="text-align:center;padding:20px;">
        <p style="color:var(--ink-300,#94a3b8);font-size:13px;margin-bottom:14px;">
            Report generated by <?= h($_SESSION['full_name'] ?? 'User') ?> on <?= date('d M Y H:i') ?>
        </p>

        <button onclick="window.print()" class="btn btn-dark">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>