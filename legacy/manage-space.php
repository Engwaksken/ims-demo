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

<style>
.spaces-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.spaces-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.filter-bar {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.filter-bar > .filters-bar {
    margin-bottom: 0;
}

.space-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 25px;
}

.space-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.space-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.space-card.unavailable {
    opacity: 0.7;
}

.space-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
}

.space-content {
    padding: 25px;
}

.space-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
}

.space-title {
    flex: 1;
}

.space-title h3 {
    font-size: 22px;
    color: #2c3e50;
    margin-bottom: 5px;
}

.space-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin: 20px 0;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
}

.info-item i {
    color: #ff6b35;
    width: 20px;
}

.pricing-grid {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin: 15px 0;
}

.pricing-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 14px;
}

.pricing-row:last-child {
    margin-bottom: 0;
}

.pricing-row .label {
    color: #7f8c8d;
}

.pricing-row .value {
    font-weight: 600;
    color: #2ECC71;
}

/* Monthly-only pricing display */
.monthly-pricing {
    background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin: 15px 0;
    text-align: center;
}

.monthly-pricing .label {
    font-size: 12px;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 5px;
}

.monthly-pricing .value {
    font-size: 28px;
    font-weight: 700;
}

.monthly-pricing .period {
    font-size: 14px;
    opacity: 0.9;
    margin-top: 5px;
}

.amenities {
    margin: 15px 0;
}

.amenities-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.amenity-tag {
    background: #ecf0f1;
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    color: #2c3e50;
}

.space-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.availability-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-top: 15px;
}

.toggle-switch {
    position: relative;
    width: 50px;
    height: 24px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

.toggle-switch input:checked + .toggle-slider {
    background-color: #2ECC71;
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(26px);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i {
    font-size: 64px;
    color: #bdc3c7;
    margin-bottom: 20px;
}

.pricing-help-text {
    font-size: 12px;
    color: #7f8c8d;
    margin-top: 5px;
}

@media (max-width: 768px) {
    .space-grid {
        grid-template-columns: 1fr;
    }
    
    .space-info {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Header -->
<div class="spaces-header">
    <h1><i class="fas fa-building"></i> Manage Available Spaces</h1>
    <p style="margin: 0; opacity: 0.9;">Manage bookable spaces and their availability</p>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-door-open"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo $stats['total']; ?></h4>
            <p>Total Spaces</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo $stats['available']; ?></h4>
            <p>Available</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo $stats['unavailable']; ?></h4>
            <p>Unavailable</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo $stats['total_capacity']; ?></h4>
            <p>Total Capacity</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div style="margin-bottom: 25px;">
    <button onclick="openModal('addSpaceModal')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add New Space
    </button>
</div>

<!-- Filter Bar -->
<form method="GET" action="" class="filter-bar">
    <div class="filters-bar" role="search" aria-label="Filter spaces">
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

<!-- Spaces Grid -->
<?php if (empty($spaces)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-door-open"></i>
                <h3>No spaces found</h3>
                <p style="color: #7f8c8d; margin-bottom: 20px;">
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
    <div class="space-grid">
        <?php foreach ($spaces as $space): 
            // Determine if this space type should show only monthly rate
            $monthly_only_types = ['Private Office', 'Hot Desk', 'Dedicated Desk'];
            $show_monthly_only = in_array($space['space_type'], $monthly_only_types);
        ?>
        <div class="space-card <?php echo !$space['is_available'] ? 'unavailable' : ''; ?>">
            <?php if ($space['space_image']): ?>
                <img src="<?php echo htmlspecialchars(ims_upload_url($space['space_image'])); ?>" alt="Space Image" class="space-image">
            <?php else: ?>
                <div class="space-image"></div>
            <?php endif; ?>
            
            <div class="space-content">
                <div class="space-header">
                    <div class="space-title">
                        <h3><?php echo htmlspecialchars($space['space_name']); ?></h3>
                        <span class="badge badge-primary">
                            <?php echo htmlspecialchars($space['space_type']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="space-info">
                    <div class="info-item">
                        <i class="fas fa-users"></i>
                        <span><strong><?php echo $space['capacity']; ?></strong> people</span>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-<?php echo $space['is_available'] ? 'check-circle' : 'times-circle'; ?>"></i>
                        <span style="color: <?php echo $space['is_available'] ? '#2ECC71' : '#E74C3C'; ?>;">
                            <strong><?php echo $space['is_available'] ? 'Available' : 'Unavailable'; ?></strong>
                        </span>
                    </div>
                </div>
                
                <?php if ($show_monthly_only): ?>
                    <!-- Monthly-only pricing for Private Office, Hot Desk, Dedicated Desk -->
                    <?php if (isset($space['monthly_rate']) && $space['monthly_rate'] > 0): ?>
                        <div class="monthly-pricing">
                            <div class="label">Monthly Rate</div>
                            <div class="value"><?php echo format_currency($space['monthly_rate']); ?></div>
                            <div class="period">per month</div>
                        </div>
                    <?php else: ?>
                        <div class="pricing-grid">
                            <div class="pricing-row">
                                <span class="label" style="color: #E74C3C;">
                                    <i class="fas fa-exclamation-triangle"></i> No monthly rate set
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Standard hourly/daily pricing for other space types -->
                    <div class="pricing-grid">
                        <div class="pricing-row">
                            <span class="label">Hourly Rate:</span>
                            <span class="value"><?php echo format_currency($space['hourly_rate']); ?></span>
                        </div>
                        <div class="pricing-row">
                            <span class="label">Half Day:</span>
                            <span class="value"><?php echo format_currency($space['half_day_rate']); ?></span>
                        </div>
                        <div class="pricing-row">
                            <span class="label">Full Day:</span>
                            <span class="value"><?php echo format_currency($space['full_day_rate']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($space['amenities']): ?>
                <div class="amenities">
                    <label style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 5px; display: block;">Amenities</label>
                    <div class="amenities-list">
                        <?php
                        $amenities = explode("\n", $space['amenities']);
                        foreach ($amenities as $amenity):
                            $amenity = trim($amenity);
                            if (!empty($amenity)):
                        ?>
                            <span class="amenity-tag"><?php echo htmlspecialchars($amenity); ?></span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Quick Availability Toggle -->
                <div class="availability-toggle">
                    <label class="toggle-switch">
                        <input type="checkbox" 
                               <?php echo $space['is_available'] ? 'checked' : ''; ?>
                               onchange="toggleAvailability(<?php echo $space['availability_id']; ?>, this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span style="font-size: 14px; color: #7f8c8d;">Quick toggle availability</span>
                </div>
                
                <div class="space-actions">
                    <a href="?edit=<?php echo $space['availability_id']; ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    
                    <a href="view-space-bookings?space_id=<?php echo $space['availability_id']; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-calendar"></i> Bookings
                    </a>
                    
                    <?php if (ims_allowed('spaces.delete', ['Administrator'], 'Delete spaces')): ?>
                    <button onclick="confirmDelete(<?php echo $space['availability_id']; ?>, '<?php echo htmlspecialchars(addslashes($space['space_name'])); ?>')" 
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

<!-- Add/Edit Space Modal -->
<div id="addSpaceModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
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
                <h4 style="margin: 25px 0 15px 0; color: #2c3e50; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px;">
                    <i class="fas fa-tag"></i> Pricing Information
                </h4>
                
                <!-- Monthly Rate (For Private Office, Hot Desk, Dedicated Desk) -->
                <div class="form-group" id="monthly_rate_field" <?php echo ($edit_space && in_array($edit_space['space_type'], ['Private Office', 'Hot Desk', 'Dedicated Desk'])) ? 'style="display: block;"' : 'style="display: none;"'; ?>>
                    <label for="monthly_rate" class="required">Monthly Rate (UGX)</label>
                    <input type="number" id="monthly_rate" name="monthly_rate" class="form-control" 
                           min="0" step="1000" placeholder="e.g., 500000" 
                           value="<?php echo $edit_space ? ($edit_space['monthly_rate'] ?? '') : ''; ?>">
                    <small class="pricing-help-text">
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
                            <small class="pricing-help-text">Half day = 4 hours</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="full_day_rate" class="required">Full Day Rate (UGX)</label>
                            <input type="number" id="full_day_rate" name="full_day_rate" class="form-control" 
                                   min="0" step="1000" placeholder="e.g., 250000" 
                                   value="<?php echo $edit_space ? $edit_space['full_day_rate'] : ''; ?>">
                            <small class="pricing-help-text">Full day = 8 hours</small>
                        </div>
                    </div>
                </div>
                
                <!-- Amenities and Image -->
                <h4 style="margin: 25px 0 15px 0; color: #2c3e50; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px;">
                    <i class="fas fa-star"></i> Additional Details
                </h4>
                
                <div class="form-group">
                    <label for="amenities">Amenities (one per line)</label>
                    <textarea id="amenities" name="amenities" class="form-control" rows="6" 
                              placeholder="WiFi&#10;Projector&#10;Whiteboard&#10;Air Conditioning&#10;Coffee Machine&#10;Printing Services"><?php echo $edit_space ? htmlspecialchars($edit_space['amenities']) : ''; ?></textarea>
                    <small style="color: #7f8c8d;">Enter each amenity on a new line</small>
                </div>
                
                <div class="form-group">
                    <label for="space_image">Space Image</label>
                    <input type="file" id="space_image" name="space_image" class="form-control" accept="image/*">
                    <?php if ($edit_space && $edit_space['space_image']): ?>
                        <small style="color: #7f8c8d;">
                            Current image: <a href="<?php echo htmlspecialchars(ims_upload_url($edit_space['space_image'])); ?>" target="_blank"><?php echo basename($edit_space['space_image']); ?></a>
                        </small>
                    <?php endif; ?>
                    <small style="display: block; color: #7f8c8d; margin-top: 5px;">
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