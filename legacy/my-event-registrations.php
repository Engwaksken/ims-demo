<?php
$page_title = 'My Event Registrations';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get member details
$member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    echo "<div class='alert alert-danger'>Member profile not found. Please contact administrator.</div>";
    include 'includes/footer.php';
    exit();
}

$member_id = $member_result->fetch_assoc()['member_id'];

// Cancel own registration (POST + CSRF via config.php; 24-hour rule as shown on the card)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cancel_registration'])) {
    $cancelId = (int) $_POST['cancel_registration'];
    $notice   = ['type' => 'error', 'message' => 'Registration not found.'];

    $stmt = $conn->prepare(
        "SELECT er.registration_status, e.event_date, e.start_time, e.event_title
           FROM event_registrations er
           JOIN hub_events e ON e.event_id = er.event_id
          WHERE er.registration_id = ? AND er.member_id = ?
          LIMIT 1"
    );
    $stmt->bind_param('ii', $cancelId, $member_id);
    $stmt->execute();
    $reg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($reg) {
        $hoursUntil = (strtotime($reg['event_date'] . ' ' . $reg['start_time']) - time()) / 3600;

        if ($reg['registration_status'] === 'Cancelled') {
            $notice = ['type' => 'info', 'message' => 'This registration is already cancelled.'];
        } elseif ($hoursUntil < 24) {
            $notice = ['type' => 'error', 'message' => 'Registrations can only be cancelled at least 24 hours before the event.'];
        } else {
            $stmt = $conn->prepare(
                "UPDATE event_registrations SET registration_status = 'Cancelled'
                  WHERE registration_id = ? AND member_id = ?"
            );
            $stmt->bind_param('ii', $cancelId, $member_id);
            $stmt->execute();
            $stmt->close();
            $notice = ['type' => 'success', 'message' => 'Your registration for "' . $reg['event_title'] . '" has been cancelled.'];
        }
    }

    $_SESSION['notification'] = $notice;
    header('Location: my-event-registrations');
    exit();
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $conn->real_escape_string(sanitize_input($_GET['status'])) : '';
$view = isset($_GET['view']) ? $conn->real_escape_string(sanitize_input($_GET['view'])) : 'upcoming';

// Build WHERE clause
$where = "er.member_id = $member_id";

// View filter
if ($view == 'upcoming') {
    $where .= " AND e.event_date >= CURDATE()";
} elseif ($view == 'past') {
    $where .= " AND e.event_date < CURDATE()";
}

// Status filter
if ($filter_status) {
    $where .= " AND er.registration_status = '$filter_status'";
}

// Fetch registrations
$registrations = [];
$query = "SELECT er.*, e.*, 
          (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.event_id AND registration_status != 'Cancelled') as total_registrations
          FROM event_registrations er
          INNER JOIN hub_events e ON er.event_id = e.event_id
          WHERE $where
          ORDER BY e.event_date DESC, e.start_time DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $registrations[] = $row;
}

// Calculate statistics
$stats = [];

// Total registrations
$stats['total'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations 
                                WHERE member_id = $member_id 
                                AND registration_status != 'Cancelled'")->fetch_assoc()['count'];

// Upcoming events
$stats['upcoming'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations er
                                   INNER JOIN hub_events e ON er.event_id = e.event_id
                                   WHERE er.member_id = $member_id 
                                   AND e.event_date >= CURDATE()
                                   AND er.registration_status != 'Cancelled'")->fetch_assoc()['count'];

// Attended events
$stats['attended'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations er
                                   INNER JOIN hub_events e ON er.event_id = e.event_id
                                   WHERE er.member_id = $member_id 
                                   AND er.check_in_time IS NOT NULL")->fetch_assoc()['count'];

// Pending payment
$stats['pending_payment'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations 
                                          WHERE member_id = $member_id 
                                          AND payment_status = 'Pending'
                                          AND registration_status != 'Cancelled'")->fetch_assoc()['count'];
?>

<style>
/* My event registrations: one card per registration with a status accent. */
.mer-list { display: flex; flex-direction: column; gap: var(--space-4); }

.mer-card {
    background: var(--surface-card);
    border: 1px solid var(--border);
    border-left: 4px solid var(--brand-500);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    padding: var(--space-6);
    transition: transform 0.2s, box-shadow 0.2s;
}

.mer-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.mer-card.pending { border-left-color: var(--amber-fg); }
.mer-card.confirmed { border-left-color: var(--green-fg); }
.mer-card.cancelled { border-left-color: var(--red-fg); opacity: 0.7; }
.mer-card.attended { border-left-color: var(--blue-fg); }

.mer-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: var(--space-5);
    flex-wrap: wrap;
    gap: var(--space-4);
}

.mer-title { flex: 1; min-width: 0; }

.mer-title h3 {
    font-size: var(--fs-xl);
    color: var(--ink-900);
    margin: 0 0 5px;
    overflow-wrap: anywhere;
}

.mer-type { color: var(--ink-300); font-size: var(--fs-base); }

.mer-badges {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: var(--space-2);
}

.mer-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-5);
    margin-bottom: var(--space-5);
}

.mer-detail { display: flex; align-items: center; gap: 10px; }
.mer-detail > i { color: var(--brand-500); font-size: 18px; width: 25px; }
.mer-detail .content { flex: 1; }
.mer-detail .label { font-size: var(--fs-xs); color: var(--ink-300); text-transform: uppercase; }
.mer-detail .value { font-size: var(--fs-md); color: var(--ink-900); font-weight: 600; }
.mer-detail .value.is-fee { color: var(--green-fg); }

.mer-countdown { margin-bottom: var(--space-4); }

.mer-info {
    background: var(--surface-hover);
    border: 1px solid var(--border);
    padding: var(--space-4);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-5);
}

.mer-info-row {
    display: flex;
    justify-content: space-between;
    gap: var(--space-3);
    margin-bottom: var(--space-2);
    font-size: var(--fs-base);
}

.mer-info-row:last-child { margin-bottom: 0; }
.mer-info-row > span { color: var(--ink-300); }
.mer-checked-in { color: var(--green-fg); }

.mer-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.mer-capacity { font-size: var(--fs-sm); display: inline-flex; align-items: center; gap: 5px; }

@media (max-width: 768px) {
    .mer-details { grid-template-columns: 1fr; }
    .mer-badges { align-items: flex-start; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-ticket-alt" aria-hidden="true"></i> My Event Registrations</h1>
        <p>View and manage your event registrations.</p>
    </div>
    <div class="hero-actions">
        <a href="hub-events" class="btn btn-primary"><i class="fas fa-plus-circle" aria-hidden="true"></i> Browse Events</a>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Registrations</span>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Upcoming Events</span>
            <span class="stat-value"><?php echo $stats['upcoming']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Events Attended</span>
            <span class="stat-value"><?php echo $stats['attended']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Payment</span>
            <span class="stat-value"><?php echo $stats['pending_payment']; ?></span>
        </div>
    </div>
</div>

<div class="card">
<!-- View Tabs -->
<nav class="tab-nav" aria-label="Registration views">
    <button type="button" class="tab-btn <?php echo $view == 'upcoming' ? 'active' : ''; ?>"
            onclick="window.location.href='my-event-registrations?view=upcoming'">
        <i class="fas fa-arrow-right" aria-hidden="true"></i> Upcoming Events
    </button>
    <button type="button" class="tab-btn <?php echo $view == 'past' ? 'active' : ''; ?>"
            onclick="window.location.href='my-event-registrations?view=past'">
        <i class="fas fa-history" aria-hidden="true"></i> Past Events
    </button>
    <button type="button" class="tab-btn <?php echo $view == 'all' ? 'active' : ''; ?>"
            onclick="window.location.href='my-event-registrations?view=all'">
        <i class="fas fa-list" aria-hidden="true"></i> All Registrations
    </button>
</nav>

<!-- Filter Bar -->
<form method="GET" action="" class="card-body">
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
    <div class="filters-bar" role="search" aria-label="Filter registrations">
        <div class="form-group">
            <label for="status">Registration Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Confirmed" <?php echo $filter_status == 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="Cancelled" <?php echo $filter_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                <option value="Waitlisted" <?php echo $filter_status == 'Waitlisted' ? 'selected' : ''; ?>>Waitlisted</option>
            </select>
        </div>

        <div class="filters-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="my-event-registrations?view=<?php echo htmlspecialchars($view); ?>" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </div>
</form>
</div>

<!-- Registrations List -->
<?php if (empty($registrations)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-calendar-times" aria-hidden="true"></i>
                <h4>No registrations found</h4>
                <p>
                    <?php if ($view == 'upcoming'): ?>
                        You don't have any upcoming event registrations.
                    <?php elseif ($view == 'past'): ?>
                        You haven't attended any past events.
                    <?php else: ?>
                        No registrations match your filter criteria.
                    <?php endif; ?>
                </p>
                <a href="hub-events" class="btn btn-primary">
                    <i class="fas fa-calendar-alt"></i> Browse Events
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
<div class="mer-list">
    <?php foreach ($registrations as $reg):
        // Determine card class
        $card_class = strtolower($reg['registration_status']);
        if ($reg['check_in_time']) {
            $card_class = 'attended';
        }

        // Calculate time until event
        $event_datetime = strtotime($reg['event_date'] . ' ' . $reg['start_time']);
        $hours_until = ($event_datetime - time()) / 3600;
        $days_until = ceil($hours_until / 24);

        // Check if event is in the past
        $is_past = strtotime($reg['event_date']) < strtotime('today');

        // Can cancel if registered and event is in future and more than 24 hours away
        $can_cancel = $reg['registration_status'] != 'Cancelled' && !$is_past && $hours_until >= 24;
    ?>
    <div class="mer-card <?php echo $card_class; ?>">
        <div class="mer-head">
            <div class="mer-title">
                <h3><?php echo htmlspecialchars($reg['event_title']); ?></h3>
                <div class="mer-type">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($reg['event_type']); ?>
                    <?php if ($reg['is_members_only']): ?>
                        | <i class="fas fa-lock"></i> Members Only
                    <?php endif; ?>
                </div>
            </div>
            <div class="mer-badges">
                <?php
                $status_badges = [
                    'Pending' => 'warning',
                    'Confirmed' => 'success',
                    'Cancelled' => 'danger',
                    'Waitlisted' => 'info'
                ];
                $badge = $status_badges[$reg['registration_status']] ?? 'secondary';
                ?>
                <span class="badge badge-<?php echo $badge; ?>">
                    <?php echo $reg['registration_status']; ?>
                </span>

                <?php if ($reg['check_in_time']): ?>
                    <span class="badge badge-success">
                        <i class="fas fa-check"></i> Checked In
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="mer-details">
            <div class="mer-detail">
                <i class="fas fa-calendar"></i>
                <div class="content">
                    <div class="label">Date</div>
                    <div class="value">
                        <?php echo date('d M Y', strtotime($reg['event_date'])); ?>
                        <br><small class="cell-muted"><?php echo date('l', strtotime($reg['event_date'])); ?></small>
                    </div>
                </div>
            </div>

            <div class="mer-detail">
                <i class="fas fa-clock"></i>
                <div class="content">
                    <div class="label">Time</div>
                    <div class="value">
                        <?php echo date('h:i A', strtotime($reg['start_time'])); ?> -
                        <?php echo date('h:i A', strtotime($reg['end_time'])); ?>
                    </div>
                </div>
            </div>

            <div class="mer-detail">
                <i class="fas fa-map-marker-alt"></i>
                <div class="content">
                    <div class="label">Venue</div>
                    <div class="value"><?php echo htmlspecialchars($reg['venue']); ?></div>
                </div>
            </div>

            <?php if ($reg['registration_fee'] > 0): ?>
            <div class="mer-detail">
                <i class="fas fa-tag"></i>
                <div class="content">
                    <div class="label">Fee</div>
                    <div class="value is-fee">
                        <?php echo format_currency($reg['registration_fee']); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$is_past && $hours_until > 0 && $hours_until <= 168): // Show countdown if within 7 days ?>
        <div class="mer-countdown">
            <span class="badge badge-warning">
                <i class="fas fa-hourglass-half"></i>
                <?php if ($days_until <= 1): ?>
                    Event starts in <?php echo round($hours_until); ?> hours
                <?php else: ?>
                    Event in <?php echo $days_until; ?> days
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- Registration Info -->
        <div class="mer-info">
            <div class="mer-info-row">
                <span>Registration Date:</span>
                <strong><?php echo date('d M Y, h:i A', strtotime($reg['created_at'])); ?></strong>
            </div>

            <?php if ($reg['attendee_name'] && $reg['attendee_name'] != ''): ?>
            <div class="mer-info-row">
                <span>Attendee Name:</span>
                <strong><?php echo htmlspecialchars($reg['attendee_name']); ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($reg['registration_fee'] > 0): ?>
            <div class="mer-info-row">
                <span>Payment Status:</span>
                <strong>
                    <?php
                    $payment_badges = [
                        'Pending' => 'warning',
                        'Paid' => 'success',
                        'Refunded' => 'danger'
                    ];
                    $payment_badge = $payment_badges[$reg['payment_status']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?php echo $payment_badge; ?>">
                        <?php echo $reg['payment_status']; ?>
                    </span>
                </strong>
            </div>
            <?php endif; ?>

            <?php if ($reg['payment_reference']): ?>
            <div class="mer-info-row">
                <span>Payment Reference:</span>
                <strong><code><?php echo htmlspecialchars($reg['payment_reference']); ?></code></strong>
            </div>
            <?php endif; ?>

            <?php if ($reg['check_in_time']): ?>
            <div class="mer-info-row">
                <span>Check-in Time:</span>
                <strong class="mer-checked-in">
                    <i class="fas fa-check-circle"></i>
                    <?php echo date('d M Y, h:i A', strtotime($reg['check_in_time'])); ?>
                </strong>
            </div>
            <?php endif; ?>

            <?php if ($reg['special_requirements']): ?>
            <div class="mer-info-row">
                <span>Special Requirements:</span>
                <strong><?php echo htmlspecialchars($reg['special_requirements']); ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="mer-actions">
            <a href="event-register?token=<?php echo rawurlencode(base64_encode('eid=' . (int)$reg['event_id'])); ?>" class="btn btn-info btn-sm">
                <i class="fas fa-eye"></i> View Event Details
            </a>

            <?php if ($can_cancel): ?>
                <button onclick="confirmCancelRegistration(<?php echo $reg['registration_id']; ?>, <?= htmlspecialchars(json_encode((string)($reg['event_title'])), ENT_QUOTES, 'UTF-8') ?>)"
                        class="btn btn-danger btn-sm">
                    <i class="fas fa-times"></i> Cancel Registration
                </button>
            <?php endif; ?>

            <?php if ($reg['max_attendees'] > 0): ?>
                <span class="cell-muted mer-capacity">
                    <i class="fas fa-users"></i>
                    <?php echo $reg['total_registrations']; ?> / <?php echo $reg['max_attendees']; ?> registered
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- /.page-stack -->

<form id="cancelRegistrationForm" method="POST" action="my-event-registrations" hidden>
    <input type="hidden" name="cancel_registration" value="">
</form>

<?php include 'includes/footer.php'; ?>

<script>
function confirmCancelRegistration(registrationId, eventTitle) {
    if (confirm(`Are you sure you want to cancel your registration for "${eventTitle}"?\n\nNote: Cancellations must be made at least 24 hours before the event.`)) {
        const form = document.getElementById('cancelRegistrationForm');
        form.elements['cancel_registration'].value = registrationId;
        form.submit();
    }
}
</script>