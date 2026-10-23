<?php
$page_title = 'Space Bookings Calendar';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if user has access (Admin/Operations or Members)
$is_admin = ims_allowed('bookings.view_all', ['Administrator', 'Operations/Admin'], 'See all bookings (not just their own)');
$is_member = isset($_SESSION['role']) && $_SESSION['role'] === 'Member';

if (!$is_admin && !$is_member) {
    $_SESSION['error'] = "Access denied.";
    header("Location: dashboard");
    exit();
}

// Get member_id if member
$member_id = null;
if ($is_member) {
    $member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
    $member_result = $conn->query($member_query);
    if ($member_result->num_rows > 0) {
        $member_id = $member_result->fetch_assoc()['member_id'];
    }
}

// Get all available spaces
$spaces = [];
$spaces_query = "SELECT * FROM space_availability ORDER BY space_name";
$spaces_result = $conn->query($spaces_query);
while ($row = $spaces_result->fetch_assoc()) {
    $spaces[] = $row;
}

// Get filter parameters
$filter_space = isset($_GET['space']) ? sanitize_input($_GET['space']) : '';
$filter_status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$filter_space_id = isset($_GET['space_id']) ? intval($_GET['space_id']) : 0;

// Calculate statistics (for admin)
$stats = [
    'total' => 0,
    'today' => 0,
    'pending' => 0,
    'confirmed' => 0
];

if ($is_admin) {
    $stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN booking_date = CURDATE() THEN 1 ELSE 0 END) as today,
                    SUM(CASE WHEN booking_status = 'Pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN booking_status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed
                    FROM space_bookings
                    WHERE booking_status NOT IN ('Cancelled', 'Completed')";
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch_assoc();
}
?>

<style>
/* Space bookings calendar: toolbar, legend and list-view rows. */
.vsb-filters { margin-bottom: 0; }

.vsb-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
    flex-wrap: wrap;
    margin-bottom: var(--space-5);
    padding-bottom: var(--space-4);
    border-bottom: 1px solid var(--ink-100);
}

.vsb-nav,
.vsb-toggle {
    display: flex;
    gap: var(--space-2);
    align-items: center;
}

.vsb-month {
    font-size: var(--fs-xl);
    font-weight: 700;
    color: var(--ink-900);
}

#calendar {
    min-height: 600px;
}

.vsb-legend {
    display: flex;
    gap: var(--space-5);
    flex-wrap: wrap;
    margin-top: var(--space-5);
    padding-top: var(--space-4);
    border-top: 1px solid var(--ink-100);
}

.vsb-legend-item {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--fs-sm);
    color: var(--ink-500);
}

.vsb-swatch {
    width: 16px;
    height: 16px;
    border-radius: var(--radius-sm);
}

.vsb-swatch.is-pending   { background: var(--amber-fg); }
.vsb-swatch.is-confirmed { background: var(--green-fg); }
.vsb-swatch.is-cancelled { background: var(--red-fg); }
.vsb-swatch.is-completed { background: var(--blue-fg); }

.list-view {
    display: none;
}

.list-view.active,
.calendar-view.active {
    display: block;
}

.vsb-booking {
    padding: var(--space-4);
    margin-bottom: var(--space-3);
    border: 1px solid var(--ink-100);
    border-left: 4px solid var(--brand-500);
    border-radius: var(--radius-md);
    background: var(--surface-card);
}

.vsb-booking.pending   { border-left-color: var(--amber-fg); }
.vsb-booking.confirmed { border-left-color: var(--green-fg); }
.vsb-booking.cancelled { border-left-color: var(--red-fg); opacity: 0.7; }
.vsb-booking.completed { border-left-color: var(--blue-fg); }

.vsb-booking-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-3);
}

.vsb-booking-title {
    font-size: var(--fs-lg);
    font-weight: 600;
    color: var(--ink-900);
}

.vsb-booking-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-3);
    margin-top: var(--space-3);
}

.vsb-detail {
    font-size: var(--fs-sm);
    color: var(--ink-300);
}

.vsb-detail strong {
    display: block;
    margin-bottom: 3px;
    color: var(--ink-900);
}

.vsb-booking-actions {
    margin-top: var(--space-3);
}

.fc-event {
    cursor: pointer;
}

.fc-event:hover {
    opacity: 0.8;
}

@media (max-width: 768px) {
    .vsb-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .vsb-nav,
    .vsb-toggle {
        justify-content: space-between;
    }

    .vsb-booking-details {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- FullCalendar v6 injects its own CSS from index.global.min.js (there is no separate CSS file; the old link 404'd). -->

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-calendar-alt" aria-hidden="true"></i> Space Bookings Calendar</h1>
        <p>View and manage space bookings across all facilities.</p>
    </div>
    <?php if ($is_member || $is_admin): ?>
    <div class="hero-actions">
        <?php if ($is_member): ?>
            <a href="my-bookings" class="btn">
                <i class="fas fa-list" aria-hidden="true"></i> My Bookings
            </a>
            <a href="book-space" class="btn btn-primary">
                <i class="fas fa-plus" aria-hidden="true"></i> Book a Space
            </a>
        <?php endif; ?>
        <?php if ($is_admin): ?>
            <a href="manage-space" class="btn">
                <i class="fas fa-door-open" aria-hidden="true"></i> Manage Spaces
            </a>
            <a href="hub-operations?tab=bookings" class="btn btn-primary">
                <i class="fas fa-cog" aria-hidden="true"></i> Manage Bookings
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Statistics (Admin Only) -->
<?php if ($is_admin): ?>
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Active Bookings</span>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Today's Bookings</span>
            <span class="stat-value"><?php echo $stats['today']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Approval</span>
            <span class="stat-value"><?php echo $stats['pending']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Confirmed</span>
            <span class="stat-value"><?php echo $stats['confirmed']; ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-filter" aria-hidden="true"></i> Filter Bookings</h3>
    </div>
    <div class="card-body">
    <form method="GET" action="">
        <div class="filters-bar vsb-filters" role="search" aria-label="Filter bookings">
            <div class="form-group">
                <label for="space">Space</label>
                <select name="space" id="space" class="form-control">
                    <option value="">All Spaces</option>
                    <?php foreach ($spaces as $space): ?>
                        <option value="<?php echo htmlspecialchars($space['space_name']); ?>"
                                <?php echo $filter_space == $space['space_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($space['space_name']); ?> (<?php echo htmlspecialchars($space['space_type']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Confirmed" <?php echo $filter_status == 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="Cancelled" <?php echo $filter_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="Completed" <?php echo $filter_status == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>

            <div class="filters-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search" aria-hidden="true"></i> Filter
                </button>
                <a href="view-space-bookings" class="btn btn-secondary">
                    <i class="fas fa-times" aria-hidden="true"></i> Clear
                </a>
            </div>
        </div>
    </form>
    </div>
</div>

<!-- Calendar Container -->
<div class="card">
    <div class="card-body">
    <div class="vsb-toolbar">
        <div class="vsb-nav">
            <button type="button" class="btn btn-secondary btn-sm" id="prevBtn">
                <i class="fas fa-chevron-left" aria-hidden="true"></i> Previous
            </button>
            <button type="button" class="btn btn-secondary btn-sm" id="todayBtn">Today</button>
            <button type="button" class="btn btn-secondary btn-sm" id="nextBtn">
                Next <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>

        <div class="vsb-month" id="monthDisplay"></div>

        <div class="vsb-toggle">
            <button type="button" class="tab-btn active" id="calendarViewBtn" onclick="switchView('calendar')">
                <i class="fas fa-calendar" aria-hidden="true"></i> Calendar
            </button>
            <button type="button" class="tab-btn" id="listViewBtn" onclick="switchView('list')">
                <i class="fas fa-list" aria-hidden="true"></i> List
            </button>
        </div>
    </div>

    <!-- Calendar View -->
    <div class="calendar-view active">
        <div id="calendar"></div>

        <!-- Legend -->
        <div class="vsb-legend">
            <div class="vsb-legend-item">
                <span class="vsb-swatch is-pending"></span>
                <span>Pending</span>
            </div>
            <div class="vsb-legend-item">
                <span class="vsb-swatch is-confirmed"></span>
                <span>Confirmed</span>
            </div>
            <div class="vsb-legend-item">
                <span class="vsb-swatch is-cancelled"></span>
                <span>Cancelled</span>
            </div>
            <div class="vsb-legend-item">
                <span class="vsb-swatch is-completed"></span>
                <span>Completed</span>
            </div>
        </div>
    </div>

    <!-- List View -->
    <div class="list-view" id="listView">
        <!-- Will be populated dynamically -->
    </div>
    </div>
</div>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js" integrity="sha384-WfE/vOHqht3KDj6FvpwQUf3UxEPUHoGJ3w1yZ8rhpLWnVigt8HjXL2zXqtcfS7mf" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
let calendar;
const filterSpace = <?php echo json_encode((string)$filter_space, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const filterStatus = <?php echo json_encode((string)$filter_status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// Status colours for calendar events come from the design tokens.
const vsbToken = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

// Debug function
function logDebug(message, data) {
    // Silent unless explicitly enabled from the browser console: window.IMS_DEBUG = true
    if (window.IMS_DEBUG) {
        console.debug('[Calendar Debug]', message, data || '');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    
    logDebug('Initializing calendar');
    logDebug('Filter Space:', filterSpace);
    logDebug('Filter Status:', filterStatus);
    
    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: false, // We have custom header
        height: 'auto',
        events: function(info, successCallback, failureCallback) {
            // Build query parameters
            const params = new URLSearchParams({
                start: info.startStr,
                end: info.endStr,
                space: filterSpace,
                status: filterStatus
            });
            
            const url = 'includes/get-bookings.php?' + params.toString();
            logDebug('Fetching bookings from:', url);
            
            // Fetch bookings from server
            fetch(url)
                .then(response => {
                    logDebug('Response status:', response.status);
                    logDebug('Response OK:', response.ok);
                    
                    if (!response.ok) {
                        return response.text().then(text => {
                            logDebug('Error response text:', text);
                            throw new Error(`HTTP ${response.status}: ${text.substring(0, 100)}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    logDebug('Bookings loaded successfully:', data.length + ' bookings');
                    
                    // Check if response is an error object
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    // Check if data is an array
                    if (!Array.isArray(data)) {
                        logDebug('Invalid data format:', data);
                        throw new Error('Invalid response format - expected array');
                    }
                    
                    const events = data.map(booking => {
                        // Set color based on status
                        let color = vsbToken('--brand-500');
                        if (booking.booking_status === 'Pending') color = vsbToken('--amber-fg');
                        else if (booking.booking_status === 'Confirmed') color = vsbToken('--green-fg');
                        else if (booking.booking_status === 'Cancelled') color = vsbToken('--red-fg');
                        else if (booking.booking_status === 'Completed') color = vsbToken('--blue-fg');
                        
                        // Create event title
                        const timeStr = booking.start_time ? booking.start_time.substring(0, 5) : '00:00';
                        const title = `${timeStr} - ${booking.space_name || 'Unknown Space'}`;
                        
                        return {
                            id: booking.booking_id,
                            title: title,
                            start: booking.booking_date + 'T' + booking.start_time,
                            end: booking.booking_date + 'T' + booking.end_time,
                            backgroundColor: color,
                            borderColor: color,
                            extendedProps: {
                                space: booking.space_name,
                                status: booking.booking_status,
                                member: booking.member_name,
                                attendees: booking.expected_attendees,
                                amount: booking.booking_amount
                            }
                        };
                    });
                    
                    logDebug('Events created:', events.length);
                    successCallback(events);
                })
                .catch(error => {
                    console.error('Error fetching bookings:', error);
                    
                    // Show user-friendly error message
                    const calendarEl = document.getElementById('calendar');
                    calendarEl.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                            <div>
                                <strong>Error Loading Bookings</strong>
                                <p><strong>Error:</strong> ${error.message}</p>
                                <p>Please check:</p>
                                <ul>
                                    <li>The file <code>includes/get-bookings.php</code> exists</li>
                                    <li>Database connection is working</li>
                                    <li>You have proper permissions</li>
                                </ul>
                                <button type="button" onclick="location.reload()" class="btn btn-primary btn-sm">
                                    <i class="fas fa-sync" aria-hidden="true"></i> Retry
                                </button>
                            </div>
                        </div>
                    `;
                    
                    failureCallback(error);
                });
        },
        eventClick: function(info) {
            window.location.href = 'view-booking?id=' + info.event.id;
        },
        datesSet: function(dateInfo) {
            // Update month display
            const monthDisplay = document.getElementById('monthDisplay');
            monthDisplay.textContent = dateInfo.view.title;
            logDebug('Month changed to:', dateInfo.view.title);
        },
        eventDidMount: function(info) {
            // Add tooltip
            const tooltip = `${info.event.extendedProps.space}\nStatus: ${info.event.extendedProps.status}\nMember: ${info.event.extendedProps.member}`;
            info.el.title = tooltip;
        },
        loading: function(isLoading) {
            logDebug('Calendar loading:', isLoading);
        }
    });
    
    calendar.render();
    logDebug('Calendar rendered');
    
    // Hook up navigation buttons
    document.getElementById('prevBtn').addEventListener('click', function() {
        calendar.prev();
    });
    
    document.getElementById('todayBtn').addEventListener('click', function() {
        calendar.today();
    });
    
    document.getElementById('nextBtn').addEventListener('click', function() {
        calendar.next();
    });
});

function switchView(view) {
    const calendarView = document.querySelector('.calendar-view');
    const listView = document.querySelector('.list-view');
    const calendarBtn = document.getElementById('calendarViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    
    if (view === 'calendar') {
        calendarView.style.display = 'block';
        listView.style.display = 'none';
        calendarBtn.classList.add('active');
        listBtn.classList.remove('active');
        
        // Refetch events when switching back to calendar
        if (calendar) {
            calendar.refetchEvents();
        }
    } else {
        calendarView.style.display = 'none';
        listView.style.display = 'block';
        calendarBtn.classList.remove('active');
        listBtn.classList.add('active');
        loadListView();
    }
}

function loadListView() {
    const listView = document.getElementById('listView');
    listView.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><p>Loading bookings...</p></div>';
    
    const params = new URLSearchParams({
        list: '1',
        space: filterSpace,
        status: filterStatus
    });
    
    const url = 'includes/get-bookings.php?' + params.toString();
    logDebug('Loading list view from:', url);
    
    fetch(url)
        .then(response => {
            logDebug('List view response status:', response.status);
            
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`HTTP ${response.status}: ${text.substring(0, 100)}`);
                });
            }
            return response.json();
        })
        .then(data => {
            logDebug('List view loaded:', data.length + ' bookings');
            
            // Check if response is an error object
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Check if data is an array
            if (!Array.isArray(data)) {
                throw new Error('Invalid response format - expected array');
            }
            
            if (data.length === 0) {
                listView.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox" aria-hidden="true"></i>
                        <p>No bookings match your filter criteria.</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            data.forEach(booking => {
                const statusClass = booking.booking_status.toLowerCase();
                const statusBadge = {
                    'Pending': 'warning',
                    'Confirmed': 'success',
                    'Cancelled': 'danger',
                    'Completed': 'info'
                }[booking.booking_status] || 'secondary';
                
                const dateFormatted = new Date(booking.booking_date).toLocaleDateString('en-US', {
                    weekday: 'short',
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                const startTime = booking.start_time ? booking.start_time.substring(0, 5) : '00:00';
                const endTime = booking.end_time ? booking.end_time.substring(0, 5) : '00:00';
                
                html += `
                    <div class="vsb-booking ${statusClass}">
                        <div class="vsb-booking-head">
                            <div class="vsb-booking-title">
                                <i class="fas fa-door-open"></i> ${booking.space_name || 'Unknown Space'}
                            </div>
                            <span class="badge badge-${statusBadge}">${booking.booking_status}</span>
                        </div>
                        <div class="vsb-booking-details">
                            <div class="vsb-detail">
                                <strong><i class="fas fa-calendar"></i> Date</strong>
                                ${dateFormatted}
                            </div>
                            <div class="vsb-detail">
                                <strong><i class="fas fa-clock"></i> Time</strong>
                                ${startTime} - ${endTime}
                            </div>
                            <div class="vsb-detail">
                                <strong><i class="fas fa-user"></i> Member</strong>
                                ${booking.member_name || 'N/A'}
                            </div>
                            <div class="vsb-detail">
                                <strong><i class="fas fa-users"></i> Attendees</strong>
                                ${booking.expected_attendees || 0} people
                            </div>
                            <div class="vsb-detail">
                                <strong><i class="fas fa-money-bill"></i> Amount</strong>
                                UGX ${parseFloat(booking.booking_amount || 0).toLocaleString()}
                            </div>
                        </div>
                        <div class="vsb-booking-actions">
                            <a href="view-booking?id=${booking.booking_id}" class="btn btn-info btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                `;
            });
            
            listView.innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading list view:', error);
            listView.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    <div>
                        <strong>Error Loading Bookings</strong>
                        <p><strong>Error:</strong> ${error.message}</p>
                        <button type="button" onclick="loadListView()" class="btn btn-primary btn-sm">
                            <i class="fas fa-sync" aria-hidden="true"></i> Retry
                        </button>
                    </div>
                </div>
            `;
        });
}
</script>