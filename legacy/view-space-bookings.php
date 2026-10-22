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
.bookings-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.bookings-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.filter-section {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.filter-section h3 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 18px;
}

.calendar-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 2px solid #ecf0f1;
}

.calendar-nav {
    display: flex;
    gap: 10px;
    align-items: center;
}

.calendar-nav button {
    padding: 8px 16px;
    background: #ff6b35;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s;
}

.calendar-nav button:hover {
    background: #ff9800;
}

.month-display {
    font-size: 24px;
    font-weight: 600;
    color: #2c3e50;
}

.view-toggle {
    display: flex;
    gap: 10px;
}

.view-toggle button {
    padding: 8px 16px;
    background: white;
    color: #ff6b35;
    border: 1px solid #ff6b35;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s;
}

.view-toggle button.active {
    background: #ff6b35;
    color: white;
}

#calendar {
    min-height: 600px;
}

.legend {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #ecf0f1;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

.legend-color {
    width: 20px;
    height: 20px;
    border-radius: 4px;
}

.list-view {
    display: none;
}

.list-view.active {
    display: block;
}

.calendar-view.active {
    display: block;
}

.booking-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    border-left: 4px solid #ff6b35;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.booking-card:hover {
    transform: translateX(5px);
}

.booking-card.pending {
    border-left-color: #F39C12;
}

.booking-card.confirmed {
    border-left-color: #2ECC71;
}

.booking-card.cancelled {
    border-left-color: #E74C3C;
    opacity: 0.7;
}

.booking-card.completed {
    border-left-color: #3498DB;
}

.booking-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
}

.booking-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
}

.booking-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.detail-item {
    font-size: 14px;
    color: #7f8c8d;
}

.detail-item strong {
    color: #2c3e50;
    display: block;
    margin-bottom: 3px;
}

.fc-event {
    cursor: pointer;
}

.fc-event:hover {
    opacity: 0.8;
}

.error-message {
    background: #fee;
    border: 1px solid #fcc;
    color: #c33;
    padding: 15px;
    border-radius: 6px;
    margin: 20px 0;
}

.loading-message {
    text-align: center;
    padding: 40px;
    color: #7f8c8d;
}

@media (max-width: 768px) {
    .calendar-header {
        flex-direction: column;
        gap: 15px;
    }
    
    .calendar-nav,
    .view-toggle {
        width: 100%;
        justify-content: space-between;
    }
    
    .booking-details {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- FullCalendar v6 injects its own CSS from index.global.min.js (there is no separate CSS file; the old link 404'd). -->

<!-- Header -->
<div class="bookings-header">
    <h1><i class="fas fa-calendar-alt"></i> Space Bookings Calendar</h1>
    <p style="margin: 0; opacity: 0.9;">
        View and manage space bookings across all facilities
    </p>
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
<div class="filter-section">
    <h3><i class="fas fa-filter"></i> Filter Bookings</h3>
    <form method="GET" action="">
        <div class="filters-bar" role="search" aria-label="Filter bookings" style="margin-bottom:0;">
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
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-search"></i> Filter
                </button>
                <a href="view-space-bookings" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Calendar Container -->
<div class="calendar-container">
    <div class="calendar-header">
        <div class="calendar-nav">
            <button id="prevBtn">
                <i class="fas fa-chevron-left"></i> Previous
            </button>
            <button id="todayBtn">Today</button>
            <button id="nextBtn">
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        
        <div class="month-display" id="monthDisplay"></div>
        
        <div class="view-toggle">
            <button class="active" id="calendarViewBtn" onclick="switchView('calendar')">
                <i class="fas fa-calendar"></i> Calendar
            </button>
            <button id="listViewBtn" onclick="switchView('list')">
                <i class="fas fa-list"></i> List
            </button>
        </div>
    </div>
    
    <!-- Calendar View -->
    <div class="calendar-view active">
        <div id="calendar"></div>
        
        <!-- Legend -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color" style="background: #F39C12;"></div>
                <span>Pending</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #2ECC71;"></div>
                <span>Confirmed</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #E74C3C;"></div>
                <span>Cancelled</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #3498DB;"></div>
                <span>Completed</span>
            </div>
        </div>
    </div>
    
    <!-- List View -->
    <div class="list-view" id="listView">
        <!-- Will be populated dynamically -->
    </div>
</div>

<!-- Quick Actions -->
<?php if ($is_member): ?>
<div style="text-align: center; margin-top: 30px;">
    <a href="book-space" class="btn btn-success btn-lg">
        <i class="fas fa-plus"></i> Book a Space
    </a>
    <a href="my-bookings" class="btn btn-info btn-lg">
        <i class="fas fa-list"></i> My Bookings
    </a>
</div>
<?php endif; ?>

<?php if ($is_admin): ?>
<div style="text-align: center; margin-top: 30px;">
    <a href="hub-operations?tab=bookings" class="btn btn-primary btn-lg">
        <i class="fas fa-cog"></i> Manage Bookings
    </a>
    <a href="manage-space" class="btn btn-info btn-lg">
        <i class="fas fa-door-open"></i> Manage Spaces
    </a>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js" integrity="sha384-WfE/vOHqht3KDj6FvpwQUf3UxEPUHoGJ3w1yZ8rhpLWnVigt8HjXL2zXqtcfS7mf" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
let calendar;
const filterSpace = <?php echo json_encode((string)$filter_space, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const filterStatus = <?php echo json_encode((string)$filter_status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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
                        let color = '#ff6b35';
                        if (booking.booking_status === 'Pending') color = '#F39C12';
                        else if (booking.booking_status === 'Confirmed') color = '#2ECC71';
                        else if (booking.booking_status === 'Cancelled') color = '#E74C3C';
                        else if (booking.booking_status === 'Completed') color = '#3498DB';
                        
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
                        <div class="error-message">
                            <h4><i class="fas fa-exclamation-triangle"></i> Error Loading Bookings</h4>
                            <p><strong>Error:</strong> ${error.message}</p>
                            <p>Please check:</p>
                            <ul>
                                <li>The file <code>includes/get-bookings.php</code> exists</li>
                                <li>Database connection is working</li>
                                <li>You have proper permissions</li>
                            </ul>
                            <button onclick="location.reload()" class="btn btn-primary" style="margin-top: 10px;">
                                <i class="fas fa-sync"></i> Retry
                            </button>
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
    listView.innerHTML = '<div class="loading-message"><i class="fas fa-spinner fa-spin fa-2x"></i><p style="margin-top: 20px;">Loading bookings...</p></div>';
    
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
                    <div style="text-align: center; padding: 60px; color: #7f8c8d;">
                        <i class="fas fa-inbox" style="font-size: 64px; margin-bottom: 20px; opacity: 0.3;"></i>
                        <h3>No bookings found</h3>
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
                    <div class="booking-card ${statusClass}">
                        <div class="booking-header">
                            <div class="booking-title">
                                <i class="fas fa-door-open"></i> ${booking.space_name || 'Unknown Space'}
                            </div>
                            <span class="badge badge-${statusBadge}">${booking.booking_status}</span>
                        </div>
                        <div class="booking-details">
                            <div class="detail-item">
                                <strong><i class="fas fa-calendar"></i> Date</strong>
                                ${dateFormatted}
                            </div>
                            <div class="detail-item">
                                <strong><i class="fas fa-clock"></i> Time</strong>
                                ${startTime} - ${endTime}
                            </div>
                            <div class="detail-item">
                                <strong><i class="fas fa-user"></i> Member</strong>
                                ${booking.member_name || 'N/A'}
                            </div>
                            <div class="detail-item">
                                <strong><i class="fas fa-users"></i> Attendees</strong>
                                ${booking.expected_attendees || 0} people
                            </div>
                            <div class="detail-item">
                                <strong><i class="fas fa-money-bill"></i> Amount</strong>
                                UGX ${parseFloat(booking.booking_amount || 0).toLocaleString()}
                            </div>
                        </div>
                        <div style="margin-top: 15px;">
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
                <div class="error-message">
                    <h4><i class="fas fa-exclamation-triangle"></i> Error Loading Bookings</h4>
                    <p><strong>Error:</strong> ${error.message}</p>
                    <button onclick="loadListView()" class="btn btn-primary" style="margin-top: 10px;">
                        <i class="fas fa-sync"></i> Retry
                    </button>
                </div>
            `;
        });
}
</script>