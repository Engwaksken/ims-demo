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
$status_color = '#E74C3C';
$status_text = 'Below Target';
if ($achievement >= 100) {
    $status_color = '#2ECC71';
    $status_text = 'Target Achieved';
} elseif ($achievement >= 75) {
    $status_color = '#F39C12';
    $status_text = 'On Track';
} elseif ($achievement >= 50) {
    $status_color = '#F39C12';
    $status_text = 'Needs Attention';
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <a href="indicators" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Indicators
        </a>
    </div>
    <div>
        <?php if (ims_allowed('indicators.edit', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer'], 'Edit indicators and update progress')): ?>
            <button onclick="openModal('editIndicatorModal')" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit Indicator
            </button>
            <button onclick="openModal('updateProgressModal')" class="btn btn-success">
                <i class="fas fa-chart-line"></i> Update Progress
            </button>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-secondary">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- Indicator Header -->
<div class="card">
    <div class="card-header" style="background: linear-gradient(135deg, var(--primary-color), #3498DB); color: white;">
        <h3 style="color: white; margin: 0;">
            <i class="fas fa-chart-bar"></i> 
            <?php echo htmlspecialchars($indicator['indicator_name']); ?>
        </h3>
        <div style="display: flex; gap: 10px;">
            <span class="badge" style="background: <?php echo $status_color; ?>; font-size: 14px; padding: 8px 12px;">
                <?php echo $status_text; ?>
            </span>
            <span class="badge badge-light" style="font-size: 14px; padding: 8px 12px; color: #333;">
                <?php echo $indicator['indicator_type']; ?>
            </span>
        </div>
    </div>
    
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <!-- Project Info -->
            <div>
                <h4 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fas fa-project-diagram"></i> Project
                </h4>
                <div style="padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 5px;">Project Code</div>
                    <div style="font-weight: 500; margin-bottom: 15px;"><?php echo htmlspecialchars($indicator['project_code']); ?></div>
                    <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 5px;">Project Name</div>
                    <div style="font-weight: 500;"><?php echo htmlspecialchars($indicator['project_name']); ?></div>
                </div>
            </div>
            
            <!-- Measurement Info -->
            <div>
                <h4 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fas fa-ruler"></i> Measurement
                </h4>
                <table style="width: 100%;">
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d; width: 40%;">Unit:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($indicator['unit_of_measure']); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;">Baseline:</td>
                        <td style="padding: 10px 0; font-weight: 500;">
                            <?php echo $indicator['baseline_value'] !== null ? number_format($indicator['baseline_value'], 2) : 'N/A'; ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;">Target:</td>
                        <td style="padding: 10px 0; font-weight: 500; color: var(--primary-color);">
                            <?php echo number_format($indicator['target_value'], 2); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; color: #7f8c8d;">Current:</td>
                        <td style="padding: 10px 0; font-weight: 500; color: #2ECC71;">
                            <?php echo number_format($indicator['current_value'], 2); ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Data Collection -->
            <div>
                <h4 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fas fa-database"></i> Data Collection
                </h4>
                <table style="width: 100%;">
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d; width: 40%;">Source:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($indicator['data_source'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;">Method:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($indicator['collection_method'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;">Frequency:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($indicator['reporting_frequency'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; color: #7f8c8d;">Last Updated:</td>
                        <td style="padding: 10px 0; font-weight: 500;">
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
        <h3><i class="fas fa-bullseye"></i> Achievement Progress</h3>
        <span style="font-size: 24px; font-weight: bold; color: <?php echo $status_color; ?>;">
            <?php echo number_format($achievement, 1); ?>%
        </span>
    </div>
    <div class="card-body">
        <div class="progress" style="height: 40px; margin-bottom: 20px;">
            <div class="progress-bar" style="width: <?php echo min($achievement, 100); ?>%; background: <?php echo $status_color; ?>;">
                <?php echo number_format($achievement, 1); ?>%
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
            <div style="text-align: center; padding: 20px; background: #E3F2FD; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold; color: #2196F3;">
                    <?php echo $indicator['baseline_value'] !== null ? number_format($indicator['baseline_value'], 2) : 'N/A'; ?>
                </div>
                <div style="color: #7f8c8d; margin-top: 5px;">Baseline</div>
            </div>
            
            <div style="text-align: center; padding: 20px; background: #E8F5E9; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold; color: #2ECC71;">
                    <?php echo number_format($indicator['current_value'], 2); ?>
                </div>
                <div style="color: #7f8c8d; margin-top: 5px;">Current Value</div>
            </div>
            
            <div style="text-align: center; padding: 20px; background: #FFF3E0; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold; color: #FF9800;">
                    <?php echo number_format($indicator['target_value'], 2); ?>
                </div>
                <div style="color: #7f8c8d; margin-top: 5px;">Target</div>
            </div>
        </div>
    </div>
</div>

<!-- Progress Updates -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Progress Updates (<?php echo count($progress_updates); ?>)</h3>
        <button onclick="openModal('addProgressModal')" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Update
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($progress_updates)): ?>
            <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                <i class="fas fa-clipboard-list" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                <h4>No Progress Updates Yet</h4>
                <p>Add progress updates to track indicator performance over time.</p>
                <button onclick="openModal('addProgressModal')" class="btn btn-primary" style="margin-top: 20px;">
                    <i class="fas fa-plus"></i> Add First Update
                </button>
            </div>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($progress_updates as $update): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                <div>
                                    <strong style="font-size: 18px; color: var(--primary-color);">
                                        <?php echo number_format($update['actual_value'], 2); ?> <?php echo htmlspecialchars($indicator['unit_of_measure']); ?>
                                    </strong>
                                    <?php if (isset($update['achievement_percentage']) && $update['achievement_percentage'] !== null): ?>
                                        <span style="margin-left: 10px; font-size: 14px; color: <?php 
                                            $ach = $update['achievement_percentage'];
                                            echo $ach >= 100 ? '#2ECC71' : ($ach >= 50 ? '#F39C12' : '#E74C3C'); 
                                        ?>;">
                                            (<?php echo number_format($update['achievement_percentage'], 1); ?>%)
                                        </span>
                                    <?php endif; ?>
                                    <div style="color: #7f8c8d; font-size: 14px; margin-top: 5px;">
                                        <i class="fas fa-calendar"></i> <?php echo date('M Y', strtotime($update['reporting_period'])); ?>
                                        <?php if ($update['recorded_date']): ?>
                                            <span style="margin-left: 10px;">
                                                <i class="fas fa-clock"></i> Recorded: <?php echo date('d M Y', strtotime($update['recorded_date'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (isset($update['data_quality_score']) && $update['data_quality_score']): ?>
                                        <div style="margin-top: 5px;">
                                            <span style="font-size: 13px; color: #7f8c8d;">Data Quality: </span>
                                            <?php
                                            $score = $update['data_quality_score'];
                                            $score_color = $score >= 4 ? '#2ECC71' : ($score >= 3 ? '#F39C12' : '#E74C3C');
                                            $score_text = ['1' => 'Very Poor', '2' => 'Poor', '3' => 'Fair', '4' => 'Good', '5' => 'Excellent'];
                                            ?>
                                            <span style="color: <?php echo $score_color; ?>; font-weight: 500;">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star<?php echo $i <= $score ? '' : '-o'; ?>"></i>
                                                <?php endfor; ?>
                                                <?php echo $score_text[$score]; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div style="text-align: right;">
                                    <?php if ($update['responsible_person']): ?>
                                        <div style="font-size: 13px; color: #7f8c8d;">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($update['responsible_person']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div style="font-size: 12px; color: #95A5A6; margin-top: 3px;">
                                        <?php echo time_ago($update['created_at']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($update['progress_notes']): ?>
                                <div style="padding: 15px; background: #f8f9fa; border-radius: 5px; border-left: 3px solid var(--primary-color);">
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
                    <small style="color: #7f8c8d;">Target: <?php echo number_format($indicator['target_value'], 2); ?> <?php echo htmlspecialchars($indicator['unit_of_measure']); ?></small>
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
                        <small style="color: #7f8c8d;">Date when data was collected</small>
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
                    <small style="color: #7f8c8d;">Rate the quality and reliability of this data</small>
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
                    <button type="submit" name="update_progress" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Progress
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Indicator Modal -->
<div id="editIndicatorModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
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
                    <button type="submit" name="edit_indicator" class="btn btn-success">
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
                        <small style="color: #7f8c8d;">Date when data was collected</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="add_actual_value" class="required">Actual Value</label>
                    <input type="number" step="0.01" id="add_actual_value" name="actual_value" class="form-control" required>
                    <small style="color: #7f8c8d;">Current: <?php echo number_format($indicator['current_value'], 2); ?> | Target: <?php echo number_format($indicator['target_value'], 2); ?></small>
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
                    <small style="color: #7f8c8d;">Rate the quality and reliability of this data</small>
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
                    <button type="submit" name="add_progress_update" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
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
    background: #ddd;
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
    background: var(--primary-color);
    border: 3px solid white;
    box-shadow: 0 0 0 2px var(--primary-color);
}

.timeline-content {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

@media print {
    .btn, .modal { display: none !important; }
}
</style>

<?php include 'includes/footer.php'; ?>