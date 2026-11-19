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
/* Manage spaces: 3-per-row cards that collapse to a summary and expand to full details. */
.ms-filters { margin-bottom: 0; }

.ms-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
.ms-toolbar .ms-count { font-size: var(--fs-sm); color: var(--ink-400); }
.ms-toolbar-actions { display: flex; gap: var(--space-2); flex-wrap: wrap; }

.ms-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--space-5);
    align-items: start; /* expanding one card does not stretch its row neighbours */
}

.ms-card {
    background: var(--surface-card);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: box-shadow .2s, border-color .2s;
}

.ms-card:hover { box-shadow: var(--shadow-md); }
.ms-card.is-open { border-color: var(--brand-200); box-shadow: var(--shadow-md); }
.ms-card.unavailable .ms-image,
.ms-card.unavailable .ms-summary { opacity: .7; }

.ms-image {
    display: block;
    width: 100%;
    height: 150px;
    object-fit: cover;
    background: linear-gradient(135deg, var(--brand-600) 0%, var(--brand-400) 100%);
}

/* Summary: always visible; the whole bar toggles the details. */
.ms-summary {
    display: grid;
    gap: var(--space-3);
    width: 100%;
    padding: var(--space-4) var(--space-5);
    background: none;
    border: 0;
    text-align: left;
    cursor: pointer;
    font: inherit;
    color: inherit;
}

.ms-summary:focus-visible { outline: none; box-shadow: inset var(--focus-ring); }

.ms-summary-top { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.ms-summary-title { min-width: 0; }
.ms-summary-title h3 {
    margin: 0 0 var(--space-1);
    font-size: var(--fs-lg);
    color: var(--ink-900);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.ms-chevron {
    flex-shrink: 0;
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--ink-50);
    color: var(--ink-500, #64748b);
    transition: transform .2s, background .2s;
}

.ms-card.is-open .ms-chevron { transform: rotate(180deg); background: var(--brand-50); color: var(--brand-600); }

.ms-summary-meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-4); font-size: var(--fs-sm); }
.ms-summary-meta i { color: var(--brand-500); margin-right: 4px; }
.ms-summary-meta .is-available   { color: var(--green-fg); font-weight: 600; }
.ms-summary-meta .is-unavailable { color: var(--red-fg); font-weight: 600; }
.ms-summary-meta .is-available i,
.ms-summary-meta .is-unavailable i { color: inherit; }
.ms-rate { margin-left: auto; font-weight: 700; color: var(--green-fg); white-space: nowrap; }
.ms-rate small { font-weight: 500; color: var(--ink-400); }
.ms-rate.is-missing { color: var(--red-fg); font-weight: 600; }

/* Details: hidden until expanded. */
.ms-details {
    padding: 0 var(--space-5) var(--space-5);
    border-top: 1px solid var(--ink-50);
}
.ms-details[hidden] { display: none; }

.ms-pricing {
    background: var(--ink-50);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md);
    margin: var(--space-4) 0;
}

.ms-pricing-row { display: flex; justify-content: space-between; margin-bottom: var(--space-2); font-size: var(--fs-sm); }
.ms-pricing-row:last-child { margin-bottom: 0; }
.ms-pricing-row .label { color: var(--ink-400); }
.ms-pricing-row .label.is-missing { color: var(--red-fg); }
.ms-pricing-row .value { font-weight: 600; color: var(--green-fg); }

.ms-amenities { margin: var(--space-4) 0; }
.ms-amenities-label { display: block; font-size: var(--fs-xs); color: var(--ink-400); text-transform: uppercase; letter-spacing: .04em; }
.ms-amenities-list { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-2); }

.ms-availability {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    background: var(--ink-50);
    border-radius: var(--radius-md);
    margin-top: var(--space-4);
}

.ms-actions { display: flex; gap: var(--space-2); margin-top: var(--space-4); flex-wrap: wrap; }

/* Availability switch (no global switch component yet). */
.ms-switch { position: relative; width: 46px; height: 24px; flex-shrink: 0; }
.ms-switch input { opacity: 0; width: 0; height: 0; }
.ms-switch-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: var(--ink-100);
    transition: .3s;
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
    transition: .3s;
    border-radius: 50%;
}
.ms-switch input:checked + .ms-switch-slider { background-color: var(--green-fg); }
.ms-switch input:checked + .ms-switch-slider:before { transform: translateX(22px); }
.ms-switch input:focus-visible + .ms-switch-slider { box-shadow: var(--focus-ring); }

.ms-modal { max-width: 900px; }

.ms-form-section {
    margin: var(--space-6) 0 var(--space-4);
    padding-bottom: var(--space-2);
    color: var(--ink-900);
    border-bottom: 2px solid var(--ink-100);
}

.ms-help { display: block; margin-top: var(--space-1); font-size: var(--fs-xs); color: var(--ink-300); }

@media (max-width: 1100px) {
    .ms-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 680px) {
    .ms-grid { grid-template-columns: 1fr; }
}

@media (prefers-reduced-motion: reduce) {
    .ms-card, .ms-chevron, .ms-switch-slider, .ms-switch-slider:before { transition: none; }
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
    <div class="ms-toolbar">
        <span class="ms-count"><?php echo count($spaces); ?> space<?php echo count($spaces) === 1 ? '' : 's'; ?></span>
        <div class="ms-toolbar-actions">
            <button type="button" class="btn btn-sm btn-soft" onclick="setAllSpaces(true)">
                <i class="fas fa-angles-down" aria-hidden="true"></i> Expand all
            </button>
            <button type="button" class="btn btn-sm btn-soft" onclick="setAllSpaces(false)">
                <i class="fas fa-angles-up" aria-hidden="true"></i> Collapse all
            </button>
        </div>
    </div>

    <div class="ms-grid">
        <?php foreach ($spaces as $space):
            // Desks and private offices are rented monthly; other spaces by the hour/day.
            $monthly_only_types = ['Private Office', 'Hot Desk', 'Dedicated Desk'];
            $show_monthly_only = in_array($space['space_type'], $monthly_only_types, true);
            $space_id = (int)$space['availability_id'];
            $has_monthly = isset($space['monthly_rate']) && (float)$space['monthly_rate'] > 0;
        ?>
        <article class="ms-card <?php echo !$space['is_available'] ? 'unavailable' : ''; ?>" data-space-id="<?php echo $space_id; ?>">
            <?php if ($space['space_image']): ?>
                <img src="<?php echo htmlspecialchars(ims_upload_url($space['space_image'])); ?>" alt="<?php echo htmlspecialchars($space['space_name']); ?>" class="ms-image" loading="lazy">
            <?php else: ?>
                <div class="ms-image" aria-hidden="true"></div>
            <?php endif; ?>

            <button type="button" class="ms-summary" aria-expanded="false" aria-controls="space-details-<?php echo $space_id; ?>">
                <span class="ms-summary-top">
                    <span class="ms-summary-title">
                        <h3><?php echo htmlspecialchars($space['space_name']); ?></h3>
                        <span class="badge badge-info"><?php echo htmlspecialchars($space['space_type']); ?></span>
                    </span>
                    <span class="ms-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                </span>
                <span class="ms-summary-meta">
                    <span><i class="fas fa-users" aria-hidden="true"></i><strong><?php echo (int)$space['capacity']; ?></strong> people</span>
                    <span class="<?php echo $space['is_available'] ? 'is-available' : 'is-unavailable'; ?>">
                        <i class="fas fa-<?php echo $space['is_available'] ? 'check-circle' : 'times-circle'; ?>" aria-hidden="true"></i><?php echo $space['is_available'] ? 'Available' : 'Unavailable'; ?>
                    </span>
                    <?php if ($show_monthly_only): ?>
                        <?php if ($has_monthly): ?>
                            <span class="ms-rate"><?php echo format_currency($space['monthly_rate']); ?> <small>/ month</small></span>
                        <?php else: ?>
                            <span class="ms-rate is-missing"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i>No monthly rate</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="ms-rate"><?php echo format_currency($space['hourly_rate']); ?> <small>/ hour</small></span>
                    <?php endif; ?>
                </span>
            </button>

            <div class="ms-details" id="space-details-<?php echo $space_id; ?>" hidden>
                <?php if ($show_monthly_only): ?>
                    <div class="ms-pricing">
                        <?php if ($has_monthly): ?>
                            <div class="ms-pricing-row">
                                <span class="label">Monthly rate:</span>
                                <span class="value"><?php echo format_currency($space['monthly_rate']); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="ms-pricing-row">
                                <span class="label is-missing"><i class="fas fa-exclamation-triangle"></i> No monthly rate set - edit the space to add one</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="ms-pricing">
                        <div class="ms-pricing-row">
                            <span class="label">Hourly rate:</span>
                            <span class="value"><?php echo format_currency($space['hourly_rate']); ?></span>
                        </div>
                        <div class="ms-pricing-row">
                            <span class="label">Half day:</span>
                            <span class="value"><?php echo format_currency($space['half_day_rate']); ?></span>
                        </div>
                        <div class="ms-pricing-row">
                            <span class="label">Full day:</span>
                            <span class="value"><?php echo format_currency($space['full_day_rate']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($space['amenities']): ?>
                <div class="ms-amenities">
                    <span class="ms-amenities-label">Amenities</span>
                    <div class="ms-amenities-list">
                        <?php foreach (explode("\n", (string)$space['amenities']) as $amenity):
                            $amenity = trim($amenity);
                            if ($amenity === '') {
                                continue;
                            }
                        ?>
                            <span class="badge badge-secondary"><?php echo htmlspecialchars($amenity); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick availability toggle -->
                <div class="ms-availability">
                    <label class="ms-switch">
                        <input type="checkbox"
                               aria-label="Available for booking"
                               <?php echo $space['is_available'] ? 'checked' : ''; ?>
                               onchange="toggleAvailability(<?php echo $space_id; ?>, this)">
                        <span class="ms-switch-slider"></span>
                    </label>
                    <span class="cell-muted">Available for booking</span>
                </div>

                <div class="ms-actions">
                    <a href="?edit=<?php echo $space_id; ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-edit"></i> Edit
                    </a>

                    <a href="view-space-bookings?space_id=<?php echo $space_id; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-calendar"></i> Bookings
                    </a>

                    <?php if (ims_allowed('spaces.delete', ['Administrator'], 'Delete spaces')): ?>
                    <button type="button" onclick="confirmDelete(<?php echo $space_id; ?>, <?= htmlspecialchars(json_encode((string)($space['space_name'])), ENT_QUOTES, 'UTF-8') ?>)"
                            class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
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
function toggleAvailability(spaceId, checkbox) {
    const isAvailable = checkbox.checked;
    const status = isAvailable ? 1 : 0;

    if (confirm(`Are you sure you want to ${isAvailable ? 'enable' : 'disable'} this space?`)) {
        window.location.href = `includes/process-space.php?toggle_availability=${encodeURIComponent(spaceId)}&status=${encodeURIComponent(status)}&csrf_token=<?= h(csrf_token()) ?>`;
    } else {
        checkbox.checked = !isAvailable; // revert when cancelled
    }
}

/* Collapse / expand space cards. Open cards are remembered in this browser. */
const SPACE_OPEN_KEY = 'manageSpace.openCards';

function readOpenSpaces() {
    try {
        const saved = JSON.parse(localStorage.getItem(SPACE_OPEN_KEY) || '[]');
        return Array.isArray(saved) ? saved.map(String) : [];
    } catch (e) {
        return [];
    }
}

function saveOpenSpaces() {
    const open = Array.from(document.querySelectorAll('.ms-card.is-open')).map(function (card) {
        return card.dataset.spaceId;
    });
    try {
        localStorage.setItem(SPACE_OPEN_KEY, JSON.stringify(open));
    } catch (e) {
        /* storage unavailable: state just is not remembered */
    }
}

function setSpaceOpen(card, open) {
    const button = card.querySelector('.ms-summary');
    const details = card.querySelector('.ms-details');
    if (!button || !details) return;
    card.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    details.hidden = !open;
}

function setAllSpaces(open) {
    document.querySelectorAll('.ms-card').forEach(function (card) {
        setSpaceOpen(card, open);
    });
    saveOpenSpaces();
}

document.addEventListener('DOMContentLoaded', function () {
    const remembered = readOpenSpaces();

    document.querySelectorAll('.ms-card').forEach(function (card) {
        if (remembered.indexOf(card.dataset.spaceId) !== -1) {
            setSpaceOpen(card, true);
        }

        const button = card.querySelector('.ms-summary');
        if (button) {
            button.addEventListener('click', function () {
                setSpaceOpen(card, !card.classList.contains('is-open'));
                saveOpenSpaces();
            });
        }
    });
});

// Confirm delete
function confirmDelete(spaceId, spaceName) {
    if (confirm(`Are you sure you want to delete "${spaceName}"?\n\nThis action cannot be undone and will affect all related bookings.`)) {
        window.location.href = `includes/process-space.php?delete_space=${encodeURIComponent(spaceId)}&csrf_token=<?= h(csrf_token()) ?>`;
    }
}
</script>