<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Recruitment (HR / Administrators)
|--------------------------------------------------------------------------
| No job selected: every job with its pipeline numbers.
| recruitment?job_id=N
|   view=candidates  applicants ranked by AI screening score; bulk shortlist /
|                    reject / move; "shortlist top N"; AI screening run.
|   view=criteria    screening criteria the AI scores applicants against.
|   view=questions   interview questions the panel scores candidates on.
|   view=interviews  scheduled interviews, panel progress and results.
|   app=ID           one applicant: application, AI analysis, interviews.
| Workflow and notifications live in includes/hr.php.
*/

$page_title = 'Recruitment';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hr.php';
check_login();

if (!hr_can_recruit()) {
    $_SESSION['notification'] = ['message' => 'You are not authorized to access that page.', 'type' => 'danger'];
    header('Location: ' . dashboard_url());
    exit;
}

$uid = (int)$_SESSION['user_id'];
$e = 'hr_h';

if (!hr_ensure_schema($conn)) {
    include 'includes/header.php';
    echo '<div class="card"><div class="card-body"><div class="alert alert-warning">Recruitment is unavailable: the HR tables could not be created. Ask an Administrator to run database/migrations/2026_11_20_hr_module.sql.</div></div></div>';
    include 'includes/footer.php';
    exit;
}

$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
$job = $jobId > 0 ? hr_job($conn, $jobId) : null;
if ($jobId > 0 && !$job) {
    $_SESSION['notification'] = ['message' => 'Job not found.', 'type' => 'danger'];
    header('Location: recruitment');
    exit;
}
$views = ['candidates' => 'Applicants', 'criteria' => 'Screening criteria', 'questions' => 'Interview questions', 'interviews' => 'Interviews'];
$view = array_key_exists((string)($_GET['view'] ?? ''), $views) ? (string)$_GET['view'] : 'candidates';
$appId = (int)($_GET['app'] ?? 0);

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_POST['ajax']);
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Your session expired. Refresh the page.']);
            exit;
        }
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }
    if (!$job) {
        header('Location: recruitment', true, 303);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $belongs = static function (int $applicationId) use ($conn, $jobId): bool {
        return (bool)hr_row($conn, 'SELECT 1 FROM job_applications WHERE application_id = ? AND job_id = ?', 'ii', [$applicationId, $jobId]);
    };

    if ($isAjax) {
        header('Content-Type: application/json');
        @set_time_limit(180);
        if ($action === 'screen_queue') {
            echo json_encode(['ok' => true, 'ids' => hr_screen_queue($conn, $jobId, !empty($_POST['all']))]);
        } elseif ($action === 'ai_screen') {
            $aid = (int)($_POST['application_id'] ?? 0);
            echo json_encode($belongs($aid) ? hr_ai_screen($conn, $aid, $uid) : hr_result(false, 'Application not found.'));
        } else {
            echo json_encode(hr_result(false, 'Unknown action.'));
        }
        exit;
    }

    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), $belongs));
    $notify = !empty($_POST['notify']);
    $note = trim((string)($_POST['note'] ?? ''));
    $returnView = array_key_exists((string)($_POST['return_view'] ?? ''), $views) ? (string)$_POST['return_view'] : 'candidates';
    $returnApp = (int)($_POST['return_app'] ?? 0);

    $result = match ($action) {
        'stage' => hr_set_stage($conn, $jobId, $ids, (string)($_POST['stage'] ?? ''), $uid, $notify, $note),
        'shortlist_top' => hr_shortlist_top($conn, $jobId, (int)($_POST['top_n'] ?? 5), (float)($_POST['min_score'] ?? 0), $uid, $notify, $note),
        'notes' => $belongs((int)($_POST['application_id'] ?? 0)) ? hr_save_notes($conn, (int)$_POST['application_id'], (string)($_POST['hr_notes'] ?? ''), $uid) : hr_result(false, 'Application not found.'),
        'criteria' => hr_save_criteria($conn, $jobId, $_POST),
        'questions' => hr_save_questions($conn, $jobId, $_POST),
        'copy' => hr_copy_setup($conn, (int)($_POST['from_job'] ?? 0), $jobId, (string)($_POST['what'] ?? '') === 'criteria' ? 'criteria' : 'questions'),
        'interview' => $belongs((int)($_POST['application_id'] ?? 0)) || (int)($_POST['interview_id'] ?? 0) > 0
            ? hr_save_interview($conn, $_POST, $uid) : hr_result(false, 'Choose an applicant.'),
        'interview_status' => hr_set_interview_status($conn, (int)($_POST['interview_id'] ?? 0), (string)($_POST['status'] ?? ''), $uid, $notify),
        'remind_panel' => hr_remind_panel($conn, (int)($_POST['interview_id'] ?? 0)),
        default => hr_result(false, 'Unknown action.'),
    };
    $_SESSION['notification'] = ['message' => $result['message'], 'type' => $result['ok'] ? 'success' : 'danger'];

    $target = 'recruitment?job_id=' . $jobId . ($returnApp > 0 ? '&app=' . $returnApp : '&view=' . $returnView);
    header('Location: ' . $target, true, 303);
    exit;
}

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
$allJobs = hr_jobs_overview($conn);
$stageBadge = static fn(string $s): string => '<span class="badge ' . (HR_STAGES[$s] ?? 'badge-secondary') . '">' . hr_h($s) . '</span>';
$scoreBar = static function ($score, string $label = ''): string {
    if ($score === null || $score === '') {
        return '<span class="hr-muted">' . ($label !== '' ? hr_h($label) : 'Not screened') . '</span>';
    }
    $s = (float)$score;
    $cls = $s >= 70 ? 'is-high' : ($s >= 50 ? 'is-mid' : 'is-low');
    return '<div class="hr-score"><div class="hr-score-bar ' . $cls . '"><span style="width:' . max(2, min(100, $s)) . '%"></span></div><b>' . rtrim(rtrim(number_format($s, 1), '0'), '.') . '%</b></div>';
};

$candidate = null;
$candidates = [];
$counts = [];
$criteria = [];
$questions = [];
$interviews = [];
$staff = [];
if ($job) {
    hr_sync_candidates($conn, $jobId);
    $counts = hr_stage_counts($conn, $jobId);
    $criteria = hr_criteria($conn, $jobId);
    $questions = hr_questions($conn, $jobId);
    $staff = hr_staff_users($conn);
    if ($appId > 0) {
        $candidate = hr_candidate($conn, $appId);
        if (!$candidate || (int)$candidate['job_id'] !== $jobId) {
            $candidate = null;
            $appId = 0;
        }
    }
    if (!$candidate) {
        if ($view === 'candidates') {
            $stageFilter = (string)($_GET['stage'] ?? 'active');
            $candidates = hr_candidates($conn, $jobId, ['stage' => $stageFilter, 'q' => (string)($_GET['q'] ?? '')]);
        }
        if ($view === 'interviews') {
            $interviews = hr_job_interviews($conn, $jobId);
        }
    }
}
$unscreened = $job ? count(hr_screen_queue($conn, $jobId, false)) : 0;
$schedulable = $job ? hr_candidates($conn, $jobId, ['stage' => 'active']) : [];

$hrEmbedJobs = $allJobs;
$hrEmbedSelected = $jobId;

include 'includes/header.php';
?>
<link rel="stylesheet" href="css/hr.css">

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-user-check" aria-hidden="true"></i> <?= $job ? $e($job['job_title']) : 'Recruitment' ?></h1>
            <p><?= $job
                ? $e(trim(($job['department'] ?: '') . ($job['location'] ? ' · ' . $job['location'] : '') . ' · ' . $job['status'], ' ·'))
                : 'Screen applicants with AI against each job\'s criteria, shortlist the best, invite them to interview and let the panel score them.' ?></p>
        </div>
        <div class="hero-actions">
            <?php if ($job): ?>
                <a href="recruitment" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> All jobs</a>
                <button type="button" class="btn" onclick="hrOpenEmbed(<?= $jobId ?>)"><i class="fas fa-code" aria-hidden="true"></i> Embed &amp; share</button>
                <button type="button" class="btn btn-primary" onclick="hrOpenInterview()"><i class="fas fa-calendar-plus" aria-hidden="true"></i> Schedule interview</button>
            <?php else: ?>
                <a href="hr-operations" class="btn"><i class="fas fa-people-roof" aria-hidden="true"></i> HR Operations</a>
                <button type="button" class="btn" onclick="hrOpenEmbed(0)"><i class="fas fa-code" aria-hidden="true"></i> Embed jobs</button>
                <a href="jobs" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Post a job</a>
            <?php endif; ?>
        </div>
    </div>

<?php if (!$job): ?>
    <!-- ================= All jobs ================= -->
    <?php
    $totals = ['applicants' => 0, 'screened' => 0, 'shortlisted' => 0, 'upcoming' => 0];
    foreach ($allJobs as $j) { foreach ($totals as $k => $v) { $totals[$k] += (int)$j[$k]; } }
    ?>
    <div class="stats-grid reports-stats">
        <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Applicants</span><span class="stat-value"><?= $totals['applicants'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-robot" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">AI screened</span><span class="stat-value"><?= $totals['screened'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-teal"><i class="fas fa-list-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Shortlisted or further</span><span class="stat-value"><?= $totals['shortlisted'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-calendar-day" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Upcoming interviews</span><span class="stat-value"><?= $totals['upcoming'] ?></span></div></div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-briefcase" aria-hidden="true"></i> Jobs</h3></div>
        <div class="card-body">
            <?php if (!$allJobs): ?>
                <div class="empty-state"><i class="fas fa-briefcase" aria-hidden="true"></i><p>No jobs yet. <a href="jobs">Post a job</a> to start receiving applications.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr><th>Job</th><th>Status</th><th>Deadline</th><th>Applicants</th><th>Screened</th><th>Shortlisted+</th><th>Interviews</th><th>Hired</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($allJobs as $j): ?>
                            <tr>
                                <td data-label="Job">
                                    <a class="hr-name" href="recruitment?job_id=<?= (int)$j['job_id'] ?>"><?= $e($j['job_title']) ?></a>
                                    <div class="hr-muted"><?= $e(trim(($j['department'] ?? '') . ' · ' . ($j['job_type'] ?? ''), ' ·')) ?><?php if (!(int)$j['criteria']): ?> · <span class="text-warning">no screening criteria</span><?php endif; ?></div>
                                </td>
                                <td data-label="Status"><span class="badge <?= match ($j['status']) { 'Published' => 'badge-success', 'Draft' => 'badge-warning', 'Closed' => 'badge-danger', default => 'badge-secondary' } ?>"><?= $e($j['status']) ?></span></td>
                                <td data-label="Deadline"><?= $j['deadline'] ? $e(date('j M Y', strtotime((string)$j['deadline']))) : '<span class="hr-muted">-</span>' ?></td>
                                <td data-label="Applicants"><strong><?= (int)$j['applicants'] ?></strong></td>
                                <td data-label="Screened"><?= (int)$j['screened'] ?></td>
                                <td data-label="Shortlisted+"><?= (int)$j['shortlisted'] ?></td>
                                <td data-label="Interviews"><?= (int)$j['upcoming'] ?> upcoming</td>
                                <td data-label="Hired"><?= (int)$j['hired'] ?></td>
                                <td data-label=""><a class="btn btn-sm btn-primary" href="recruitment?job_id=<?= (int)$j['job_id'] ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($candidate): ?>
    <!-- ================= One applicant ================= -->
    <?php
    $c = $candidate;
    $critRows = json_decode((string)($c['ai_criteria'] ?? ''), true) ?: [];
    $candInterviews = array_values(array_filter(hr_job_interviews($conn, $jobId), fn($i) => (int)$i['application_id'] === $appId));
    $lines = static fn(?string $t): array => array_values(array_filter(array_map('trim', explode("\n", (string)$t))));
    ?>
    <p><a href="recruitment?job_id=<?= $jobId ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to applicants</a></p>
    <div class="hr-grid-2">
        <div class="hr-col">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-robot" aria-hidden="true"></i> AI screening</h3>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="hrScreen([<?= $appId ?>], this)"><i class="fas fa-rotate" aria-hidden="true"></i> <?= $c['ai_screened_at'] ? 'Re-screen' : 'Screen now' ?></button>
                </div>
                <div class="card-body">
                    <?php if (!$c['ai_screened_at']): ?>
                        <p class="hr-muted"><?= $c['ai_error'] ? 'Last attempt failed: ' . $e($c['ai_error']) : 'Not screened yet.' ?></p>
                    <?php else: ?>
                        <div class="hr-ai-head">
                            <div class="hr-ai-big"><?= $e(rtrim(rtrim(number_format((float)$c['ai_score'], 1), '0'), '.')) ?>%</div>
                            <div>
                                <span class="badge <?= in_array($c['ai_fit'], ['Strong fit', 'Good fit'], true) ? 'badge-success' : ($c['ai_fit'] === 'Possible fit' ? 'badge-warning' : 'badge-danger') ?>"><?= $e($c['ai_fit']) ?></span>
                                <?php if ((int)$c['must_have_missing']): ?><span class="badge badge-danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Missing a must-have</span><?php endif; ?>
                                <div class="hr-muted">Screened <?= $e(date('j M Y, H:i', strtotime((string)$c['ai_screened_at']))) ?><?= $c['ai_model'] ? ' · ' . $e($c['ai_model']) : '' ?></div>
                            </div>
                        </div>
                        <p class="hr-pre"><?= $e($c['ai_summary']) ?></p>

                        <?php if ($critRows): ?>
                            <div class="table-responsive">
                                <table class="hr-crit">
                                    <thead><tr><th>Criterion</th><th>Met</th><th>Score</th><th>Evidence</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($critRows as $cr): ?>
                                        <tr>
                                            <td><?= $e($cr['criterion']) ?><?= !empty($cr['must_have']) ? ' <span class="badge badge-dark">Must</span>' : '' ?><div class="hr-muted">Weight <?= (int)$cr['weight'] ?></div></td>
                                            <td class="hr-met-<?= $e($cr['met']) ?>"><?= $e(ucfirst((string)$cr['met'])) ?></td>
                                            <td><?= $e($cr['score']) ?>/10</td>
                                            <td><?= $e($cr['evidence']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <div class="hr-form-grid" style="margin-top:14px">
                            <div><h4>Strengths</h4><ul class="hr-list"><?php foreach ($lines($c['ai_strengths']) as $l): ?><li><?= $e($l) ?></li><?php endforeach; ?></ul></div>
                            <div><h4>Gaps</h4><ul class="hr-list"><?php foreach ($lines($c['ai_gaps']) as $l): ?><li><?= $e($l) ?></li><?php endforeach; ?></ul></div>
                            <?php if ($lines($c['ai_red_flags'])): ?><div><h4>Points to check</h4><ul class="hr-list"><?php foreach ($lines($c['ai_red_flags']) as $l): ?><li><?= $e($l) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                            <?php if ($lines($c['ai_questions'])): ?><div><h4>Suggested interview questions</h4><ul class="hr-list"><?php foreach ($lines($c['ai_questions']) as $l): ?><li><?= $e($l) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                        </div>
                        <p class="hr-muted" style="margin-top:12px"><i class="fas fa-circle-info" aria-hidden="true"></i> AI screening is a guide for ranking. Read the application before shortlisting or rejecting.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i class="fas fa-file-lines" aria-hidden="true"></i> Application</h3></div>
                <div class="card-body">
                    <dl class="hr-dl">
                        <dt>Email</dt><dd><a href="mailto:<?= $e($c['applicant_email']) ?>"><?= $e($c['applicant_email']) ?></a></dd>
                        <dt>Phone</dt><dd><?= $e($c['applicant_phone']) ?></dd>
                        <dt>Location</dt><dd><?= $e($c['applicant_location'] ?: '-') ?></dd>
                        <dt>Submitted</dt><dd><?= $c['submitted_at'] ? $e(date('j M Y, H:i', strtotime((string)$c['submitted_at']))) : '-' ?></dd>
                        <dt>Availability</dt><dd><?= $e($c['availability'] ?: '-') ?></dd>
                        <dt>Notice period</dt><dd><?= $e($c['notice_period'] ?: '-') ?></dd>
                        <dt>Salary expectation</dt><dd><?= $e($c['salary_expectation'] ?: '-') ?></dd>
                        <?php foreach (['linkedin_url' => 'LinkedIn', 'portfolio_url' => 'Portfolio'] as $col => $lbl): if (!empty($c[$col]) && preg_match('#^https?://#i', (string)$c[$col])): ?>
                            <dt><?= $lbl ?></dt><dd><a href="<?= $e($c[$col]) ?>" target="_blank" rel="noopener noreferrer"><?= $e($c[$col]) ?></a></dd>
                        <?php endif; endforeach; ?>
                        <dt>Heard about us</dt><dd><?= $e($c['how_did_you_hear'] ?: '-') ?></dd>
                    </dl>
                    <div class="hr-inline" style="margin:14px 0">
                        <?php foreach (['cv_path' => 'CV', 'cover_letter_file_path' => 'Cover letter file', 'portfolio_file_path' => 'Portfolio file'] as $col => $lbl): if (!empty($c[$col])): ?>
                            <a class="btn btn-sm btn-secondary" href="<?= $e(ims_upload_url((string)$c[$col])) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip" aria-hidden="true"></i> <?= $lbl ?></a>
                        <?php endif; endforeach; ?>
                    </div>
                    <h4>Cover letter</h4>
                    <div class="hr-pre"><?= $e($c['cover_letter']) ?></div>
                    <?php if (!empty($c['additional_info'])): ?><h4 style="margin-top:14px">Additional information</h4><div class="hr-pre"><?= $e($c['additional_info']) ?></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="hr-col">
            <div class="card">
                <div class="card-header"><h3><?= $e($c['applicant_name']) ?></h3><?= $stageBadge((string)$c['stage']) ?></div>
                <div class="card-body">
                    <form method="POST" action="recruitment">
                        <?= csrf_field() ?>
                        <input type="hidden" name="job_id" value="<?= $jobId ?>">
                        <input type="hidden" name="action" value="stage">
                        <input type="hidden" name="ids[]" value="<?= $appId ?>">
                        <input type="hidden" name="return_app" value="<?= $appId ?>">
                        <div class="form-group">
                            <label for="hrStageOne">Move to stage</label>
                            <select id="hrStageOne" name="stage" class="form-control">
                                <?php foreach (array_keys(HR_STAGES) as $s): ?><option<?= $s === $c['stage'] ? ' selected' : '' ?>><?= $e($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <label class="hr-check"><input type="checkbox" name="notify" value="1"> Email the applicant (shortlisted, offer or regret message)</label>
                        <div class="form-group" style="margin-top:8px">
                            <label for="hrStageNote">Personal note for the email (optional)</label>
                            <textarea id="hrStageNote" name="note" class="form-control" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Update stage</button>
                    </form>
                    <hr>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="hrOpenInterview(<?= $appId ?>)"><i class="fas fa-calendar-plus" aria-hidden="true"></i> Schedule interview</button>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i class="fas fa-calendar-check" aria-hidden="true"></i> Interviews</h3></div>
                <div class="card-body">
                    <?php if (!$candInterviews): ?>
                        <p class="hr-muted">No interviews yet.</p>
                    <?php else: foreach ($candInterviews as $iv): ?>
                        <div style="margin-bottom:12px">
                            <strong><?= $e(date('D j M Y, H:i', strtotime((string)$iv['scheduled_at']))) ?></strong> · <?= $e($iv['mode']) ?> · <span class="badge badge-secondary"><?= $e($iv['status']) ?></span>
                            <div class="hr-muted">Panel: <?= $e($iv['panel_names'] ?: '-') ?> · <?= (int)$iv['submitted'] ?>/<?= (int)$iv['panel_size'] ?> scored</div>
                            <?php if ($iv['result']['pct'] !== null): ?><?= $scoreBar($iv['result']['pct']) ?><?php endif; ?>
                            <a class="btn btn-sm btn-soft" href="interview-scorecard?id=<?= (int)$iv['interview_id'] ?>">Results &amp; scorecards</a>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i class="fas fa-note-sticky" aria-hidden="true"></i> HR notes</h3></div>
                <div class="card-body">
                    <form method="POST" action="recruitment">
                        <?= csrf_field() ?>
                        <input type="hidden" name="job_id" value="<?= $jobId ?>">
                        <input type="hidden" name="action" value="notes">
                        <input type="hidden" name="application_id" value="<?= $appId ?>">
                        <input type="hidden" name="return_app" value="<?= $appId ?>">
                        <label for="hrNotes" class="sr-only">HR notes</label>
                        <textarea id="hrNotes" name="hr_notes" class="form-control" rows="5" placeholder="Private to HR: reference checks, salary discussion, impressions..."><?= $e($c['hr_notes']) ?></textarea>
                        <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:8px">Save notes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ================= One job ================= -->
    <div class="stats-grid reports-stats">
        <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Applicants</span><span class="stat-value"><?= array_sum($counts) ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-robot" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Waiting for AI screening</span><span class="stat-value"><?= $unscreened ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-teal"><i class="fas fa-list-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Shortlisted</span><span class="stat-value"><?= $counts['Shortlisted'] ?? 0 ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-comments" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Interviewing</span><span class="stat-value"><?= $counts['Interview'] ?? 0 ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-handshake" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Offer / hired</span><span class="stat-value"><?= ($counts['Offer'] ?? 0) + ($counts['Hired'] ?? 0) ?></span></div></div>
    </div>

    <div class="card">
        <nav class="tab-nav" aria-label="Recruitment views">
            <?php foreach ($views as $key => $label):
                $badge = match ($key) { 'criteria' => count($criteria), 'questions' => count($questions), default => 0 }; ?>
                <a class="tab-btn<?= $view === $key ? ' active' : '' ?>" href="recruitment?job_id=<?= $jobId ?>&view=<?= $key ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?><?php if ($badge): ?> <span class="badge"><?= $badge ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="card-body">

        <?php if ($view === 'candidates'): ?>
            <?php if (!$criteria): ?>
                <div class="alert alert-warning"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> No screening criteria yet: the AI will judge applicants against the job description only. <a href="recruitment?job_id=<?= $jobId ?>&view=criteria">Set criteria</a> for a fairer, consistent ranking.</div>
            <?php endif; ?>

            <div class="hr-toolbar">
                <div class="hr-toolbar-group">
                    <button type="button" class="btn btn-primary" onclick="hrScreenQueue(false, this)"<?= $unscreened ? '' : ' disabled' ?>><i class="fas fa-robot" aria-hidden="true"></i> AI screen new applicants (<?= $unscreened ?>)</button>
                    <button type="button" class="btn btn-secondary" onclick="hrScreenQueue(true, this)"><i class="fas fa-rotate" aria-hidden="true"></i> Re-screen all</button>
                    <button type="button" class="btn btn-secondary" onclick="openModal('hrTopModal')"><i class="fas fa-ranking-star" aria-hidden="true"></i> Shortlist top…</button>
                </div>
                <span id="hrScreenStatus" class="hr-muted" role="status" aria-live="polite"></span>
            </div>

            <?php $stageFilter = (string)($_GET['stage'] ?? 'active'); ?>
            <div class="hr-stage-pills">
                <a href="recruitment?job_id=<?= $jobId ?>&stage=active" class="<?= $stageFilter === 'active' ? 'active' : '' ?>">Active <span class="n"><?= array_sum($counts) - ($counts['Rejected'] ?? 0) ?></span></a>
                <?php foreach ($counts as $s => $n): ?>
                    <a href="recruitment?job_id=<?= $jobId ?>&stage=<?= urlencode($s) ?>" class="<?= $stageFilter === $s ? 'active' : '' ?>"><?= $e($s) ?> <span class="n"><?= $n ?></span></a>
                <?php endforeach; ?>
                <a href="recruitment?job_id=<?= $jobId ?>&stage=all" class="<?= $stageFilter === 'all' ? 'active' : '' ?>">All</a>
            </div>

            <form method="POST" action="recruitment" id="hrBulkForm">
                <?= csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                <input type="hidden" name="action" value="stage">
                <?php if (!$candidates): ?>
                    <div class="empty-state"><i class="fas fa-inbox" aria-hidden="true"></i><p>No applicants here yet. Share the job with <button type="button" class="btn btn-sm btn-soft" onclick="hrOpenEmbed(<?= $jobId ?>)">Embed &amp; share</button></p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-stack">
                            <thead><tr>
                                <th><label class="sr-only" for="hrAll">Select all</label><input type="checkbox" id="hrAll"></th>
                                <th>#</th><th>Applicant</th><th>AI score</th><th>Fit</th><th>Interview</th><th>Stage</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($candidates as $rank => $r): ?>
                                <tr>
                                    <td data-label="Select"><input type="checkbox" name="ids[]" value="<?= (int)$r['application_id'] ?>" class="hr-pick" aria-label="Select <?= $e($r['applicant_name']) ?>"></td>
                                    <td data-label="Rank"><span class="hr-rank"><?= $r['ai_score'] !== null && $r['stage'] !== 'Rejected' ? $rank + 1 : '–' ?></span></td>
                                    <td data-label="Applicant">
                                        <a class="hr-name" href="recruitment?job_id=<?= $jobId ?>&app=<?= (int)$r['application_id'] ?>"><?= $e($r['applicant_name']) ?></a>
                                        <div class="hr-muted"><?= $e($r['applicant_email']) ?> · applied <?= $r['submitted_at'] ? $e(date('j M', strtotime((string)$r['submitted_at']))) : '-' ?></div>
                                        <?php if ($r['ai_summary']): ?><div class="hr-muted" style="max-width:520px"><?= $e(mb_strimwidth((string)$r['ai_summary'], 0, 180, '…')) ?></div><?php endif; ?>
                                    </td>
                                    <td data-label="AI score" id="hrScore<?= (int)$r['application_id'] ?>"><?= $scoreBar($r['ai_score'], $r['ai_error'] ? 'Failed – retry' : '') ?></td>
                                    <td data-label="Fit">
                                        <?php if ($r['ai_fit']): ?><span class="badge <?= in_array($r['ai_fit'], ['Strong fit', 'Good fit'], true) ? 'badge-success' : ($r['ai_fit'] === 'Possible fit' ? 'badge-warning' : 'badge-danger') ?>"><?= $e($r['ai_fit']) ?></span><?php endif; ?>
                                        <?php if ((int)$r['must_have_missing']): ?><span class="badge badge-danger" title="Does not meet a must-have criterion">Must-have ✗</span><?php endif; ?>
                                    </td>
                                    <td data-label="Interview">
                                        <?php if ($r['interview_pct'] !== null): ?><?= $scoreBar($r['interview_pct']) ?>
                                        <?php elseif ($r['interview_at']): ?><span class="hr-muted"><?= $e(date('j M, H:i', strtotime((string)$r['interview_at']))) ?></span>
                                        <?php else: ?><span class="hr-muted">-</span><?php endif; ?>
                                    </td>
                                    <td data-label="Stage"><?= $stageBadge((string)$r['stage']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="hr-toolbar" style="margin-top:14px">
                        <div class="hr-toolbar-group">
                            <label for="hrBulkStage" class="sr-only">Move selected to</label>
                            <select id="hrBulkStage" name="stage" class="form-control" style="width:auto">
                                <?php foreach (array_keys(HR_STAGES) as $s): ?><option<?= $s === 'Shortlisted' ? ' selected' : '' ?>><?= $e($s) ?></option><?php endforeach; ?>
                            </select>
                            <label class="hr-check"><input type="checkbox" name="notify" value="1"> Email applicants</label>
                            <button type="submit" class="btn btn-primary" onclick="return hrBulkCheck()"><i class="fas fa-arrow-right" aria-hidden="true"></i> Move selected</button>
                        </div>
                        <span class="hr-muted">Shortlisted, Offer and Rejected have ready-made emails; add a note below if you like.</span>
                    </div>
                    <label for="hrBulkNote" class="sr-only">Note</label>
                    <textarea id="hrBulkNote" name="note" class="form-control" rows="2" placeholder="Optional note added to the email"></textarea>
                <?php endif; ?>
            </form>

        <?php elseif ($view === 'criteria'): ?>
            <p class="hr-muted">The AI scores every applicant 0–10 on each criterion and combines the scores by weight into a ranking score. Applicants who clearly fail a <strong>must-have</strong> are flagged and never auto-shortlisted. Be specific: “3+ years managing donor-funded M&amp;E systems” works better than “experience”.</p>
            <form method="POST" action="recruitment">
                <?= csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                <input type="hidden" name="action" value="criteria">
                <input type="hidden" name="return_view" value="criteria">
                <div class="hr-rows" id="hrCritRows">
                    <?php foreach (($criteria ?: [['criterion' => '', 'details' => '', 'weight' => 3, 'is_must_have' => 0]]) as $i => $cr): ?>
                        <div class="hr-row" data-row>
                            <div class="hr-row-num" data-num><?= $i + 1 ?></div>
                            <div class="hr-row-fields">
                                <div class="form-group"><label class="sr-only">Criterion</label><input name="criterion[]" class="form-control" maxlength="255" placeholder="e.g. Bachelor's degree in Statistics, Economics or related" value="<?= $e($cr['criterion']) ?>"></div>
                                <div class="form-group"><label class="sr-only">Weight</label>
                                    <select name="weight[]" class="form-control" title="Weight">
                                        <?php foreach ([1 => '1 · Nice to have', 2 => '2 · Useful', 3 => '3 · Important', 4 => '4 · Very important', 5 => '5 · Critical'] as $w => $lbl): ?><option value="<?= $w ?>"<?= (int)$cr['weight'] === $w ? ' selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group"><input type="hidden" name="must_have[]" value="<?= (int)$cr['is_must_have'] ?>" data-must><label class="hr-check"><input type="checkbox" data-must-box<?= (int)$cr['is_must_have'] ? ' checked' : '' ?>> Must-have</label></div>
                                <div class="form-group is-wide"><label class="sr-only">Details</label><input name="details[]" class="form-control" maxlength="2000" placeholder="What counts as evidence (optional)" value="<?= $e($cr['details']) ?>"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-soft" data-remove aria-label="Remove criterion"><i class="fas fa-trash" aria-hidden="true"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="hr-toolbar" style="margin-top:12px">
                    <button type="button" class="btn btn-secondary" data-add="hrCritRows"><i class="fas fa-plus" aria-hidden="true"></i> Add criterion</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save criteria</button>
                </div>
            </form>
            <?php $others = array_filter($allJobs, fn($j) => (int)$j['job_id'] !== $jobId && (int)$j['criteria'] > 0); if ($others && !$criteria): ?>
                <form method="POST" action="recruitment" class="hr-inline" style="margin-top:16px">
                    <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= $jobId ?>"><input type="hidden" name="action" value="copy"><input type="hidden" name="what" value="criteria"><input type="hidden" name="return_view" value="criteria">
                    <label for="hrCopyCrit">Or copy from</label>
                    <select id="hrCopyCrit" name="from_job" class="form-control"><?php foreach ($others as $o): ?><option value="<?= (int)$o['job_id'] ?>"><?= $e($o['job_title']) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-secondary btn-sm">Copy</button>
                </form>
            <?php endif; ?>

        <?php elseif ($view === 'questions'): ?>
            <p class="hr-muted">Every panel member scores the candidate on these questions in their scorecard. Add guidance on what a strong answer looks like so the panel scores consistently.</p>
            <form method="POST" action="recruitment">
                <?= csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                <input type="hidden" name="action" value="questions">
                <input type="hidden" name="return_view" value="questions">
                <div class="hr-rows" id="hrQRows">
                    <?php foreach (($questions ?: [['question_id' => 0, 'question' => '', 'guidance' => '', 'max_score' => 5]]) as $i => $q): ?>
                        <div class="hr-row" data-row>
                            <div class="hr-row-num" data-num><?= $i + 1 ?></div>
                            <div class="hr-row-fields is-q">
                                <input type="hidden" name="question_id[]" value="<?= (int)$q['question_id'] ?>" data-keep-id>
                                <div class="form-group"><label class="sr-only">Question</label><textarea name="question[]" class="form-control" rows="2" maxlength="2000" placeholder="e.g. Tell us about a time you designed a data collection tool from scratch."><?= $e($q['question']) ?></textarea></div>
                                <div class="form-group"><label class="sr-only">Score out of</label>
                                    <select name="max_score[]" class="form-control" title="Scored out of">
                                        <?php foreach ([3, 5, 10] as $m): ?><option value="<?= $m ?>"<?= (int)$q['max_score'] === $m ? ' selected' : '' ?>>Out of <?= $m ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group is-wide"><label class="sr-only">Guidance</label><input name="guidance[]" class="form-control" maxlength="2000" placeholder="What a strong answer includes (shown to the panel)" value="<?= $e($q['guidance']) ?>"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-soft" data-remove aria-label="Remove question"><i class="fas fa-trash" aria-hidden="true"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="hr-toolbar" style="margin-top:12px">
                    <button type="button" class="btn btn-secondary" data-add="hrQRows"><i class="fas fa-plus" aria-hidden="true"></i> Add question</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save questions</button>
                </div>
            </form>
            <?php $othersQ = array_filter($allJobs, fn($j) => (int)$j['job_id'] !== $jobId); if ($othersQ && !$questions): ?>
                <form method="POST" action="recruitment" class="hr-inline" style="margin-top:16px">
                    <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= $jobId ?>"><input type="hidden" name="action" value="copy"><input type="hidden" name="what" value="questions"><input type="hidden" name="return_view" value="questions">
                    <label for="hrCopyQ">Or copy from</label>
                    <select id="hrCopyQ" name="from_job" class="form-control"><?php foreach ($othersQ as $o): ?><option value="<?= (int)$o['job_id'] ?>"><?= $e($o['job_title']) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-secondary btn-sm">Copy</button>
                </form>
            <?php endif; ?>

        <?php else: ?>
            <?php if (!$questions): ?>
                <div class="alert alert-warning"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Add <a href="recruitment?job_id=<?= $jobId ?>&view=questions">interview questions</a> so the panel has something to score.</div>
            <?php endif; ?>
            <?php if (!$interviews): ?>
                <div class="empty-state"><i class="fas fa-calendar" aria-hidden="true"></i><p>No interviews scheduled. Shortlist applicants, then use <strong>Schedule interview</strong>.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr><th>Candidate</th><th>When</th><th>Panel</th><th>Scored</th><th>Result</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($interviews as $iv): ?>
                            <tr>
                                <td data-label="Candidate"><a class="hr-name" href="recruitment?job_id=<?= $jobId ?>&app=<?= (int)$iv['application_id'] ?>"><?= $e($iv['applicant_name']) ?></a></td>
                                <td data-label="When"><?= $e(date('D j M Y, H:i', strtotime((string)$iv['scheduled_at']))) ?><div class="hr-muted"><?= $e($iv['mode']) ?><?= $iv['location'] ? ' · ' . $e($iv['location']) : '' ?></div></td>
                                <td data-label="Panel"><?= $e($iv['panel_names'] ?: '-') ?></td>
                                <td data-label="Scored"><?= (int)$iv['submitted'] ?>/<?= (int)$iv['panel_size'] ?></td>
                                <td data-label="Result"><?= $scoreBar($iv['result']['pct'], 'Awaiting scores') ?></td>
                                <td data-label="Status"><span class="badge <?= $iv['status'] === 'Completed' ? 'badge-success' : ($iv['status'] === 'Cancelled' ? 'badge-danger' : 'badge-info') ?>"><?= $e($iv['status']) ?></span></td>
                                <td data-label="Actions">
                                    <div class="hr-inline">
                                        <a class="btn btn-sm btn-soft" href="interview-scorecard?id=<?= (int)$iv['interview_id'] ?>" title="Results and scorecards"><i class="fas fa-clipboard-list" aria-hidden="true"></i></a>
                                        <?php if ($iv['status'] !== 'Cancelled'): ?>
                                            <button type="button" class="btn btn-sm btn-soft" title="Edit or reschedule" onclick='hrOpenInterview(<?= (int)$iv["application_id"] ?>, <?= json_encode([
                                                'interview_id' => (int)$iv['interview_id'],
                                                'date' => date('Y-m-d', strtotime((string)$iv['scheduled_at'])),
                                                'time' => date('H:i', strtotime((string)$iv['scheduled_at'])),
                                                'duration_minutes' => (int)$iv['duration_minutes'],
                                                'mode' => $iv['mode'], 'location' => (string)$iv['location'], 'meeting_link' => (string)$iv['meeting_link'],
                                                'candidate_message' => (string)$iv['candidate_message'],
                                                'panel' => array_map('intval', array_column(hr_panel($conn, (int)$iv['interview_id']), 'user_id')),
                                            ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fas fa-pen" aria-hidden="true"></i></button>
                                            <form method="POST" action="recruitment" style="display:inline">
                                                <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= $jobId ?>"><input type="hidden" name="return_view" value="interviews">
                                                <input type="hidden" name="interview_id" value="<?= (int)$iv['interview_id'] ?>">
                                                <?php if ((int)$iv['submitted'] < (int)$iv['panel_size']): ?>
                                                    <button class="btn btn-sm btn-soft" name="action" value="remind_panel" title="Remind panel to score"><i class="fas fa-bell" aria-hidden="true"></i></button>
                                                <?php endif; ?>
                                                <?php if ($iv['status'] === 'Scheduled'): ?>
                                                    <input type="hidden" name="notify" value="1">
                                                    <button class="btn btn-sm btn-soft" name="action" value="interview_status" onclick="this.form.status.value='Completed'" title="Mark completed"><i class="fas fa-check" aria-hidden="true"></i></button>
                                                    <button class="btn btn-sm btn-danger" name="action" value="interview_status" onclick="if(!confirm('Cancel this interview and tell the candidate and panel?'))return false;this.form.status.value='Cancelled'" title="Cancel interview"><i class="fas fa-xmark" aria-hidden="true"></i></button>
                                                <?php endif; ?>
                                                <input type="hidden" name="status" value="">
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($job): ?>
<!-- Shortlist top N -->
<div id="hrTopModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hrTopTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="hrTopTitle"><i class="fas fa-ranking-star" aria-hidden="true"></i> Shortlist the top applicants</h3>
            <button type="button" class="close" aria-label="Close" onclick="closeModal('hrTopModal')">&times;</button>
        </div>
        <form method="POST" action="recruitment">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                <input type="hidden" name="action" value="shortlist_top">
                <p class="hr-muted">Moves the highest-scoring screened applicants (New or Screened, meeting every must-have) to Shortlisted.</p>
                <div class="hr-form-grid">
                    <div class="form-group"><label for="hrTopN">How many</label><input id="hrTopN" type="number" name="top_n" min="1" max="200" value="5" class="form-control"></div>
                    <div class="form-group"><label for="hrTopMin">Minimum AI score (%)</label><input id="hrTopMin" type="number" name="min_score" min="0" max="100" step="1" value="50" class="form-control"></div>
                    <div class="form-group is-wide"><label class="hr-check"><input type="checkbox" name="notify" value="1"> Email the shortlisted applicants</label></div>
                    <div class="form-group is-wide"><label for="hrTopNote">Note for the email (optional)</label><textarea id="hrTopNote" name="note" class="form-control" rows="2"></textarea></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('hrTopModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Shortlist</button>
            </div>
        </form>
    </div>
</div>

<!-- Schedule / edit interview -->
<div id="hrInterviewModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hrIvTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="hrIvTitle"><i class="fas fa-calendar-plus" aria-hidden="true"></i> <span>Schedule interview</span></h3>
            <button type="button" class="close" aria-label="Close" onclick="closeModal('hrInterviewModal')">&times;</button>
        </div>
        <form method="POST" action="recruitment" id="hrIvForm">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                <input type="hidden" name="action" value="interview">
                <input type="hidden" name="interview_id" value="0">
                <input type="hidden" name="return_view" value="interviews">
                <div class="hr-form-grid">
                    <div class="form-group is-wide">
                        <label for="hrIvApp" class="required">Candidate</label>
                        <select id="hrIvApp" name="application_id" class="form-control" required>
                            <option value="">Choose…</option>
                            <?php foreach ($schedulable as $s): ?>
                                <option value="<?= (int)$s['application_id'] ?>"><?= $e($s['applicant_name']) ?> · <?= $e($s['stage']) ?><?= $s['ai_score'] !== null ? ' · ' . $e(round((float)$s['ai_score'])) . '%' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label for="hrIvDate" class="required">Date</label><input id="hrIvDate" type="date" name="date" class="form-control" required min="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label for="hrIvTime" class="required">Time</label><input id="hrIvTime" type="time" name="time" class="form-control" required value="10:00"></div>
                    <div class="form-group"><label for="hrIvDur">Duration (minutes)</label><input id="hrIvDur" type="number" name="duration_minutes" min="10" max="480" step="5" value="45" class="form-control"></div>
                    <div class="form-group"><label for="hrIvMode">Format</label>
                        <select id="hrIvMode" name="mode" class="form-control"><?php foreach (HR_INTERVIEW_MODES as $m): ?><option><?= $e($m) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="form-group"><label for="hrIvLoc">Location / phone</label><input id="hrIvLoc" name="location" class="form-control" maxlength="255" placeholder="e.g. Board room, 2nd floor"></div>
                    <div class="form-group"><label for="hrIvLink">Meeting link</label><input id="hrIvLink" type="url" name="meeting_link" class="form-control" maxlength="500" placeholder="https://meet.google.com/..."></div>
                    <div class="form-group is-wide">
                        <label>Interview panel (staff)</label>
                        <input type="search" class="form-control hr-picker-search" placeholder="Filter staff…" aria-label="Filter staff" data-filter="hrIvPanel">
                        <div class="hr-picker" id="hrIvPanel">
                            <?php foreach ($staff as $s): ?>
                                <label data-name="<?= $e(mb_strtolower($s['full_name'] . ' ' . $s['role'])) ?>"><input type="checkbox" name="panel[]" value="<?= (int)$s['user_id'] ?>"<?= (int)$s['user_id'] === $uid ? ' checked' : '' ?>> <span><?= $e($s['full_name']) ?> <small><?= $e($s['role']) ?></small></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group is-wide"><label for="hrIvMsg">Message to the candidate (optional)</label><textarea id="hrIvMsg" name="candidate_message" class="form-control" rows="3" placeholder="e.g. Please bring your original certificates and a national ID."></textarea></div>
                    <div class="form-group is-wide">
                        <label class="hr-check"><input type="checkbox" name="notify_candidate" value="1" checked> Email the invitation to the candidate</label>
                        <label class="hr-check"><input type="checkbox" name="notify_panel" value="1" checked> Invite the panel (in-app + email with the scorecard link)</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('hrInterviewModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Save &amp; send invitations</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/hr-embed-modal.php'; ?>

<script>
(function () {
    const csrf = <?= json_encode(csrf_token()) ?>;
    const jobId = <?= $jobId ?>;

    async function post(data) {
        const body = new URLSearchParams(Object.assign({ csrf_token: csrf, job_id: jobId, ajax: 1 }, data));
        const res = await fetch('recruitment', { method: 'POST', body: body, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
        return res.json();
    }

    window.hrScreen = async function (ids, btn) {
        const status = document.getElementById('hrScreenStatus');
        if (btn) btn.disabled = true;
        let ok = 0, failed = 0, lastError = '';
        for (let i = 0; i < ids.length; i++) {
            if (status) status.textContent = 'AI screening ' + (i + 1) + ' of ' + ids.length + '…';
            const cell = document.getElementById('hrScore' + ids[i]);
            if (cell) cell.innerHTML = '<span class="hr-muted"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Screening…</span>';
            try {
                const r = await post({ action: 'ai_screen', application_id: ids[i] });
                if (r.ok) { ok++; if (cell) cell.innerHTML = '<b>' + r.score + '%</b> <span class="hr-muted">' + r.fit + '</span>'; }
                else { failed++; lastError = r.message; if (cell) cell.innerHTML = '<span class="text-danger">Failed</span>'; if (/no active AI API/i.test(r.message)) break; }
            } catch (e) { failed++; lastError = 'Network error'; }
        }
        if (status) status.textContent = ok + ' screened' + (failed ? ', ' + failed + ' failed: ' + lastError : '') + '. Refreshing ranking…';
        if (!status && failed) alert(lastError);
        setTimeout(() => location.reload(), failed ? 2500 : 800);
    };

    window.hrScreenQueue = async function (all, btn) {
        if (all && !confirm('Re-screen every active applicant for this job? This uses your AI API quota.')) return;
        btn.disabled = true;
        const r = await post({ action: 'screen_queue', all: all ? 1 : 0 });
        if (!r.ok || !r.ids.length) { document.getElementById('hrScreenStatus').textContent = 'Nothing to screen.'; btn.disabled = false; return; }
        hrScreen(r.ids, btn);
    };

    window.hrBulkCheck = function () {
        if (!document.querySelector('.hr-pick:checked')) { alert('Select at least one applicant.'); return false; }
        const stage = document.getElementById('hrBulkStage').value;
        return stage !== 'Rejected' || confirm('Reject the selected applicants?');
    };
    const all = document.getElementById('hrAll');
    if (all) all.addEventListener('change', () => document.querySelectorAll('.hr-pick').forEach((c) => { c.checked = all.checked; }));

    // Repeating rows (criteria / questions)
    function renumber(list) { list.querySelectorAll('[data-row]').forEach((row, i) => { const n = row.querySelector('[data-num]'); if (n) n.textContent = i + 1; }); }
    document.addEventListener('click', (ev) => {
        const add = ev.target.closest('[data-add]');
        if (add) {
            const list = document.getElementById(add.dataset.add);
            const tpl = list.querySelector('[data-row]').cloneNode(true);
            tpl.querySelectorAll('input:not([type=checkbox]), textarea').forEach((f) => { f.value = f.hasAttribute('data-keep-id') || f.hasAttribute('data-must') ? '0' : ''; });
            tpl.querySelectorAll('input[type=checkbox]').forEach((f) => { f.checked = false; });
            tpl.querySelectorAll('select').forEach((s) => { s.selectedIndex = s.name === 'weight[]' ? 2 : (s.name === 'max_score[]' ? 1 : 0); });
            list.appendChild(tpl);
            renumber(list);
            const first = tpl.querySelector('input:not([type=hidden]), textarea');
            if (first) first.focus();
        }
        const rm = ev.target.closest('[data-remove]');
        if (rm) {
            const list = rm.closest('.hr-rows');
            const row = rm.closest('[data-row]');
            if (list.querySelectorAll('[data-row]').length > 1) row.remove();
            else row.querySelectorAll('input:not([type=checkbox]), textarea').forEach((f) => { f.value = f.hasAttribute('data-keep-id') || f.hasAttribute('data-must') ? '0' : ''; });
            renumber(list);
        }
    });
    document.addEventListener('change', (ev) => {
        if (ev.target.matches('[data-must-box]')) {
            ev.target.closest('.form-group').querySelector('[data-must]').value = ev.target.checked ? '1' : '0';
        }
    });

    // Staff filter in pickers
    document.querySelectorAll('[data-filter]').forEach((input) => input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        document.querySelectorAll('#' + input.dataset.filter + ' label').forEach((l) => { l.hidden = q !== '' && !l.dataset.name.includes(q); });
    }));

    window.hrOpenInterview = function (appId, data) {
        const form = document.getElementById('hrIvForm');
        if (!form) return;
        form.reset();
        data = data || {};
        form.interview_id.value = data.interview_id || 0;
        document.querySelector('#hrIvTitle span').textContent = data.interview_id ? 'Edit or reschedule interview' : 'Schedule interview';
        const sel = form.application_id;
        if (appId && !sel.querySelector('option[value="' + appId + '"]')) {
            sel.add(new Option('Applicant #' + appId, appId));
        }
        sel.value = appId || '';
        sel.disabled = !!data.interview_id;
        ['date', 'time', 'duration_minutes', 'mode', 'location', 'meeting_link', 'candidate_message'].forEach((k) => { if (data[k] !== undefined) form[k].value = data[k]; });
        if (data.panel) form.querySelectorAll('input[name="panel[]"]').forEach((c) => { c.checked = data.panel.includes(parseInt(c.value, 10)); });
        openModal('hrInterviewModal');
    };
    const ivForm = document.getElementById('hrIvForm');
    if (ivForm) ivForm.addEventListener('submit', (ev) => {
        if (!ivForm.querySelector('input[name="panel[]"]:checked')) { ev.preventDefault(); alert('Choose at least one panel member.'); return; }
        ivForm.application_id.disabled = false;
    });
    <?php if (isset($_GET['schedule']) && $job): ?>hrOpenInterview(<?= (int)$_GET['schedule'] ?>);<?php endif; ?>
})();
</script>
<?php include 'includes/footer.php'; ?>
