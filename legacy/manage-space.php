<?php

$page_title = 'Manage Available Spaces';
include 'includes/header.php';


if (!isset($_SESSION['user_id']) || !ims_allowed('spaces.manage', ['Administrator', 'Operations/Admin'], 'Add and edit spaces')) {
    $_SESSION['error'] = "Access denied. Administrator or Operations role required.";
    header("Location: dashboard");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get filter parameters
$filter_type = isset($_GET['type']) ? $conn->real_escape_string(sanitize_input($_GET['type'])) : '';
$filter_status = isset($_GET['status']) ? $conn->real_escape_string(sanitize_input($_GET['status'])) : '';

// Build WHERE clause
$where = "1=1";

if ($filter_type) {
    $where .= " AND space_type = '$filter_type'";
}

if ($filter_status !== '') {
    $is_available = $filter_status == '1' ? 1 : 0;
    $where .= " AND is_available = $is_available";
}

// Fetch spaces
$spaces = [];
$query = "SELECT * FROM space_availability WHERE $where ORDER BY space_type, space_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $spaces[] = $row;
}

// Get statistics
$stats = [];
$stats['total'] = $conn->query("SELECT COUNT(*) as count FROM space_availability")->fetch_assoc()['count'];
$stats['available'] = $conn->query("SELECT COUNT(*) as count FROM space_availability WHERE is_available = 1")->fetch_assoc()['count'];
$stats['unavailable'] = $conn->query("SELECT COUNT(*) as count FROM space_availability WHERE is_available = 0")->fetch_assoc()['count'];
$stats['total_capacity'] = $conn->query("SELECT SUM(capacity) as total FROM space_availability WHERE is_available = 1")->fetch_assoc()['total'] ?? 0;

// Get space for editing if edit parameter is set
$edit_space = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_query = "SELECT * FROM space_availability WHERE availability_id = $edit_id";
    $edit_result = $conn->query($edit_query);
    if ($edit_result->num_rows > 0) {
        $edit_space = $edit_result->fetch_assoc();
    }
}
?>

<link rel="stylesheet" href="css/assets.css">
<style>
/* Manage spaces: space card grid, pricing block, amenities and availability switch. */
.ms-filters { margin-bottom: 0; }

.ms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: var(--space-6);
}

.ms-card {
    background: var(--surface-card);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s, box-shadow 0.2s;
}

.ms-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.ms-card.unavailable {
    opacity: 0.7;
}

.ms-image {
    display: block;
    width: 100%;
    height: 200px;
    object-fit: cover;
    background: linear-gradient(135deg, var(--brand-600) 0%, var(--brand-400) 100%);
}

.ms-content {
    padding: var(--space-6);
}

.ms-title h3 {
    font-size: var(--fs-xl);
    color: var(--ink-900);
    margin: 0 0 var(--space-1);
}

.ms-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-3);
    margin: var(--space-5) 0;
}

.ms-info-item {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--fs-sm);
}

.ms-info-item i {
    color: var(--brand-500);
    width: 20px;
}

.ms-info-item .is-available   { color: var(--green-fg); }
.ms-info-item .is-unavailable { color: var(--red-fg); }

.ms-pricing {
    background: var(--ink-50);
    padding: var(--space-4);
    border-radius: var(--radius-md);
    margin: var(--space-4) 0;
}

.ms-pricing-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: var(--space-2);
    font-size: var(--fs-sm);
}

.ms-pricing-row:last-child {
    margin-bottom: 0;
}

.ms-pricing-row .label {
    color: var(--ink-300);
}

.ms-pricing-row .label.is-missing {
    color: var(--red-fg);
}

.ms-pricing-row .value {
    font-weight: 600;
    color: var(--green-fg);
}

/* Monthly-only pricing display */
.ms-monthly {
    background: var(--green-bg);
    border: 1px solid var(--green-border);
    color: var(--green-fg);
    padding: var(--space-5);
    border-radius: var(--radius-md);
    margin: var(--space-4) 0;
    text-align: center;
}

.ms-monthly .label {
    font-size: var(--fs-xs);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: var(--space-1);
}

.ms-monthly .value {
    font-size: var(--fs-2xl);
    font-weight: 700;
}

.ms-monthly .period {
    font-size: var(--fs-sm);
    margin-top: var(--space-1);
}

.ms-amenities {
    margin: var(--space-4) 0;
}

.ms-amenities-label {
    display: block;
    margin-bottom: var(--space-1);
    font-size: var(--fs-xs);
    color: var(--ink-300);
    text-transform: uppercase;
}

.ms-amenities-list {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-top: var(--space-2);
}

.ms-actions {
    display: flex;
    gap: var(--space-2);
    margin-top: var(--space-5);
    flex-wrap: wrap;
}

.ms-availability {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-4);
    background: var(--ink-50);
    border-radius: var(--radius-md);
    margin-top: var(--space-4);
}

/* Availability switch (no global switch component yet). */
.ms-switch {
    position: relative;
    width: 50px;
    height: 24px;
    flex-shrink: 0;
}

.ms-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.ms-switch-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: var(--ink-100);
    transition: .4s;
    border-radius: var(--radius-pill);
}

.ms-switch-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: var(--ink-0);
    transition: .4s;
    border-radius: 50%;
}

.ms-switch input:checked + .ms-switch-slider {
    background-color: var(--green-fg);
}

.ms-switch input:checked + .ms-switch-slider:before {
    transform: translateX(26px);
}

.ms-modal { max-width: 900px; }

.ms-form-section {
    margin: var(--space-6) 0 var(--space-4);
    padding-bottom: var(--space-2);
    color: var(--ink-900);
    border-bottom: 2px solid var(--ink-100);
}

.ms-help {
    display: block;
    margin-top: var(--space-1);
    font-size: var(--fs-xs);
    color: var(--ink-300);
}

@media (max-width: 768px) {
    .ms-grid,
    .ms-info {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="page-stack">

<!-- Header (global .page-hero) -->
<div class="page-hero">
    <div>
        <h1><i class="fas fa-building" aria-hidden="true"></i> Manage Available Spaces</h1>
        <p>Manage bookable spaces, their rates and availability.</p>
    </div>
    <div class="hero-actions">
        <a href="manage-bookings" class="btn"><i class="fas fa-calendar-check" aria-hidden="true"></i> Bookings</a>
        <button type="button" onclick="openModal('addSpaceModal')" class="btn btn-primary">
            <i class="fas fa-plus" aria-hidden="true"></i> Add New Space
        </button>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-door-open" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Spaces</span>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Available</span>
            <span class="stat-value"><?php echo $stats['available']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-times-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Unavailable</span>
            <span class="stat-value"><?php echo $stats['unavailable']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Capacity</span>
            <span class="stat-value"><?php echo $stats['total_capacity']; ?></span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card">
<div class="card-body">
<form method="GET" action="">
    <div class="filters-bar ms-filters" role="search" aria-label="Filter spaces">
        <div class="form-group">
            <label for="type">Space Type</label>
            <select name="type" id="type" class="form-control">
                <option value="">All Types</option>
                <option value="Boardroom" <?php echo $filter_type == 'Boardroom' ? 'selected' : ''; ?>>Boardroom</option>
                <option value="Meeting Room" <?php echo $filter_type == 'Meeting Room' ? 'selected' : ''; ?>>Meeting Room</option>
                <option value="Event Hall" <?php echo $filter_type == 'Event Hall' ? 'selected' : ''; ?>>Event Hall</option>
                <option value="Private Office" <?php echo $filter_type == 'Private Office' ? 'selected' : ''; ?>>Private Office</option>
                <option value="Hot Desk" <?php echo $filter_type == 'Hot Desk' ? 'selected' : ''; ?>>Hot Desk</option>
                <option value="Dedicated Desk" <?php echo $filter_type == 'Dedicated Desk' ? 'selected' : ''; ?>>Dedicated Desk</option>
                <option value="Conference Room" <?php echo $filter_type == 'Conference Room' ? 'selected' : ''; ?>>Conference Room</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="status">Availability</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Status</option>
                <option value="1" <?php echo $filter_status === '1' ? 'selected' : ''; ?>>Available</option>
                <option value="0" <?php echo $filter_status === '0' ? 'selected' : ''; ?>>Unavailable</option>
            </select>
        </div>
        
        <div class="filters-actions">
            <button type="submit" class="btn btn-info">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="manage-space" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </div>
</form>
</div>
</div>

<!-- Spaces Grid -->
<?php if (empty($spaces)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-door-open" aria-hidden="true"></i>
                <p>
                    <?php if ($filter_type || $filter_status !== ''): ?>
                        No spaces match your filter criteria.
                    <?php else: ?>
                        No spaces have been added yet.
                    <?php endif; ?>
                </p>
                <button onclick="openModal('addSpaceModal')" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Space
                </button>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="ms-grid">
        <?php foreach ($spaces as $space): 
            // Determine if this space type should show only monthly rate
            $monthly_only_types = ['Private Office', 'Hot Desk', 'Dedicated Desk'];
            $show_monthly_only = in_array($space['space_type'], $monthly_only_types);
        ?>
        <div class="ms-card <?php echo !$space['is_available'] ? 'unavailable' : ''; ?>">
            <?php if ($space['space_image']): ?>
                <img src="<?php echo htmlspecialchars(ims_upload_url($space['space_image'])); ?>" alt="Space Image" class="ms-image">
            <?php else: ?>
                <div class="ms-image"></div>
            <?php endif; ?>
            
            <div class="ms-content">
                <div class="ms-title">
                    <h3><?php echo htmlspecialchars($space['space_name']); ?></h3>
                    <span class="badge badge-info">
                        <?php echo htmlspecialchars($space['space_type']); ?>
                    </span>
                </div>
                
                <div class="ms-info">
                    <div class="ms-info-item">
                        <i class="fas fa-users"></i>
                        <span><strong><?php echo $space['capacity']; ?></strong> people</span>
                    </div>
                    
                    <div class="ms-info-item">
                        <i class="fas fa-<?php echo $space['is_available'] ? 'check-circle' : 'times-circle'; ?>"></i>
                        <span class="<?php echo $space['is_available'] ? 'is-available' : 'is-unavailable'; ?>">
                            <strong><?php echo $space['is_available'] ? 'Available' : 'Unavailable'; ?></strong>
                        </span>
                    </div>
                </div>
                
                <?php if ($show_monthly_only): ?>
                    <!-- Monthly-only pricing for Private Office, Hot Desk, Dedicated Desk -->
                    <?php if (isset($space['monthly_rate']) && $space['monthly_rate'] > 0): ?>
                        <div class="ms-monthly">
                            <div class="label">Monthly Rate</div>
                            <div class="value"><?php echo format_currency($space['monthly_rate']); ?></div>
                            <div class="period">per month</div>
                        </div>
                    <?php else: ?>
                        <div class="ms-pricing">
                            <div class="ms-pricing-row">
                                <span class="label is-missing">
                                    <i class="fas fa-exclamation-triangle"></i> No monthly rate set
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Standard hourly/daily pricing for other space types -->
                    <div class="ms-pricing">
                        <div class="ms-pricing-row">
                            <span class="label">Hourly Rate:</span>
                            <span class="value"><?php echo format_currency($space['hourly_rate']); ?></span>
                        </div>
                        <div class="ms-pricing-row">
                            <span class="label">Half Day:</span>
                            <span class="value"><?php echo format_currency($space['half_day_rate']); ?></span>
                        </div>
                        <div class="ms-pricing-row">
                            <span class="label">Full Day:</span>
                            <span class="value"><?php echo format_currency($space['full_day_rate']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($space['amenities']): ?>
                <div class="ms-amenities">
                    <span class="ms-amenities-label">Amenities</span>
                    <div class="ms-amenities-list">
                        <?php
                        $amenities = explode("\n", $space['amenities']);
                        foreach ($amenities as $amenity):
                            $amenity = trim($amenity);
                            if (!empty($amenity)):
                        ?>
                            <span class="badge badge-secondary"><?php echo htmlspecialchars($amenity); ?></span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Quick Availability Toggle -->
                <div class="ms-availability">
                    <label class="ms-switch">
                        <input type="checkbox" 
                               <?php echo $space['is_available'] ? 'checked' : ''; ?>
                               onchange="toggleAvailability(<?php echo $space['availability_id']; ?>, this.checked)">
                        <span class="ms-switch-slider"></span>
                    </label>
                    <span class="cell-muted">Quick toggle availability</span>
                </div>
                
                <div class="ms-actions">
                    <a href="?edit=<?php echo $space['availability_id']; ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    
                    <a href="view-space-bookings?space_id=<?php echo $space['availability_id']; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-calendar"></i> Bookings
                    </a>
                    
                    <?php if (ims_allowed('spaces.delete', ['Administrator'], 'Delete spaces')): ?>
                    <button onclick="confirmDelete(<?php echo $space['availability_id']; ?>, <?= htmlspecialchars(json_encode((string)($space['space_name'])), ENT_QUOTES, 'UTF-8') ?>)" 
                            class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</div><!-- /.page-stack -->

<!-- Add/Edit Space Modal -->
<div id="addSpaceModal" class="modal">
    <div class="modal-content ms-modal">
        <div class="modal-header">
            <h3><?php echo $edit_space ? 'Edit Space' : 'Add New Space'; ?></h3>
            <span class="close" onclick="closeModal('addSpaceModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/process-space.php" enctype="multipart/form-data" id="spaceForm">
                <input type="hidden" name="action" value="<?php echo $edit_space ? 'edit' : 'add'; ?>">
                <?php if ($edit_space): ?>
                    <input type="hidden" name="availability_id" value="<?php echo $edit_space['availability_id']; ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="space_type" class="required">Space Type</label>
                        <select id="space_type" name="space_type" class="form-control" required onchange="togglePricingFields()">
                            <option value="">Select Type</option>
                            <option value="Boardroom" <?php echo ($edit_space && $edit_space['space_type'] == 'Boardroom') ? 'selected' : ''; ?>>Boardroom</option>
                            <option value="Meeting Room" <?php echo ($edit_space && $edit_space['space_type'] == 'Meeting Room') ? 'selected' : ''; ?>>Meeting Room</option>
                            <option value="Event Hall" <?php echo ($edit_space && $edit_space['space_type'] == 'Event Hall') ? 'selected' : ''; ?>>Event Hall</option>
                            <option value="Private Office" <?php echo ($edit_space && $edit_space['space_type'] == 'Private Office') ? 'selected' : ''; ?>>Private Office</option>
                            <option value="Hot Desk" <?php echo ($edit_space && $edit_space['space_type'] == 'Hot Desk') ? 'selected' : ''; ?>>Hot Desk</option>
                            <option value="Dedicated Desk" <?php echo ($edit_space && $edit_space['space_type'] == 'Dedicated Desk') ? 'selected' : ''; ?>>Dedicated Desk</option>
                            <option value="Conference Room" <?php echo ($edit_space && $edit_space['space_type'] == 'Conference Room') ? 'selected' : ''; ?>>Conference Room</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="space_name" class="required">Space Name</label>
                        <input type="text" id="space_name" name="space_name" class="form-control" 
                               placeholder="e.g., Main Boardroom" 
                               value="<?php echo $edit_space ? htmlspecialchars($edit_space['space_name']) : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="capacity" class="required">Capacity (People)</label>
                        <input type="number" id="capacity" name="capacity" class="form-control" 
                               min="1" placeholder="e.g., 20" 
                               value="<?php echo $edit_space ? $edit_space['capacity'] : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="is_available">Availability Status</label>
                        <select id="is_available" name="is_available" class="form-control">
                            <option value="1" <?php echo (!$edit_space || $edit_space['is_available']) ? 'selected' : ''; ?>>Available</option>
                            <option value="0" <?php echo ($edit_space && !$edit_space['is_available']) ? 'selected' : ''; ?>>Unavailable</option>
                        </select>
                    </div>
                </div>
                
                <!-- Pricing Section -->
                <h4 class="ms-form-section">
                    <i class="fas fa-tag"></i> Pricing Information
                </h4>
                
                <!-- Monthly Rate (For Private Office, Hot Desk, Dedicated Desk) -->
                <div class="form-group" id="monthly_rate_field" <?php echo ($edit_space && in_array($edit_space['space_type'], ['Private Office', 'Hot Desk', 'Dedicated Desk'])) ? 'style="display: block;"' : 'style="display: none;"'; ?>>
                    <label for="monthly_rate" class="required">Monthly Rate (UGX)</label>
                    <input type="number" id="monthly_rate" name="monthly_rate" class="form-control" 
                           min="0" step="1000" placeholder="e.g., 500000" 
                           value="<?php echo $edit_space ? ($edit_space['monthly_rate'] ?? '') : ''; ?>">
                    <small class="ms-help">
                        <i class="fas fa-info-circle"></i> Monthly rental rate (required for desks and private offices)
                    </small>
                </div>
                
                <!-- Hourly/Daily Rates (For other space types) -->
                <div id="hourly_pricing_fields" <?php echo ($edit_space && !in_array($edit_space['space_type'], ['Private Office', 'Hot Desk', 'Dedicated Desk'])) ? 'style="display: block;"' : 'style="display: none;"'; ?>>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="hourly_rate" class="required">Hourly Rate (UGX)</label>
                            <input type="number" id="hourly_rate" name="hourly_rate" class="form-control" 
                                   min="0" step="1000" placeholder="e.g., 50000" 
                                   value="<?php echo $edit_space ? $edit_space['hourly_rate'] : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="half_day_rate" class="required">Half Day Rate (UGX)</label>
                            <input type="number" id="half_day_rate" name="half_day_rate" class="form-control" 
                                   min="0" step="1000" placeholder="e.g., 150000" 
                                   value="<?php echo $edit_space ? $edit_space['half_day_rate'] : ''; ?>">
                            <small class="ms-help">Half day = 4 hours</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="full_day_rate" class="required">Full Day Rate (UGX)</label>
                            <input type="number" id="full_day_rate" name="full_day_rate" class="form-control" 
                                   min="0" step="1000" placeholder="e.g., 250000" 
                                   value="<?php echo $edit_space ? $edit_space['full_day_rate'] : ''; ?>">
                            <small class="ms-help">Full day = 8 hours</small>
                        </div>
                    </div>
                </div>
                
                <!-- Amenities and Image -->
                <h4 class="ms-form-section">
                    <i class="fas fa-star"></i> Additional Details
                </h4>
                
                <div class="form-group">
                    <label for="amenities">Amenities (one per line)</label>
                    <textarea id="amenities" name="amenities" class="form-control" rows="6" 
                              placeholder="WiFi&#10;Projector&#10;Whiteboard&#10;Air Conditioning&#10;Coffee Machine&#10;Printing Services"><?php echo $edit_space ? htmlspecialchars($edit_space['amenities']) : ''; ?></textarea>
                    <small class="ms-help">Enter each amenity on a new line</small>
                </div>
                
                <div class="form-group">
                    <label for="space_image">Space Image</label>
                    <input type="file" id="space_image" name="space_image" class="form-control" accept="image/*">
                    <?php if ($edit_space && $edit_space['space_image']): ?>
                        <small class="ms-help">
                            Current image: <a href="<?php echo htmlspecialchars(ims_upload_url($edit_space['space_image'])); ?>" target="_blank"><?php echo basename($edit_space['space_image']); ?></a>
                        </small>
                    <?php endif; ?>
                    <small class="ms-help">
                        <i class="fas fa-info-circle"></i> Recommended size: 800x600px. Max file size: 5MB
                    </small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addSpaceModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> <?php echo $edit_space ? 'Update Space' : 'Add Space'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Toggle pricing fields based on space type
function togglePricingFields() {
    const spaceType = document.getElementById('space_type').value;
    const monthlyField = document.getElementById('monthly_rate_field');
    const monthlyInput = document.getElementById('monthly_rate');
    const hourlyFields = document.getElementById('hourly_pricing_fields');
    const hourlyRate = document.getElementById('hourly_rate');
    const halfDayRate = document.getElementById('half_day_rate');
    const fullDayRate = document.getElementById('full_day_rate');
    
    // Space types that use monthly-only pricing
    const monthlyOnlyTypes = ['Private Office', 'Hot Desk', 'Dedicated Desk'];
    
    if (monthlyOnlyTypes.includes(spaceType)) {
        // Show monthly, hide hourly
        monthlyField.style.display = 'block';
        hourlyFields.style.display = 'none';
        
        // Make monthly required, hourly optional
        monthlyInput.required = true;
        hourlyRate.required = false;
        halfDayRate.required = false;
        fullDayRate.required = false;
        
        // Clear hourly values
        hourlyRate.value = '0';
        halfDayRate.value = '0';
        fullDayRate.value = '0';
    } else {
        // Show hourly, hide monthly
        monthlyField.style.display = 'none';
        hourlyFields.style.display = 'block';
        
        // Make hourly required, monthly optional
        monthlyInput.required = false;
        hourlyRate.required = true;
        halfDayRate.required = true;
        fullDayRate.required = true;
        
        // Clear monthly value
        monthlyInput.value = '';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    togglePricingFields();
    
    <?php if ($edit_space): ?>
    // Auto-open modal if editing
    openModal('addSpaceModal');
    <?php endif; ?>
});

// Toggle availability
function toggleAvailability(spaceId, isAvailable) {
    const status = isAvailable ? 1 : 0;
    
    if (confirm(`Are you sure you want to ${isAvailable ? 'enable' : 'disable'} this space?`)) {
        window.location.href = `includes/process-space.php?toggle_availability=${encodeURIComponent(spaceId)}&status=${encodeURIComponent(status)}&csrf_token=<?= h(csrf_token()) ?>`;
    } else {
        // Revert checkbox if cancelled
        event.target.checked = !isAvailable;
    }
}

// Confirm delete
function confirmDelete(spaceId, spaceName) {
    if (confirm(`Are you sure you want to delete "${spaceName}"?\n\nThis action cannot be undone and will affect all related bookings.`)) {
        window.location.href = `includes/process-space.php?delete_space=${encodeURIComponent(spaceId)}&csrf_token=<?= h(csrf_token()) ?>`;
    }
}
</script>