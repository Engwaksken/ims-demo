<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Event outreach: invite people and thank attendees
|--------------------------------------------------------------------------
| Invite tab: email an invitation (details, register/join link, .ics) to
| startups, members, participants, attendees of earlier events and typed-in
| email addresses. Thank tab: email attendees a thank-you. Both have a
| preview step; the logic lives in includes/event-outreach.php.
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/event-outreach.php';

const EVENT_OUTREACH_ROLES = ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer', 'Program Director', 'Program Manager', 'Program Officer'];
check_role(EVENT_OUTREACH_ROLES);

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ev_links_ensure_schema($conn);
$schemaReady = evo_ensure_schema($conn);

$eventId = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);
$event = evo_find_event($conn, $eventId);
if (!$event) {
    $_SESSION['notification'] = ['message' => 'Event not found.', 'type' => 'danger'];
    header('Location: events');
    exit;
}

$tab = in_array((string)($_REQUEST['tab'] ?? ''), ['invite', 'thank'], true) ? (string)$_REQUEST['tab'] : 'invite';
$userId = (int)($_SESSION['user_id'] ?? 0);
$isCancelled = strcasecmp((string)($event['event_status'] ?? ''), 'Cancelled') === 0;
$days = ev_event_days($event);
$lastDay = $days ? end($days) : '';
$hasHappened = $lastDay !== '' && $lastDay <= date('Y-m-d');
$isMeeting = ev_is_meeting_type((string)($event['event_type'] ?? ''));

$defaults = [
    'invite_subject' => "You're invited: {event}",
    'invite_message' => "We would be delighted to have you join us for {event}.\n\n"
        . ($isMeeting ? 'Use the link below to join on the day.' : 'Please register using the link or QR code below so we can plan for you.'),
    'thank_subject' => 'Thank you for attending {event}',
    'thank_message' => "Thank you for attending {event} on {date}. We truly appreciate the time you spent with us and your contribution to the discussions.\n\n"
        . 'We look forward to seeing you at our next event.',
];

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = static fn(string $key, string $default = ''): string => $post ? trim((string)($_POST[$key] ?? '')) : $default;
$checked = static fn(string $key, bool $default = false): bool => $post ? !empty($_POST[$key]) : $default;

$inviteOpts = [
    'audiences' => $post ? (array)($_POST['audiences'] ?? []) : ['startups'],
    'startup_status' => $val('startup_status'),
    'opportunity_id' => (int)$val('opportunity_id', '0'),
    'past_event_ids' => (array)($_POST['past_event_ids'] ?? []),
    'past_attended_only' => $checked('past_attended_only', true),
    'manual' => mb_substr($val('manual'), 0, 20000),
    'resend' => $checked('resend'),
];
$thankOpts = [
    'include_confirmed' => $checked('include_confirmed'),
    'include_thanked' => $checked('include_thanked'),
];
$form = [
    'invite_subject' => mb_substr($val('invite_subject', $defaults['invite_subject']), 0, 200) ?: $defaults['invite_subject'],
    'invite_message' => mb_substr($val('invite_message', $defaults['invite_message']), 0, 5000),
    'thank_subject' => mb_substr($val('thank_subject', $defaults['thank_subject']), 0, 200) ?: $defaults['thank_subject'],
    'thank_message' => mb_substr($val('thank_message', $defaults['thank_message']), 0, 5000),
    'thank_link' => mb_substr($val('thank_link'), 0, 500),
    'thank_link_label' => mb_substr($val('thank_link_label', 'Share your feedback'), 0, 60),
];

$redirect = static function (string $message, string $type) use ($eventId, $tab): never {
    $_SESSION['notification'] = ['message' => $message, 'type' => $type];
    header('Location: event-outreach?id=' . $eventId . '&tab=' . $tab);
    exit;
};

$preview = null;
$errors = [];

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/
if ($post) {
    $do = (string)($_POST['do'] ?? 'preview');

    if (!$schemaReady) {
        $errors[] = 'The invitation tables could not be created. Run database/migrations/2026_11_17_event_outreach.sql and try again.';
    } elseif ($tab === 'invite') {
        if ($isCancelled) {
            $errors[] = 'This event is cancelled, so invitations cannot be sent.';
        }
        if (!array_intersect(array_keys(EVO_AUDIENCES), $inviteOpts['audiences'])) {
            $errors[] = 'Choose at least one group to invite.';
        }
        if (trim($form['invite_message']) === '') {
            $errors[] = 'Write a message for the invitation.';
        }

        if (!$errors) {
            $preview = evo_invite_recipients($conn, $eventId, $inviteOpts);

            if ($do === 'send') {
                if (!$preview['people']) {
                    $errors[] = 'Nobody to invite: everyone selected has already registered or been invited.';
                } else {
                    $result = evo_send_invites($conn, $event, $preview['people'], $form['invite_subject'], $form['invite_message'], $userId);
                    if ($result['error'] !== '') {
                        $errors[] = $result['error'];
                    } else {
                        if (function_exists('log_action')) {
                            log_action($userId, 'Send Event Invitations', 'hub_events', $eventId, $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
                        }
                        $more = count($preview['people']) - EVO_MAX_SEND;
                        $redirect(
                            $result['sent'] . ' invitation' . ($result['sent'] === 1 ? '' : 's') . ' sent'
                            . ($result['failed'] ? ', ' . $result['failed'] . ' failed (see the error log)' : '')
                            . ($more > 0 ? '. ' . $more . ' more are waiting: send again to reach them.' : '.'),
                            $result['failed'] ? 'warning' : 'success'
                        );
                    }
                }
            }
        }
    } else {
        $link = $form['thank_link'];
        if ($link !== '') {
            $link = ev_clean_meeting_link($link);
            if ($link === '') {
                $errors[] = 'The link must be a valid web address (https://...).';
            }
            $form['thank_link'] = $link;
        }
        if (trim($form['thank_message']) === '') {
            $errors[] = 'Write a thank-you message.';
        }

        if (!$errors && $do === 'send') {
            $people = evo_appreciation_recipients($conn, $eventId, $thankOpts['include_confirmed'], $thankOpts['include_thanked']);
            if (!$people) {
                $errors[] = 'Nobody to thank: no attendees with an email address are waiting for a thank-you.';
            } else {
                $result = evo_send_appreciation($conn, $event, $people, $form['thank_subject'], $form['thank_message'], $form['thank_link'], $form['thank_link_label']);
                if ($result['error'] !== '') {
                    $errors[] = $result['error'];
                } else {
                    if (function_exists('log_action')) {
                        log_action($userId, 'Send Event Appreciation', 'hub_events', $eventId, $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
                    }
                    $redirect(
                        $result['sent'] . ' thank-you email' . ($result['sent'] === 1 ? '' : 's') . ' sent'
                        . ($result['failed'] ? ', ' . $result['failed'] . ' failed (see the error log).' : '.'),
                        $result['failed'] ? 'warning' : 'success'
                    );
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/
$startupStatuses = $schemaReady ? evo_startup_statuses($conn) : [];
$opportunities = $schemaReady ? evo_opportunities($conn) : [];
$pastEvents = $schemaReady ? evo_past_events($conn, $eventId) : [];
$inviteLog = $schemaReady ? evo_invite_log($conn, $eventId) : [];
$attendees = $schemaReady ? evo_appreciation_recipients($conn, $eventId, true, true) : [];
$thankQueue = $schemaReady ? evo_appreciation_recipients($conn, $eventId, $thankOpts['include_confirmed'], $thankOpts['include_thanked']) : [];
$thankedCount = count(array_filter($attendees, static fn($p) => $p['thanked_at'] !== '' && $p['status'] === 'Attended'));
$attendedCount = count(array_filter($attendees, static fn($p) => $p['status'] === 'Attended'));

$fmt = static fn($dt, string $format = 'j M Y, H:i'): string => !empty($dt) && strtotime((string)$dt) ? date($format, strtotime((string)$dt)) : '';
$tabUrl = static fn(string $t): string => 'event-outreach?id=' . $eventId . '&tab=' . $t;
$audienceOn = static fn(string $key): bool => in_array($key, (array)$inviteOpts['audiences'], true);

$page_title = 'Invite & Thank';
include 'includes/header.php';
?>

<style>
.eo-tabs{display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:4px;flex-wrap:wrap}
.eo-tabs a{padding:10px 16px;font-weight:700;color:var(--text-muted,#6b7280);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
.eo-tabs a[aria-current="page"]{color:var(--primary,#2563eb);border-bottom-color:currentColor}
.eo-groups{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
.eo-group{border:1px solid var(--border);border-radius:10px;padding:12px 14px}
.eo-group > label{display:flex;gap:8px;align-items:center;font-weight:700;cursor:pointer}
.eo-group .eo-sub{margin-top:10px;display:grid;gap:8px}
.eo-group:not(.is-on) .eo-sub{display:none}
.eo-group.is-on{border-color:var(--primary,#2563eb)}
.eo-past{max-height:220px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:6px 10px}
.eo-past label{display:flex;gap:8px;align-items:flex-start;padding:4px 0;font-weight:400}
.eo-fields{display:grid;gap:12px;margin-top:16px}
.eo-fields textarea{min-height:130px}
.eo-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.eo-check{display:flex;gap:8px;align-items:center}
.eo-summary{display:flex;flex-wrap:wrap;gap:8px 18px;margin-bottom:10px}
</style>

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-envelope-open-text" aria-hidden="true"></i> <?= $h($event['event_title']) ?></h1>
            <p>Invite people and thank attendees · <?= $h(evo_when_text($event)) ?><?= !empty($event['venue']) ? ' · ' . $h($event['venue']) : '' ?></p>
        </div>
        <div class="hero-actions">
            <a href="events" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Events</a>
            <a href="event-report?id=<?= $eventId ?>" class="btn btn-secondary"><i class="fas fa-chart-column" aria-hidden="true"></i> Report</a>
            <a href="event-attendance?id=<?= $eventId ?>" class="btn btn-secondary"><i class="fas fa-user-check" aria-hidden="true"></i> Mark attendance</a>
        </div>
    </div>

    <nav class="eo-tabs" aria-label="Outreach">
        <a href="<?= $h($tabUrl('invite')) ?>"<?= $tab === 'invite' ? ' aria-current="page"' : '' ?>><i class="fas fa-paper-plane" aria-hidden="true"></i> Invite people</a>
        <a href="<?= $h($tabUrl('thank')) ?>"<?= $tab === 'thank' ? ' aria-current="page"' : '' ?>><i class="fas fa-heart" aria-hidden="true"></i> Thank attendees <span class="badge"><?= $attendedCount ?></span></a>
    </nav>

    <?php if (!$schemaReady): ?>
        <div class="alert alert-danger">The invitation tables could not be created automatically. Run <code>database/migrations/2026_11_17_event_outreach.sql</code>.</div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger" role="alert"><?= $h($error) ?></div>
    <?php endforeach; ?>

<?php if ($tab === 'invite'): ?>
    <?php if ($isCancelled): ?>
        <div class="alert alert-warning">This event is cancelled. Invitations are switched off.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-users" aria-hidden="true"></i> Who to invite</h3></div>
        <form method="POST" action="event-outreach" class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="tab" value="invite">

            <div class="eo-groups">
                <div class="eo-group<?= $audienceOn('startups') ? ' is-on' : '' ?>">
                    <label><input type="checkbox" name="audiences[]" value="startups"<?= $audienceOn('startups') ? ' checked' : '' ?>> <i class="fas fa-rocket" aria-hidden="true"></i> Startups</label>
                    <div class="eo-sub">
                        <label class="form-label" for="eoStartupStatus">Application status</label>
                        <select id="eoStartupStatus" name="startup_status" class="form-control">
                            <option value="">Any status (not drafts)</option>
                            <?php foreach ($startupStatuses as $s): ?><option value="<?= $h($s) ?>"<?= $inviteOpts['startup_status'] === $s ? ' selected' : '' ?>><?= $h($s) ?></option><?php endforeach; ?>
                        </select>
                        <label class="form-label" for="eoOpportunity">Opportunity / cohort</label>
                        <select id="eoOpportunity" name="opportunity_id" class="form-control">
                            <option value="0">All opportunities</option>
                            <?php foreach ($opportunities as $o): ?><option value="<?= (int)$o['opportunity_id'] ?>"<?= $inviteOpts['opportunity_id'] === (int)$o['opportunity_id'] ? ' selected' : '' ?>><?= $h($o['opportunity_title']) ?> (<?= (int)$o['applications'] ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="eo-group<?= $audienceOn('members') ? ' is-on' : '' ?>">
                    <label><input type="checkbox" name="audiences[]" value="members"<?= $audienceOn('members') ? ' checked' : '' ?>> <i class="fas fa-id-card" aria-hidden="true"></i> Members</label>
                    <div class="eo-sub"><p class="form-hint">All active members with an email address.</p></div>
                </div>

                <div class="eo-group<?= $audienceOn('participants') ? ' is-on' : '' ?>">
                    <label><input type="checkbox" name="audiences[]" value="participants"<?= $audienceOn('participants') ? ' checked' : '' ?>> <i class="fas fa-user-group" aria-hidden="true"></i> Participants</label>
                    <div class="eo-sub"><p class="form-hint">Programme participants (beneficiaries) with an email address.</p></div>
                </div>

                <div class="eo-group<?= $audienceOn('past') ? ' is-on' : '' ?>">
                    <label><input type="checkbox" name="audiences[]" value="past"<?= $audienceOn('past') ? ' checked' : '' ?>> <i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Past event attendees</label>
                    <div class="eo-sub">
                        <label class="eo-check"><input type="checkbox" name="past_attended_only" value="1"<?= $inviteOpts['past_attended_only'] ? ' checked' : '' ?>> Only people marked Attended</label>
                        <span class="form-label">Events (none ticked = all events)</span>
                        <div class="eo-past" role="group" aria-label="Past events">
                            <?php foreach ($pastEvents as $pe): ?>
                                <label>
                                    <input type="checkbox" name="past_event_ids[]" value="<?= (int)$pe['event_id'] ?>"<?= in_array((string)$pe['event_id'], array_map('strval', $inviteOpts['past_event_ids']), true) ? ' checked' : '' ?>>
                                    <span><?= $h($pe['event_title']) ?> <span class="cell-muted">· <?= $h($fmt($pe['event_date'], 'j M Y')) ?> · <?= (int)$pe['attended'] ?>/<?= (int)$pe['registrations'] ?> attended</span></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (!$pastEvents): ?><p class="cell-muted">No other events have registrations yet.</p><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="eo-group<?= $audienceOn('manual') ? ' is-on' : '' ?>">
                    <label><input type="checkbox" name="audiences[]" value="manual"<?= $audienceOn('manual') ? ' checked' : '' ?>> <i class="fas fa-at" aria-hidden="true"></i> Other email addresses</label>
                    <div class="eo-sub">
                        <label class="form-label" for="eoManual">One per line: <code>Name &lt;email&gt;</code>, <code>Name, email</code> or just the email</label>
                        <textarea id="eoManual" name="manual" class="form-control" rows="5" placeholder="Jane Doe <jane@example.com>&#10;partner@example.org"><?= $h($inviteOpts['manual']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="eo-fields">
                <div>
                    <label class="form-label" for="eoInviteSubject">Subject</label>
                    <input id="eoInviteSubject" type="text" name="invite_subject" class="form-control" maxlength="200" value="<?= $h($form['invite_subject']) ?>">
                </div>
                <div>
                    <label class="form-label" for="eoInviteMessage">Message</label>
                    <textarea id="eoInviteMessage" name="invite_message" class="form-control" maxlength="5000"><?= $h($form['invite_message']) ?></textarea>
                    <p class="form-hint">Use {name}, {event} and {date}. The event details, the <?= $isMeeting ? 'meeting link' : 'registration link and QR code' ?> and a calendar file are added below your message.</p>
                </div>
                <label class="eo-check"><input type="checkbox" name="resend" value="1"<?= $inviteOpts['resend'] ? ' checked' : '' ?>> Send again to people already invited (people who have registered are always skipped)</label>
            </div>

            <div class="eo-actions">
                <button type="submit" name="do" value="preview" class="btn btn-secondary"><i class="fas fa-eye" aria-hidden="true"></i> Preview recipients</button>
                <button type="submit" name="do" value="send" class="btn btn-primary"<?= $isCancelled ? ' disabled' : '' ?>><i class="fas fa-paper-plane" aria-hidden="true"></i> Send invitations</button>
            </div>
        </form>
    </div>

    <?php if ($preview !== null): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-list-check" aria-hidden="true"></i> Recipients <span class="badge"><?= count($preview['people']) ?></span></h3></div>
            <div class="card-body">
                <div class="eo-summary cell-muted">
                    <span><?= count($preview['people']) ?> will be invited</span>
                    <span><?= (int)$preview['skipped_registered'] ?> already registered (skipped)</span>
                    <span><?= (int)$preview['skipped_invited'] ?> already invited (skipped<?= $inviteOpts['resend'] ? '' : ', tick "send again" to include' ?>)</span>
                    <?php if (count($preview['people']) > EVO_MAX_SEND): ?><span>Only the first <?= EVO_MAX_SEND ?> are sent per click.</span><?php endif; ?>
                </div>
                <?php if ($preview['people']): ?>
                    <div class="table-responsive">
                        <table class="table table-stack">
                            <thead><tr><th scope="col">#</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Organisation</th><th scope="col">Group</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($preview['people'], 0, EVO_MAX_SEND) as $i => $p): ?>
                                    <tr>
                                        <td data-label="#" class="cell-muted"><?= $i + 1 ?></td>
                                        <td data-label="Name"><?= $h($p['name']) ?></td>
                                        <td data-label="Email"><?= $h($p['email']) ?></td>
                                        <td data-label="Organisation"><?= $h($p['organization']) ?: '<span class="cell-muted">-</span>' ?></td>
                                        <td data-label="Group"><span class="badge"><?= $h(EVO_AUDIENCES[$p['audience']] ?? $p['audience']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-user-slash" aria-hidden="true"></i><p>Nobody new to invite with these choices.</p></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Invitations sent <span class="badge"><?= count($inviteLog) ?></span></h3></div>
        <div class="card-body">
            <?php if (!$inviteLog): ?>
                <div class="empty-state"><i class="fas fa-envelope" aria-hidden="true"></i><p>No invitations sent for this event yet.</p></div>
            <?php else: ?>
                <p class="cell-muted"><?= count(array_filter($inviteLog, static fn($r) => !empty($r['registered']))) ?> of <?= count($inviteLog) ?> invited people have registered.</p>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Group</th><th scope="col">Last sent</th><th scope="col">Times</th><th scope="col">Registered</th></tr></thead>
                        <tbody>
                            <?php foreach ($inviteLog as $r): ?>
                                <tr>
                                    <td data-label="Name"><?= $h($r['full_name']) ?><?php if (!empty($r['organization'])): ?><div class="cell-muted"><?= $h($r['organization']) ?></div><?php endif; ?></td>
                                    <td data-label="Email"><?= $h($r['email']) ?></td>
                                    <td data-label="Group"><?= $h(EVO_AUDIENCES[$r['audience']] ?? $r['audience']) ?></td>
                                    <td data-label="Last sent" class="cell-muted"><?= $h($fmt($r['last_sent_at'])) ?></td>
                                    <td data-label="Times"><?= (int)$r['send_count'] ?></td>
                                    <td data-label="Registered"><?= !empty($r['registered']) ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">Not yet</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <?php if (!$hasHappened): ?>
        <div class="alert alert-warning">This event has not finished yet. Thank-you emails are usually sent after the last day, once attendance is marked.</div>
    <?php endif; ?>

    <div class="stats-grid reports-stats">
        <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-user-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Attended (with email)</span><span class="stat-value"><?= $attendedCount ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-heart" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Already thanked</span><span class="stat-value"><?= $thankedCount ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-paper-plane" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Will receive this email</span><span class="stat-value"><?= count($thankQueue) ?></span></div></div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-heart" aria-hidden="true"></i> Attendance appreciation email</h3></div>
        <form method="POST" action="event-outreach" class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="tab" value="thank">

            <div class="eo-fields" style="margin-top:0">
                <label class="eo-check"><input type="checkbox" name="include_confirmed" value="1"<?= $thankOpts['include_confirmed'] ? ' checked' : '' ?>> Also thank people who confirmed but were not marked Attended</label>
                <label class="eo-check"><input type="checkbox" name="include_thanked" value="1"<?= $thankOpts['include_thanked'] ? ' checked' : '' ?>> Send again to people already thanked</label>
                <div>
                    <label class="form-label" for="eoThankSubject">Subject</label>
                    <input id="eoThankSubject" type="text" name="thank_subject" class="form-control" maxlength="200" value="<?= $h($form['thank_subject']) ?>">
                </div>
                <div>
                    <label class="form-label" for="eoThankMessage">Message</label>
                    <textarea id="eoThankMessage" name="thank_message" class="form-control" maxlength="5000"><?= $h($form['thank_message']) ?></textarea>
                    <p class="form-hint">Use {name}, {event} and {date}. Each person gets one email, even for multi-day events.</p>
                </div>
                <div class="eo-groups">
                    <div>
                        <label class="form-label" for="eoThankLink">Link (optional): feedback form, slides, photos</label>
                        <input id="eoThankLink" type="url" name="thank_link" class="form-control" maxlength="500" placeholder="https://" value="<?= $h($form['thank_link']) ?>">
                    </div>
                    <div>
                        <label class="form-label" for="eoThankLinkLabel">Button text</label>
                        <input id="eoThankLinkLabel" type="text" name="thank_link_label" class="form-control" maxlength="60" value="<?= $h($form['thank_link_label']) ?>">
                    </div>
                </div>
            </div>

            <div class="eo-actions">
                <button type="submit" name="do" value="preview" class="btn btn-secondary"><i class="fas fa-rotate" aria-hidden="true"></i> Update list</button>
                <button type="submit" name="do" value="send" class="btn btn-primary"<?= $thankQueue ? '' : ' disabled' ?>><i class="fas fa-paper-plane" aria-hidden="true"></i> Send thank-you to <?= count($thankQueue) ?></button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-list" aria-hidden="true"></i> Attendees <span class="badge"><?= count($attendees) ?></span></h3></div>
        <div class="card-body">
            <?php if (!$attendees): ?>
                <div class="empty-state"><i class="fas fa-user-slash" aria-hidden="true"></i><p>Nobody is marked Attended or Confirmed yet. <a href="event-attendance?id=<?= $eventId ?>">Mark attendance</a> first.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr><th scope="col">#</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Thanked</th></tr></thead>
                        <tbody>
                            <?php foreach ($attendees as $i => $p): ?>
                                <tr>
                                    <td data-label="#" class="cell-muted"><?= $i + 1 ?></td>
                                    <td data-label="Name"><?= $h($p['name']) ?><?php if ($p['organization'] !== ''): ?><div class="cell-muted"><?= $h($p['organization']) ?></div><?php endif; ?></td>
                                    <td data-label="Email"><?= $h($p['email']) ?></td>
                                    <td data-label="Status"><span class="badge <?= $p['status'] === 'Attended' ? 'badge-success' : 'badge-warning' ?>"><?= $h($p['status']) ?></span></td>
                                    <td data-label="Thanked"><?= $p['thanked_at'] !== '' ? $h($fmt($p['thanked_at'])) : '<span class="cell-muted">Not yet</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
document.querySelectorAll('.eo-group > label > input[type="checkbox"]').forEach(function (box) {
    box.addEventListener('change', function () {
        box.closest('.eo-group').classList.toggle('is-on', box.checked);
    });
});
</script>

<?php include 'includes/footer.php'; ?>
