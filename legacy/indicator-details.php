<?php
// Frontend - Indicator Details Display
$page_title = 'Indicator Details';
include 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Get indicator ID
$indicator_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$indicator_id) {
    send_notification($_SESSION['user_id'], 'Invalid indicator ID', 'danger');
    header("Location: indicators");
    exit();
}

// Fetch indicator details
$query = "SELECT i.*, p.project_name, p.project_code 
    FROM indicators i 
    LEFT JOIN projects p ON i.project_id = p.project_id 
    WHERE i.indicator_id = $indicator_id";
$result = $conn->query($query);

if ($result->num_rows == 0) {
    send_notification($_SESSION['user_id'], 'Indicator not found', 'danger');
    header("Location: indicators");
    exit();
}

$indicator = $result->fetch_assoc();

// Calculate achievement
$achievement = 0;
if ($indicator['target_value'] > 0) {
    $achievement = ($indicator['current_value'] / $indicator['target_value']) * 100;
}

// Fetch progress updates
$progress_updates = [];
$query = "SELECT * FROM indicator_progress WHERE indicator_id = $indicator_id ORDER BY reporting_period DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $progress_updates[] = $row;
}

// Get achievement status
$status_color = 'var(--red-fg)';
$status_badge = 'badge-danger';
$status_text = 'Below Target';
if ($achievement >= 100) {
    $status_color = 'var(--green-fg)';
    $status_badge = 'badge-success';
    $status_text = 'Target Achieved';
} elseif ($achievement >= 75) {
    $status_color = 'var(--amber-fg)';
    $status_badge = 'badge-warning';
    $status_text = 'On Track';
} elseif ($achievement >= 50) {
    $status_color = 'var(--amber-fg)';
    $status_badge = 'badge-warning';
    $status_text = 'Needs Attention';
}
?>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-chart-bar" aria-hidden="true"></i> <?php echo htmlspecialchars($indicator['indicator_name']); ?></h1>
        <p>
            <span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span>
            <span class="badge badge-light"><?php echo $indicator['indicator_type']; ?></span>
            &nbsp;Indicator details, achievement and progress history.
        </p>
    </div>
    <div class="hero-actions">
        <a href="indicators" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Indicators
        </a>
        <?php if (ims_allowed('indicators.edit', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer'], 'Edit indicators and update progress')): ?>
            <button type="button" onclick="openModal('editIndicatorModal')" class="btn">
                <i class="fas fa-edit" aria-hidden="true"></i> Edit Indicator
            </button>
            <button type="button" onclick="openModal('updateProgressModal')" class="btn btn-primary">
                <i class="fas fa-chart-line" aria-hidden="true"></i> Update Progress
            </button>
        <?php endif; ?>
        <button type="button" onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
    </div>
</div>

<!-- Indicator Overview -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-circle-info" aria-hidden="true"></i> Overview</h3>
    </div>

    <div class="card-body">
        <div class="idt-info-grid">
            <!-- Project Info -->
            <div>
                <h4 class="idt-section-title">
                    <i class="fas fa-project-diagram" aria-hidden="true"></i> Project
                </h4>
                <div class="idt-project-box">
                    <div class="idt-label">Project Code</div>
                    <div class="idt-value idt-gap"><?php echo htmlspecialchars($indicator['project_code']); ?></div>
                    <div class="idt-label">Project Name</div>
                    <div class="idt-value"><?php echo htmlspecialchars($indicator['project_name']); ?></div>
                </div>
            </div>

            <!-- Measurement Info -->
            <div>
                <h4 class="idt-section-title">
                    <i class="fas fa-ruler" aria-hidden="true"></i> Measurement
                </h4>
                <table class="idt-kv">
                    <tr>
                        <th scope="row">Unit:</th>
                        <td><?php echo htmlspecialchars($indicator['unit_of_measure']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Baseline:</th>
                        <td>
                            <?php echo $indicator['baseline_value'] !== null ? number_format($indicator['baseline_value'], 2) : 'N/A'; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Target:</th>
                        <td class="idt-target">
                            <?php echo number_format($indicator['target_value'], 2); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Current:</th>
                        <td class="idt-current">
                            <?php echo number_format($indicator['current_value'], 2); ?>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Data Collection -->
            <div>
                <h4 class="idt-section-title">
                    <i class="fas fa-database" aria-hidden="true"></i> Data Collection
                </h4>
                <table class="idt-kv">
                    <tr>
                        <th scope="row">Source:</th>
                        <td><?php echo htmlspecialchars($indicator['data_source'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Method:</th>
                        <td><?php echo htmlspecialchars($indicator['collection_method'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Frequency:</th>
                        <td><?php echo htmlspecialchars($indicator['reporting_frequency'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Last Updated:</th>
                        <td>
                            <?php echo $indicator['last_updated'] ? date('d M Y', strtotime($indicator['last_updated'])) : 'Never'; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Achievement Progress -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bullseye" aria-hidden="true"></i> Achievement Progress</h3>
        <span class="idt-ach-pct" style="color: <?php echo $status_color; ?>;">
            <?php echo number_format($achievement, 1); ?>%
        </span>
    </div>
    <div class="card-body">
        <div class="idt-track">
            <div class="idt-fill" style="width: <?php echo min($achievement, 100); ?>%; background: <?php echo $status_color; ?>;">
                <?php echo number_format($achievement, 1); ?>%
            </div>
        </div>

        <div class="stats-grid reports-stats idt-summary-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue"><i class="fas fa-flag" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Baseline</div>
                    <div class="stat-value">
                        <?php echo $indicator['baseline_value'] !== null ? number_format($indicator['baseline_value'], 2) : 'N/A'; ?>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Current Value</div>
                    <div class="stat-value">
                        <?php echo number_format($indicator['current_value'], 2); ?>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-amber"><i class="fas fa-bullseye" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Target</div>
                    <div class="stat-value">
                        <?php echo number_format($indicator['target_value'], 2); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Progress Updates -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history" aria-hidden="true"></i> Progress Updates (<?php echo count($progress_updates); ?>)</h3>
        <button type="button" onclick="openModal('addProgressModal')" class="btn btn-primary btn-sm">
            <i class="fas fa-plus" aria-hidden="true"></i> Add Update
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($progress_updates)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                <p>No progress updates yet. Add updates to track indicator performance over time.</p>
                <button type="button" onclick="openModal('addProgressModal')" class="btn btn-primary">
                    <i class="fas fa-plus" aria-hidden="true"></i> Add First Update
                </button>
            </div>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($progress_updates as $update): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <div class="idt-update-head">
                                <div>
                                    <strong class="idt-update-value">
                                        <?php echo number_format($update['actual_value'], 2); ?> <?php echo htmlspecialchars($indicator['unit_of_measure']); ?>
                                    </strong>
                                    <?php if (isset($update['achievement_percentage']) && $update['achievement_percentage'] !== null): ?>
                                        <span class="idt-update-pct" style="color: <?php
                                            $ach = $update['achievement_percentage'];
                                            echo $ach >= 100 ? 'var(--green-fg)' : ($ach >= 50 ? 'var(--amber-fg)' : 'var(--red-fg)');
                                        ?>;">
                                            (<?php echo number_format($update['achievement_percentage'], 1); ?>%)
                                        </span>
                                    <?php endif; ?>
                                    <div class="idt-update-meta">
                                        <i class="fas fa-calendar" aria-hidden="true"></i> <?php echo date('M Y', strtotime($update['reporting_period'])); ?>
                                        <?php if ($update['recorded_date']): ?>
                                            <span class="idt-gap-left">
                                                <i class="fas fa-clock" aria-hidden="true"></i> Recorded: <?php echo date('d M Y', strtotime($update['recorded_date'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (isset($update['data_quality_score']) && $update['data_quality_score']): ?>
                                        <div class="idt-quality">
                                            <span class="idt-hint">Data Quality: </span>
                                            <?php
                                            $score = $update['data_quality_score'];
                                            $score_color = $score >= 4 ? 'var(--green-fg)' : ($score >= 3 ? 'var(--amber-fg)' : 'var(--red-fg)');
                                            $score_text = ['1' => 'Very Poor', '2' => 'Poor', '3' => 'Fair', '4' => 'Good', '5' => 'Excellent'];
                                            ?>
                                            <span class="idt-quality-score" style="color: <?php echo $score_color; ?>;">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star<?php echo $i <= $score ? '' : '-o'; ?>"></i>
                                                <?php endfor; ?>
                                                <?php echo $score_text[$score]; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right">
                                    <?php if ($update['responsible_person']): ?>
                                        <div class="idt-update-meta">
                                            <i class="fas fa-user" aria-hidden="true"></i> <?php echo htmlspecialchars($update['responsible_person']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="cell-muted">
                                        <?php echo time_ago($update['created_at']); ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($update['progress_notes']): ?>
                                <div class="idt-notes">
                                    <?php echo nl2br(htmlspecialchars($update['progress_notes'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.page-stack -->

<!-- Update Progress Modal -->
<div id="updateProgressModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Update Progress</h3>
            <span class="close" onclick="closeModal('updateProgressModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/indicator-details-process.php?id=<?php echo $indicator_id; ?>">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    This will update the current value of the indicator and create a progress record.
                </div>
                
                <div class="form-group">
                    <label for="current_value" class="required">Current Value</label>
                    <input type="number" step="0.01" id="current_value" name="current_value" class="form-control" 
                           value="<?php echo $indicator['current_value']; ?>" required>
                    <small class="idt-hint">Target: <?php echo number_format($indicator['target_value'], 2); ?> <?php echo htmlspecialchars($indicator['unit_of_measure']); ?></small>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="reporting_period" class="required">Reporting Period</label>
                        <input type="month" id="reporting_period" name="reporting_period" class="form-control" 
                               value="<?php echo date('Y-m'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="recorded_date">Recorded Date</label>
                        <input type="date" id="recorded_date" name="recorded_date" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>">
                        <small class="idt-hint">Date when data was collected</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="data_quality_score">Data Quality Score (1-5)</label>
                    <select id="data_quality_score" name="data_quality_score" class="form-control">
                        <option value="">Not rated</option>
                        <option value="5">5 - Excellent (Verified, complete, accurate)</option>
                        <option value="4">4 - Good (Minor issues, mostly accurate)</option>
                        <option value="3">3 - Fair (Some concerns, partially verified)</option>
                        <option value="2">2 - Poor (Multiple issues, questionable accuracy)</option>
                        <option value="1">1 - Very Poor (Unreliable, incomplete)</option>
                    </select>
                    <small class="idt-hint">Rate the quality and reliability of this data</small>
                </div>
                
                <div class="form-group">
                    <label for="responsible_person">Responsible Person</label>
                    <input type="text" id="responsible_person" name="responsible_person" class="form-control" 
                           value="<?php echo htmlspecialchars($_SESSION['full_name']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="progress_notes">Progress Notes</label>
                    <textarea id="progress_notes" name="progress_notes" class="form-control" rows="4"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('updateProgressModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="update_progress" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Progress
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Indicator Modal -->
<div id="editIndicatorModal" class="modal">
    <div class="modal-content idt-modal-wide">
        <div class="modal-header">
            <h3>Edit Indicator</h3>
            <span class="close" onclick="closeModal('editIndicatorModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/indicator-details-process.php?id=<?php echo $indicator_id; ?>">
                <div class="form-group">
                    <label for="edit_indicator_name" class="required">Indicator Name</label>
                    <input type="text" id="edit_indicator_name" name="indicator_name" class="form-control" 
                           value="<?php echo htmlspecialchars($indicator['indicator_name']); ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_indicator_type" class="required">Type</label>
                        <select id="edit_indicator_type" name="indicator_type" class="form-control" required>
                            <option value="Output" <?php echo $indicator['indicator_type'] == 'Output' ? 'selected' : ''; ?>>Output</option>
                            <option value="Outcome" <?php echo $indicator['indicator_type'] == 'Outcome' ? 'selected' : ''; ?>>Outcome</option>
                            <option value="Impact" <?php echo $indicator['indicator_type'] == 'Impact' ? 'selected' : ''; ?>>Impact</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_unit_of_measure" class="required">Unit of Measure</label>
                        <input type="text" id="edit_unit_of_measure" name="unit_of_measure" class="form-control" 
                               value="<?php echo htmlspecialchars($indicator['unit_of_measure']); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_baseline_value">Baseline Value</label>
                        <input type="number" step="0.01" id="edit_baseline_value" name="baseline_value" class="form-control" 
                               value="<?php echo $indicator['baseline_value']; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_target_value" class="required">Target Value</label>
                        <input type="number" step="0.01" id="edit_target_value" name="target_value" class="form-control" 
                               value="<?php echo $indicator['target_value']; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_data_source">Data Source</label>
                        <input type="text" id="edit_data_source" name="data_source" class="form-control" 
                               value="<?php echo htmlspecialchars($indicator['data_source']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_collection_method">Collection Method</label>
                        <input type="text" id="edit_collection_method" name="collection_method" class="form-control" 
                               value="<?php echo htmlspecialchars($indicator['collection_method']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_reporting_frequency">Reporting Frequency</label>
                    <select id="edit_reporting_frequency" name="reporting_frequency" class="form-control">
                        <option value="Monthly" <?php echo $indicator['reporting_frequency'] == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                        <option value="Quarterly" <?php echo $indicator['reporting_frequency'] == 'Quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                        <option value="Semi-Annually" <?php echo $indicator['reporting_frequency'] == 'Semi-Annually' ? 'selected' : ''; ?>>Semi-Annually</option>
                        <option value="Annually" <?php echo $indicator['reporting_frequency'] == 'Annually' ? 'selected' : ''; ?>>Annually</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('editIndicatorModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="edit_indicator" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Indicator
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Progress Update Modal -->
<div id="addProgressModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Progress Update</h3>
            <span class="close" onclick="closeModal('addProgressModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/indicator-details-process.php?id=<?php echo $indicator_id; ?>">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Record a progress update without changing the current indicator value.
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="add_reporting_period" class="required">Reporting Period</label>
                        <input type="month" id="add_reporting_period" name="reporting_period" class="form-control" 
                               value="<?php echo date('Y-m'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_recorded_date">Recorded Date</label>
                        <input type="date" id="add_recorded_date" name="recorded_date" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>">
                        <small class="idt-hint">Date when data was collected</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="add_actual_value" class="required">Actual Value</label>
                    <input type="number" step="0.01" id="add_actual_value" name="actual_value" class="form-control" required>
                    <small class="idt-hint">Current: <?php echo number_format($indicator['current_value'], 2); ?> | Target: <?php echo number_format($indicator['target_value'], 2); ?></small>
                </div>
                
                <div class="form-group">
                    <label for="add_data_quality_score">Data Quality Score (1-5)</label>
                    <select id="add_data_quality_score" name="data_quality_score" class="form-control">
                        <option value="">Not rated</option>
                        <option value="5">5 - Excellent (Verified, complete, accurate)</option>
                        <option value="4">4 - Good (Minor issues, mostly accurate)</option>
                        <option value="3">3 - Fair (Some concerns, partially verified)</option>
                        <option value="2">2 - Poor (Multiple issues, questionable accuracy)</option>
                        <option value="1">1 - Very Poor (Unreliable, incomplete)</option>
                    </select>
                    <small class="idt-hint">Rate the quality and reliability of this data</small>
                </div>
                
                <div class="form-group">
                    <label for="add_responsible_person">Responsible Person</label>
                    <input type="text" id="add_responsible_person" name="responsible_person" class="form-control" 
                           value="<?php echo htmlspecialchars($_SESSION['full_name']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="add_progress_notes">Progress Notes</label>
                    <textarea id="add_progress_notes" name="progress_notes" class="form-control" rows="4"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addProgressModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="add_progress_update" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Indicator details: page-only layout (idt- prefix). Header, cards, stat
   cards, badges, buttons, empty states and modals are global. */
.idt-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-5); }

.idt-section-title {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--brand-500);
    color: var(--ink-700);
}

.idt-project-box { padding: 15px; background: var(--ink-50); border-radius: var(--radius-sm); }
.idt-label { margin-bottom: 5px; font-size: var(--fs-sm); color: var(--ink-300); }
.idt-value { font-weight: 500; color: var(--ink-700); }
.idt-gap { margin-bottom: 15px; }
.idt-gap-left { margin-left: 10px; }
.idt-hint { color: var(--ink-300); }
.idt-modal-wide { max-width: 800px; }

.idt-kv { width: 100%; background: transparent; }
.idt-kv tr { border-bottom: 1px solid var(--ink-100); }
.idt-kv tr:last-child { border-bottom: none; }
.idt-kv th,
.idt-kv td { padding: 10px 0; border: none; background: transparent; text-align: left; }
.idt-kv th { width: 40%; font-weight: 400; color: var(--ink-300); }
.idt-kv td { font-weight: 500; color: var(--ink-700); }
.idt-kv tr:hover { background: transparent; }
.idt-kv .idt-target { color: var(--brand-600); }
.idt-kv .idt-current { color: var(--green-fg); }

.idt-ach-pct { font-size: 24px; font-weight: 800; }

.idt-track {
    height: 40px;
    margin-bottom: var(--space-5);
    overflow: hidden;
    border-radius: var(--radius-pill);
    background: var(--ink-50);
}

.idt-fill {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--ink-0);
    font-size: var(--fs-sm);
    font-weight: 700;
    transition: width .5s var(--ease-out);
}

.idt-summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 0; }

.idt-update-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-3);
    margin-bottom: 10px;
}

.idt-update-value { font-size: 18px; color: var(--brand-600); }
.idt-update-pct { margin-left: 10px; font-size: var(--fs-base); }
.idt-update-meta { margin-top: 5px; font-size: var(--fs-sm); color: var(--ink-300); }
.idt-quality { margin-top: 5px; font-size: var(--fs-sm); }
.idt-quality-score { font-weight: 500; }

.idt-notes {
    padding: 15px;
    border-left: 3px solid var(--brand-500);
    border-radius: var(--radius-sm);
    background: var(--ink-50);
    color: var(--ink-500);
}

.timeline {
    position: relative;
    padding: 20px 0;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--ink-100);
}

.timeline-item {
    position: relative;
    padding-left: 60px;
    margin-bottom: 30px;
}

.timeline-marker {
    position: absolute;
    left: 11px;
    top: 0;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--brand-500);
    border: 3px solid var(--surface-card);
    box-shadow: 0 0 0 2px var(--brand-500);
}

.timeline-content {
    background: var(--surface-card);
    padding: 20px;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-sm);
}

@media (max-width: 768px) {
    .idt-summary-grid { grid-template-columns: 1fr; }
    .idt-update-head { flex-direction: column; }
    .idt-update-head .text-right { text-align: left; }
}

@media print {
    .btn, .modal { display: none !important; }
}
</style>

<?php include 'includes/footer.php'; ?>