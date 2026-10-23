<?php
// Frontend - Update KPI Progress
$page_title = 'Update KPI Progress';
include 'includes/header.php';

// Get KPI ID
$kpi_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$kpi_id) {
    send_notification($_SESSION['user_id'], 'Invalid KPI ID', 'danger');
    header("Location: my-kpis");
    exit();
}

// Fetch KPI details
$query = "SELECT k.*, c.category_name, d.department_name
    FROM kpis k
    LEFT JOIN kpi_categories c ON k.category_id = c.category_id
    LEFT JOIN departments d ON k.department_id = d.department_id
    WHERE k.kpi_id = $kpi_id";
$result = $conn->query($query);

if ($result->num_rows == 0) {
    send_notification($_SESSION['user_id'], 'KPI not found', 'danger');
    header("Location: my-kpis");
    exit();
}

$kpi = $result->fetch_assoc();

// Check ownership
if ($kpi['user_id'] != $_SESSION['user_id']) {
    send_notification($_SESSION['user_id'], 'You do not have permission to update this KPI', 'danger');
    header("Location: my-kpis");
    exit();
}

// Only approved KPIs can be updated
if ($kpi['status'] != 'Approved') {
    send_notification($_SESSION['user_id'], 'Only approved KPIs can have progress updated', 'warning');
    header("Location: view-kpi?id=$kpi_id");
    exit();
}

// Determine current quarter based on current month
$current_month = date('n');
$current_quarter = 'Q1';
if ($current_month >= 4 && $current_month <= 6) $current_quarter = 'Q2';
elseif ($current_month >= 7 && $current_month <= 9) $current_quarter = 'Q3';
elseif ($current_month >= 10 && $current_month <= 12) $current_quarter = 'Q4';
?>

<style>
.quarter-selector {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 30px;
}

.quarter-option {
    padding: 20px;
    background: var(--surface-hover);
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
    position: relative;
}

.quarter-option:hover {
    border-color: var(--brand-500);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.quarter-option.selected {
    background: var(--brand-50);
    color: var(--ink-900);
    border-color: var(--brand-600);
}

.quarter-option.completed {
    background: var(--green-bg);
    border-color: var(--green-border);
}

.quarter-label {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 5px;
}

.quarter-period {
    font-size: 12px;
    color: var(--ink-300);
    margin-bottom: 10px;
}

.quarter-target {
    font-size: 14px;
    font-weight: 600;
    margin-top: 10px;
}

.quarter-actual {
    margin-top: 5px;
    font-size: 13px;
    color: var(--green-fg);
}

.quarter-current {
    position: absolute;
    top: -10px;
    right: -10px;
    background: var(--brand-600);
    color: var(--ink-0);
    padding: 4px 10px;
    border-radius: var(--radius-pill);
    font-size: 10px;
    font-weight: 600;
}

.quarter-status-badge {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
}

.progress-form-section {
    background: var(--surface-hover);
    border: 1px solid var(--border);
    padding: 25px;
    border-radius: var(--radius-md);
}

.achievement-preview {
    background: var(--surface-card);
    padding: 20px;
    border-radius: var(--radius-md);
    text-align: center;
    border: 2px dashed var(--ink-100);
}

.achievement-number {
    font-size: 48px;
    font-weight: 700;
    color: var(--brand-600);
    margin: 10px 0;
}

.achievement-label {
    font-size: 14px;
    color: var(--ink-300);
}

.calculation-box {
    background: var(--blue-bg);
    padding: 15px;
    border-radius: var(--radius-sm);
    border-left: 4px solid var(--blue-fg);
    margin: 15px 0;
}

.tips-box {
    background: var(--amber-bg);
    padding: 15px;
    border-radius: var(--radius-sm);
    border-left: 4px solid var(--amber-fg);
    margin-top: 20px;
}

.progress-timeline {
    display: flex;
    gap: 15px;
}

.timeline-item {
    flex: 1;
    background: var(--surface-hover);
    padding: 15px;
    border-radius: var(--radius-md);
    border-left: 4px solid var(--border);
}

.timeline-item.completed {
    border-left-color: var(--green-fg);
}

.timeline-item.pending {
    border-left-color: var(--ink-200);
}

.select-quarter-message {
    text-align: center;
    padding: 40px;
    color: var(--ink-300);
}

.select-quarter-message i {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .quarter-selector { grid-template-columns: repeat(2, 1fr); }
    .progress-timeline { flex-direction: column; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-chart-line" aria-hidden="true"></i> <?php echo htmlspecialchars($kpi['kpi_title']); ?></h1>
        <p>
            <?php echo htmlspecialchars($kpi['department_name']); ?> |
            <?php echo htmlspecialchars($kpi['category_name']); ?> |
            Weight: <?php echo $kpi['weight_percentage']; ?>% |
            Annual Target: <?php echo $kpi['target_value']; ?> <?php echo htmlspecialchars($kpi['unit_of_measure']); ?>
        </p>
    </div>
    <div class="hero-actions">
        <a href="view-kpi?id=<?php echo $kpi_id; ?>" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to KPI Details
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-chart-line" aria-hidden="true"></i> Update Quarterly Progress</h3></div>
    <div class="card-body">
    
    <!-- Quarter Selection -->
    <div class="quarter-selector" id="quarterSelector">
        <?php
        $quarters = [
            'Q1' => ['name' => 'Q1', 'period' => 'January - March', 'months' => '1-3'],
            'Q2' => ['name' => 'Q2', 'period' => 'April - June', 'months' => '4-6'],
            'Q3' => ['name' => 'Q3', 'period' => 'July - September', 'months' => '7-9'],
            'Q4' => ['name' => 'Q4', 'period' => 'October - December', 'months' => '10-12']
        ];
        
        foreach ($quarters as $q_key => $q_info):
            $q_lower = strtolower($q_key);
            $target = $kpi["{$q_lower}_target"];
            $actual = $kpi["{$q_lower}_actual"];
            $status = $kpi["{$q_lower}_status"];
            $achievement = $kpi["{$q_lower}_achievement"];
            
            $is_current = ($q_key == $current_quarter);
            $is_completed = ($status == 'Completed');
            $has_data = ($actual !== null);
        ?>
            <div class="quarter-option <?php echo $is_completed ? 'completed' : ''; ?>" 
                 data-quarter="<?php echo $q_key; ?>"
                 data-target="<?php echo $target; ?>"
                 data-actual="<?php echo $actual ?: ''; ?>"
                 data-status="<?php echo $status; ?>"
                 onclick="selectQuarter('<?php echo $q_key; ?>')">
                
                <?php if ($is_current): ?>
                    <div class="quarter-current">Current</div>
                <?php endif; ?>
                
                <div class="quarter-label"><?php echo $q_info['name']; ?></div>
                <div class="quarter-period"><?php echo $q_info['period']; ?></div>
                <div class="quarter-target">Target: <?php echo $target; ?></div>
                
                <?php if ($has_data): ?>
                    <div class="quarter-actual">
                        Actual: <?php echo $actual; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($is_completed): ?>
                    <span class="quarter-status-badge badge badge-success">
                        ? Completed
                    </span>
                <?php elseif ($has_data): ?>
                    <span class="quarter-status-badge badge badge-info">
                        Recorded
                    </span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Progress Form -->
    <form method="POST" action="kpi-process" id="progressForm" style="display: none;">
        <input type="hidden" name="action" value="update_progress">
        <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
        <input type="hidden" name="quarter" id="selectedQuarter" value="">
        
        <div class="progress-form-section">
            <h4 style="margin-bottom: 20px;">
                <i class="fas fa-edit"></i> Update <span id="quarterDisplay"></span> Progress
            </h4>
            
            <div class="calculation-box">
                <strong>Target for this quarter:</strong> <span id="quarterTargetDisplay"></span> <?php echo htmlspecialchars($kpi['unit_of_measure']); ?>
            </div>
            
            <div class="form-group">
                <label for="actual" class="required">Actual Value Achieved</label>
                <input type="number" id="actual" name="actual" class="form-control" 
                       step="0.01" placeholder="Enter actual value" required>
                <div class="hint-text">Enter the actual value you achieved for this quarter</div>
            </div>
            
            <div class="form-group">
                <label for="status" class="required">Status</label>
                <select id="status" name="status" class="form-control" required>
                    <option value="">Select Status</option>
                    <option value="Not Started">Not Started</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Completed">Completed</option>
                    <option value="Delayed">Delayed</option>
                </select>
                <div class="hint-text">Current status of this quarter's work</div>
            </div>
            
            <div class="form-group">
                <label for="comment">Progress Notes (Optional)</label>
                <textarea id="comment" name="comment" class="form-control" rows="4" 
                          placeholder="Share details about your progress, challenges faced, lessons learned, etc."></textarea>
                <div class="hint-text">Provide context about your achievement</div>
            </div>
            
            <!-- Achievement Preview -->
            <div class="achievement-preview" id="achievementPreview" style="display: none;">
                <div class="achievement-label">Expected Achievement</div>
                <div class="achievement-number" id="achievementPercentage">0%</div>
                <div class="achievement-label" id="achievementStatus"></div>
            </div>
            
            <div class="tips-box">
                <strong><i class="fas fa-lightbulb"></i> Tips for Progress Updates:</strong>
                <ul style="margin: 10px 0 0 20px; font-size: 13px;">
                    <li>Be honest and accurate with your actual values</li>
                    <li>If you exceeded the target, that's great - record the actual number achieved</li>
                    <li>If you fell short, explain what challenges you faced in the notes</li>
                    <li>Regular updates help track trends and adjust future targets</li>
                    <li>Use the notes section to document key learnings or best practices</li>
                </ul>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="button" onclick="cancelUpdate()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="margin-left: 10px;">
                    <i class="fas fa-save"></i> Save Progress
                </button>
            </div>
        </div>
    </form>
    
    <div id="selectQuarterMessage" class="select-quarter-message">
        <i class="fas fa-hand-pointer" aria-hidden="true"></i>
        <h4>Select a quarter above to update progress</h4>
        <p>Click on any quarter to enter or update its progress data</p>
    </div>
    </div>
</div>

<!-- Historical Progress -->
<div class="card historical-progress">
    <div class="card-header"><h3><i class="fas fa-history" aria-hidden="true"></i> Progress Timeline</h3></div>
    <div class="card-body">
    <div class="progress-timeline">
        <?php
        foreach ($quarters as $q_key => $q_info):
            $q_lower = strtolower($q_key);
            $target = $kpi["{$q_lower}_target"];
            $actual = $kpi["{$q_lower}_actual"];
            $status = $kpi["{$q_lower}_status"];
            $achievement = $kpi["{$q_lower}_achievement"];
            
            $is_completed = ($actual !== null);
        ?>
            <div class="timeline-item <?php echo $is_completed ? 'completed' : 'pending'; ?>">
                <div style="font-weight: 600; margin-bottom: 8px;"><?php echo $q_key; ?></div>
                <div class="cell-muted" style="font-size: 12px; margin-bottom: 8px;">
                    <?php echo $q_info['period']; ?>
                </div>
                
                <?php if ($is_completed): ?>
                    <div style="font-size: 13px; margin-bottom: 5px;">
                        <strong>Target:</strong> <?php echo $target; ?>
                    </div>
                    <div style="font-size: 13px; margin-bottom: 5px; color: var(--green-fg);">
                        <strong>Actual:</strong> <?php echo $actual; ?>
                    </div>
                    <div style="font-size: 13px; margin-bottom: 5px;">
                        <strong>Achievement:</strong> <?php echo round($achievement); ?>%
                    </div>
                    <span class="badge badge-<?php 
                        echo $status == 'Completed' ? 'success' : 
                            ($status == 'In Progress' ? 'info' : 
                            ($status == 'Delayed' ? 'danger' : 'secondary')); 
                    ?>">
                        <?php echo $status; ?>
                    </span>
                <?php else: ?>
                    <div style="font-size: 13px; margin-bottom: 5px;">
                        <strong>Target:</strong> <?php echo $target; ?>
                    </div>
                    <div class="cell-muted" style="font-style: italic; font-size: 12px;">
                        Not yet updated
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    </div>
</div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
let selectedQuarterData = null;

function selectQuarter(quarter) {
    // Remove previous selection
    document.querySelectorAll('.quarter-option').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Select new quarter
    const quarterElement = document.querySelector(`[data-quarter="${quarter}"]`);
    quarterElement.classList.add('selected');
    
    // Get quarter data
    const target = parseFloat(quarterElement.dataset.target);
    const actual = quarterElement.dataset.actual;
    const status = quarterElement.dataset.status;
    
    selectedQuarterData = { quarter, target, actual, status };
    
    // Update form
    document.getElementById('selectedQuarter').value = quarter;
    document.getElementById('quarterDisplay').textContent = quarter;
    document.getElementById('quarterTargetDisplay').textContent = target;
    
    // Pre-fill if data exists
    if (actual) {
        document.getElementById('actual').value = actual;
        document.getElementById('status').value = status;
        calculateAchievement();
    } else {
        document.getElementById('actual').value = '';
        document.getElementById('status').value = '';
        document.getElementById('achievementPreview').style.display = 'none';
    }
    
    // Show form, hide message
    document.getElementById('progressForm').style.display = 'block';
    document.getElementById('selectQuarterMessage').style.display = 'none';
    
    // Scroll to form
    document.getElementById('progressForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cancelUpdate() {
    document.getElementById('progressForm').style.display = 'none';
    document.getElementById('selectQuarterMessage').style.display = 'block';
    
    // Remove selection
    document.querySelectorAll('.quarter-option').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Reset form
    document.getElementById('progressForm').reset();
    document.getElementById('achievementPreview').style.display = 'none';
}

function calculateAchievement() {
    const actual = parseFloat(document.getElementById('actual').value) || 0;
    const target = selectedQuarterData ? selectedQuarterData.target : 0;
    
    if (actual > 0 && target > 0) {
        const achievement = (actual / target) * 100;
        const achievementRounded = Math.round(achievement);
        
        document.getElementById('achievementPercentage').textContent = achievementRounded + '%';
        
        let statusText = '';
        let statusColor = '';
        
        if (achievement >= 100) {
            statusText = 'Excellent! Target Exceeded!';
            statusColor = 'var(--green-fg)';
        } else if (achievement >= 80) {
            statusText = 'Good Progress! On Track';
            statusColor = 'var(--blue-fg)';
        } else if (achievement >= 60) {
            statusText = 'Below Target - Action Needed';
            statusColor = 'var(--amber-fg)';
        } else {
            statusText = 'Significantly Below Target';
            statusColor = 'var(--red-fg)';
        }
        
        document.getElementById('achievementStatus').textContent = statusText;
        document.getElementById('achievementStatus').style.color = statusColor;
        document.getElementById('achievementPercentage').style.color = statusColor;
        
        document.getElementById('achievementPreview').style.display = 'block';
    } else {
        document.getElementById('achievementPreview').style.display = 'none';
    }
}

// Auto-calculate achievement as user types
document.getElementById('actual').addEventListener('input', calculateAchievement);

// Form validation
document.getElementById('progressForm').addEventListener('submit', function(e) {
    const actual = parseFloat(document.getElementById('actual').value);
    const status = document.getElementById('status').value;
    
    if (!actual || actual < 0) {
        e.preventDefault();
        alert('Please enter a valid actual value');
        return false;
    }
    
    if (!status) {
        e.preventDefault();
        alert('Please select a status');
        return false;
    }
    
    return true;
});

// Auto-select current quarter on load
window.addEventListener('load', function() {
    const currentQuarter = '<?php echo $current_quarter; ?>';
    selectQuarter(currentQuarter);
});
</script>
