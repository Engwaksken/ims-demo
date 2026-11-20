<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| My HR (every member of staff)
|--------------------------------------------------------------------------
| view=policies    policies HR shared with me: open, acknowledge.
| view=documents   my employee profile status and required documents.
| view=interviews  interview panels I am on, with links to my scorecards.
*/

$page_title = 'My HR';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hr.php';
check_role(IMS_STAFF_ROLES);

$uid = (int)$_SESSION['user_id'];
$e = 'hr_h';

if (!hr_ensure_schema($conn)) {
    include 'includes/header.php';
    echo '<div class="card"><div class="card-body"><div class="alert alert-warning">My HR is unavailable right now. Please try again later.</div></div></div>';
    include 'includes/footer.php';
    exit;
}

// Open a policy: record that it was opened, then hand over to the download endpoint.
if (isset($_GET['open'])) {
    $row = hr_mark_policy_viewed($conn, (int)$_GET['open'], $uid);
    if (!$row || ($url = ims_upload_url((string)$row['file_path'])) === '') {
        $_SESSION['notification'] = ['message' => 'That policy is not available to you.', 'type' => 'danger'];
        header('Location: my-hr');
        exit;
    }
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }
    $result = ($_POST['action'] ?? '') === 'acknowledge' && !empty($_POST['confirm'])
        ? hr_acknowledge_policy($conn, (int)($_POST['policy_id'] ?? 0), $uid)
        : hr_result(false, 'Tick the box to confirm you have read the policy.');
    $_SESSION['notification'] = ['message' => $result['message'], 'type' => $result['ok'] ? 'success' : 'warning'];
    header('Location: my-hr?view=policies', true, 303);
    exit;
}

$policies = hr_my_policies($conn, $uid);
$pendingPolicies = array_filter($policies, fn($p) => (int)$p['requires_ack'] === 1 && (empty($p['acknowledged_at']) || (int)$p['acknowledged_version'] !== (int)$p['version']));
$me = hr_employees($conn, ['user_id' => $uid])[0] ?? null;
$settings = hr_settings($conn);
$profile = hr_row($conn, 'SELECT * FROM employee_directory WHERE user_id = ? ORDER BY employee_id DESC LIMIT 1', 'i', [$uid]);
$interviews = hr_my_interviews($conn, $uid);
$toScore = array_filter($interviews, fn($i) => empty($i['submitted_at']));

$views = ['policies' => 'Policies', 'documents' => 'My documents', 'interviews' => 'Interview panels'];
$default = $pendingPolicies ? 'policies' : ($toScore ? 'interviews' : ($me && $me['missing_docs'] ? 'documents' : 'policies'));
$view = array_key_exists((string)($_GET['view'] ?? ''), $views) ? (string)$_GET['view'] : $default;

include 'includes/header.php';
?>
<link rel="stylesheet" href="css/hr.css">

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-id-card-clip" aria-hidden="true"></i> My HR</h1>
            <p>Read and acknowledge organisation policies, keep your documents up to date and score interviews you sit on.</p>
        </div>
        <div class="hero-actions">
            <a href="my-profile" class="btn btn-primary"><i class="fas fa-user-pen" aria-hidden="true"></i> My profile &amp; documents</a>
        </div>
    </div>

    <div class="stats-grid reports-stats">
        <div class="stat-card" data-stat-href="my-hr?view=policies"><div class="stat-icon bg-red"><i class="fas fa-file-signature" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Policies to acknowledge</span><span class="stat-value"><?= count($pendingPolicies) ?></span></div></div>
        <div class="stat-card" data-stat-href="my-hr?view=documents"><div class="stat-icon bg-amber"><i class="fas fa-file-circle-exclamation" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Documents missing</span><span class="stat-value"><?= $me ? count($me['missing_docs']) : count($settings['required_docs_list']) ?></span></div></div>
        <div class="stat-card" data-stat-href="my-hr?view=interviews"><div class="stat-icon bg-purple"><i class="fas fa-clipboard-list" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Scorecards to submit</span><span class="stat-value"><?= count($toScore) ?></span></div></div>
    </div>

    <div class="card">
        <nav class="tab-nav" aria-label="My HR views">
            <?php foreach ($views as $key => $label):
                $badge = match ($key) { 'policies' => count($pendingPolicies), 'documents' => $me ? count($me['missing_docs']) : 0, 'interviews' => count($toScore) }; ?>
                <a class="tab-btn<?= $view === $key ? ' active' : '' ?>" href="my-hr?view=<?= $key ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?><?php if ($badge): ?> <span class="badge"><?= $badge ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="card-body">
        <?php if ($view === 'policies'): ?>
            <?php if (!$policies): ?>
                <div class="empty-state"><i class="fas fa-file-shield" aria-hidden="true"></i><p>No policies have been shared with you yet.</p></div>
            <?php else: foreach ($policies as $p):
                $pending = (int)$p['requires_ack'] === 1 && (empty($p['acknowledged_at']) || (int)$p['acknowledged_version'] !== (int)$p['version']); ?>
                <div class="hr-policy-card<?= $pending ? ' is-pending' : '' ?>">
                    <div class="hr-policy-icon"><i class="fas fa-file-lines" aria-hidden="true"></i></div>
                    <div class="hr-policy-body">
                        <h4><?= $e($p['title']) ?> <span class="badge badge-secondary">v<?= (int)$p['version'] ?></span></h4>
                        <div class="hr-muted"><?= $e($p['category']) ?> · shared <?= $e(date('j M Y', strtotime((string)$p['shared_at']))) ?><?= $p['viewed_at'] ? ' · opened' : '' ?></div>
                        <?php if ($p['description']): ?><p class="hr-pre" style="margin:8px 0"><?= $e($p['description']) ?></p><?php endif; ?>
                        <div class="hr-inline" style="margin-top:8px">
                            <a class="btn btn-sm btn-secondary" href="my-hr?open=<?= (int)$p['policy_id'] ?>" target="_blank" rel="noopener"><i class="fas fa-book-open" aria-hidden="true"></i> Read policy</a>
                            <?php if ($pending): ?>
                                <form method="POST" action="my-hr" class="hr-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="acknowledge">
                                    <input type="hidden" name="policy_id" value="<?= (int)$p['policy_id'] ?>">
                                    <label class="hr-check"><input type="checkbox" name="confirm" value="1" required> I have read and understood this policy and agree to follow it</label>
                                    <button class="btn btn-sm btn-primary"><i class="fas fa-signature" aria-hidden="true"></i> Acknowledge</button>
                                </form>
                            <?php elseif ((int)$p['requires_ack'] === 1): ?>
                                <span class="badge badge-success"><i class="fas fa-check" aria-hidden="true"></i> Acknowledged <?= $e(date('j M Y', strtotime((string)$p['acknowledged_at']))) ?></span>
                            <?php else: ?>
                                <span class="hr-muted">For information</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>

        <?php elseif ($view === 'documents'): ?>
            <?php if (!$profile): ?>
                <div class="alert alert-warning"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> You have not created your employee profile yet. <a href="my-profile">Create it now</a> and upload your documents.</div>
            <?php else: ?>
                <p>Profile status: <span class="badge <?= match ($profile['status']) { 'Approved' => 'badge-success', 'Submitted' => 'badge-info', 'Rejected' => 'badge-danger', default => 'badge-secondary' } ?>"><?= $e($profile['status']) ?></span>
                    <?php if ($profile['status'] === 'Rejected' && !empty($profile['rejection_reason'])): ?><br><span class="text-danger">HR feedback: <?= $e($profile['rejection_reason']) ?></span><?php endif; ?></p>
            <?php endif; ?>
            <ul class="hr-checklist">
                <?php foreach (HR_DOCS as $col => $label):
                    $required = in_array($col, $settings['required_docs_list'], true);
                    $has = $profile && trim((string)($profile[$col] ?? '')) !== '';
                    if (!$required && !$has) { continue; } ?>
                    <li class="<?= $has ? 'done' : 'todo' ?>">
                        <i class="fas <?= $has ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" aria-hidden="true"></i>
                        <span><?= $e($label) ?><?= $required ? '' : ' <small class="hr-muted">(optional)</small>' ?></span>
                        <?php if ($has): ?>
                            <a class="btn btn-sm btn-soft" href="<?= $e(ims_upload_url((string)$profile[$col])) ?>" target="_blank" rel="noopener">View</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-primary" href="my-profile">Upload</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

        <?php else: ?>
            <?php if (!$interviews): ?>
                <div class="empty-state"><i class="fas fa-user-tie" aria-hidden="true"></i><p>You are not on any interview panels.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr><th>Candidate</th><th>Position</th><th>When</th><th>Your scorecard</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($interviews as $i): ?>
                            <tr>
                                <td data-label="Candidate"><strong><?= $e($i['applicant_name']) ?></strong></td>
                                <td data-label="Position"><?= $e($i['job_title']) ?></td>
                                <td data-label="When"><?= $e(date('D j M Y, H:i', strtotime((string)$i['scheduled_at']))) ?><div class="hr-muted"><?= $e($i['mode']) ?><?= $i['location'] ? ' · ' . $e($i['location']) : '' ?></div></td>
                                <td data-label="Your scorecard"><?= $i['submitted_at'] ? '<span class="badge badge-success">Submitted</span>' : '<span class="badge badge-warning">To do</span>' ?></td>
                                <td data-label=""><a class="btn btn-sm btn-primary" href="interview-scorecard?id=<?= (int)$i['interview_id'] ?>"><?= $i['submitted_at'] ? 'View' : 'Score' ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
