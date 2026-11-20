<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Interview scorecard
|--------------------------------------------------------------------------
| Panel members score the candidate on the job's interview questions and
| give a recommendation (draft, then submit). Panel members only ever see
| their own scorecard, so scoring stays independent. HR / Administrators
| (recruitment.manage) see every panelist's scores and the combined result.
*/

$page_title = 'Interview scorecard';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hr.php';
check_login();

$uid = (int)$_SESSION['user_id'];
$e = 'hr_h';
$interviewId = (int)($_GET['id'] ?? $_POST['interview_id'] ?? 0);

if (!hr_ensure_schema($conn)) {
    $_SESSION['notification'] = ['message' => 'Interviews are unavailable right now.', 'type' => 'danger'];
    header('Location: my-hr');
    exit;
}

$interview = $interviewId > 0 ? hr_interview($conn, $interviewId) : null;
$isManager = hr_can_recruit();
$isPanelist = $interview && hr_is_panelist($conn, $interviewId, $uid);
if (!$interview || (!$isPanelist && !$isManager)) {
    $_SESSION['notification'] = ['message' => 'That interview was not found or you are not on its panel.', 'type' => 'danger'];
    header('Location: my-hr?view=interviews');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }
    $result = hr_save_scorecard($conn, $interviewId, $uid, $_POST);
    $_SESSION['notification'] = ['message' => $result['message'], 'type' => $result['ok'] ? 'success' : 'warning'];
    header('Location: interview-scorecard?id=' . $interviewId, true, 303);
    exit;
}

$questions = hr_questions($conn, (int)$interview['job_id']);
$panel = hr_panel($conn, $interviewId);
$mine = $isPanelist ? hr_my_scores($conn, $interviewId, $uid) : [];
$myPanel = null;
foreach ($panel as $p) {
    if ((int)$p['user_id'] === $uid) {
        $myPanel = $p;
    }
}
$result = $isManager ? hr_interview_result($conn, $interviewId) : null;
$allScores = [];
if ($isManager) {
    foreach (hr_rows($conn, 'SELECT user_id, question_id, score, comment FROM hr_interview_scores WHERE interview_id = ?', 'i', [$interviewId]) as $s) {
        $allScores[(int)$s['user_id']][(int)$s['question_id']] = $s;
    }
}
$cand = hr_candidate($conn, (int)$interview['application_id']);

include 'includes/header.php';
?>
<link rel="stylesheet" href="css/hr.css">

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-clipboard-list" aria-hidden="true"></i> <?= $e($interview['applicant_name']) ?></h1>
            <p><?= $e($interview['job_title']) ?> · <?= $e(hr_interview_when($interview)) ?> · <?= $e($interview['mode']) ?><?= $interview['location'] ? ' · ' . $e($interview['location']) : '' ?></p>
        </div>
        <div class="hero-actions">
            <?php if ($isManager): ?>
                <a href="recruitment?job_id=<?= (int)$interview['job_id'] ?>&view=interviews" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Recruitment</a>
            <?php endif; ?>
            <a href="my-hr?view=interviews" class="btn"><i class="fas fa-user-clock" aria-hidden="true"></i> My interviews</a>
            <?php if (!empty($interview['meeting_link']) && preg_match('#^https?://#i', (string)$interview['meeting_link'])): ?>
                <a href="<?= $e($interview['meeting_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary"><i class="fas fa-video" aria-hidden="true"></i> Join meeting</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($interview['status'] === 'Cancelled'): ?>
        <div class="alert alert-warning">This interview was cancelled.</div>
    <?php endif; ?>

    <div class="hr-grid-2">
        <div class="hr-col">
            <?php if ($isPanelist): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-pen-to-square" aria-hidden="true"></i> Your scorecard</h3>
                    <?php if (!empty($myPanel['submitted_at'])): ?>
                        <span class="badge badge-success">Submitted <?= $e(date('j M, H:i', strtotime((string)$myPanel['submitted_at']))) ?></span>
                    <?php else: ?>
                        <span class="badge badge-warning">Not submitted</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$questions): ?>
                        <div class="alert alert-warning">HR has not added interview questions for this job yet. You can still give your recommendation and comments.</div>
                    <?php endif; ?>
                    <form method="POST" action="interview-scorecard">
                        <?= csrf_field() ?>
                        <input type="hidden" name="interview_id" value="<?= $interviewId ?>">
                        <?php foreach ($questions as $n => $q):
                            $qid = (int)$q['question_id'];
                            $cur = $mine[$qid]['score'] ?? null; ?>
                            <fieldset class="hr-q">
                                <legend class="hr-q-title"><?= $n + 1 ?>. <?= $e($q['question']) ?></legend>
                                <?php if ($q['guidance']): ?><div class="hr-q-guide"><i class="fas fa-lightbulb" aria-hidden="true"></i> <?= $e($q['guidance']) ?></div><?php endif; ?>
                                <div class="hr-scale" role="radiogroup" aria-label="Score out of <?= (int)$q['max_score'] ?>">
                                    <?php for ($s = 0; $s <= (int)$q['max_score']; $s++): ?>
                                        <label><input type="radio" name="score[<?= $qid ?>]" value="<?= $s ?>"<?= $cur !== null && (int)$cur === $s ? ' checked' : '' ?>><span><?= $s ?></span></label>
                                    <?php endfor; ?>
                                    <span class="hr-muted" style="align-self:center">out of <?= (int)$q['max_score'] ?></span>
                                </div>
                                <label class="sr-only" for="hrC<?= $qid ?>">Comment</label>
                                <textarea id="hrC<?= $qid ?>" name="comment[<?= $qid ?>]" class="form-control" rows="2" placeholder="Evidence from the answer (optional)"><?= $e($mine[$qid]['comment'] ?? '') ?></textarea>
                            </fieldset>
                        <?php endforeach; ?>

                        <div class="form-group" style="margin-top:14px">
                            <label for="hrVerdict" class="required">Your recommendation</label>
                            <select id="hrVerdict" name="verdict" class="form-control">
                                <option value="">Choose…</option>
                                <?php foreach (HR_PANEL_VERDICTS as $v): ?><option<?= ($myPanel['verdict'] ?? '') === $v ? ' selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="hrOverall">Overall comments</label>
                            <textarea id="hrOverall" name="overall_comment" class="form-control" rows="4"><?= $e($myPanel['overall_comment'] ?? '') ?></textarea>
                        </div>
                        <?php if ($interview['status'] !== 'Cancelled'): ?>
                            <div class="hr-inline">
                                <button type="submit" name="intent" value="draft" class="btn btn-secondary"><i class="fas fa-save" aria-hidden="true"></i> Save draft</button>
                                <button type="submit" name="intent" value="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> <?= !empty($myPanel['submitted_at']) ? 'Update submitted scores' : 'Submit scores' ?></button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isManager): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-column" aria-hidden="true"></i> Panel results</h3>
                    <?php if ($result['pct'] !== null): ?><span class="badge badge-info">Average <?= $e($result['pct']) ?>%</span><?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$panel): ?>
                        <p class="hr-muted">No panel members.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="hr-crit">
                                <thead>
                                    <tr><th>Question</th>
                                        <?php foreach ($panel as $p): ?><th><?= $e($p['full_name']) ?><?= empty($p['submitted_at']) ? ' <span class="hr-muted">(draft)</span>' : '' ?></th><?php endforeach; ?>
                                        <th>Average</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($questions as $n => $q): $qid = (int)$q['question_id']; ?>
                                    <tr>
                                        <td><?= $n + 1 ?>. <?= $e(mb_strimwidth((string)$q['question'], 0, 90, '…')) ?></td>
                                        <?php foreach ($panel as $p): $s = $allScores[(int)$p['user_id']][$qid] ?? null; ?>
                                            <td title="<?= $e($s['comment'] ?? '') ?>"><?= $s && $s['score'] !== null ? (int)$s['score'] . '/' . (int)$q['max_score'] : '<span class="hr-muted">-</span>' ?><?php if (!empty($s['comment'])): ?> <i class="fas fa-comment hr-muted" aria-label="Has comment"></i><?php endif; ?></td>
                                        <?php endforeach; ?>
                                        <td><strong><?= isset($result['by_question'][$qid]) ? $e($result['by_question'][$qid]['avg']) . '/' . (int)$q['max_score'] : '-' ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                    <tr>
                                        <th>Total</th>
                                        <?php foreach ($panel as $p): $u = $result['by_user'][(int)$p['user_id']] ?? null; ?>
                                            <th><?= $u ? $e($u['pct']) . '%' : '-' ?></th>
                                        <?php endforeach; ?>
                                        <th><?= $result['pct'] !== null ? $e($result['pct']) . '%' : '-' ?></th>
                                    </tr>
                                    <tr>
                                        <th>Recommendation</th>
                                        <?php foreach ($panel as $p): ?><td><?= $e($p['verdict'] ?: '-') ?></td><?php endforeach; ?>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <?php foreach ($panel as $p): if (trim((string)$p['overall_comment']) === '') { continue; } ?>
                            <div style="margin-top:12px"><strong><?= $e($p['full_name']) ?>:</strong> <span class="hr-pre"><?= $e($p['overall_comment']) ?></span></div>
                        <?php endforeach; ?>
                        <p class="hr-muted" style="margin-top:10px">Only submitted scorecards count towards the averages. Hover a score to read the comment.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="hr-col">
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-user" aria-hidden="true"></i> Candidate</h3></div>
                <div class="card-body">
                    <dl class="hr-dl">
                        <dt>Position</dt><dd><?= $e($interview['job_title']) ?></dd>
                        <dt>When</dt><dd><?= $e(hr_interview_when($interview)) ?></dd>
                        <dt>Format</dt><dd><?= $e($interview['mode']) ?><?= $interview['location'] ? ' · ' . $e($interview['location']) : '' ?></dd>
                        <dt>Panel</dt><dd><?= $e(implode(', ', array_column($panel, 'full_name'))) ?></dd>
                    </dl>
                    <div class="hr-inline" style="margin-top:12px">
                        <?php foreach (['cv_path' => 'CV', 'cover_letter_file_path' => 'Cover letter'] as $col => $lbl): if (!empty($interview[$col])): ?>
                            <a class="btn btn-sm btn-secondary" href="<?= $e(ims_upload_url((string)$interview[$col])) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip" aria-hidden="true"></i> <?= $lbl ?></a>
                        <?php endif; endforeach; ?>
                    </div>
                    <?php if ($cand && $cand['ai_summary']): ?>
                        <h4 style="margin-top:14px">Screening summary</h4>
                        <p class="hr-pre"><?= $e($cand['ai_summary']) ?></p>
                    <?php endif; ?>
                    <?php if ($cand && trim((string)$cand['ai_questions']) !== ''): ?>
                        <h4>Suggested follow-up questions</h4>
                        <ul class="hr-list"><?php foreach (array_filter(array_map('trim', explode("\n", (string)$cand['ai_questions']))) as $l): ?><li><?= $e($l) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
