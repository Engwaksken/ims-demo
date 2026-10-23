<?php
// Frontend - Create KPI (also serves edit-kpi.php?id=N in edit mode)
$kpi_edit_id = isset($kpi_edit_id) ? (int) $kpi_edit_id : 0;
$page_title = $kpi_edit_id > 0 ? 'Edit KPI' : 'Create KPI';
include_once __DIR__ . '/includes/header.php';

$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

// Get user's department
$user_dept = null;
$stmt = $conn->prepare("SELECT ed.department_id, d.department_name
    FROM employee_directory ed
    LEFT JOIN departments d ON ed.department_id = d.department_id
    WHERE ed.user_id = ?
    LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $user_dept = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Edit mode: load the KPI (owner only, Draft/Rejected only)
$editing_kpi = null;
if ($kpi_edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM kpis WHERE kpi_id = ? AND user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $kpi_edit_id, $current_user_id);
        $stmt->execute();
        $editing_kpi = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$editing_kpi) {
        send_notification($current_user_id, 'KPI not found or you do not have permission to edit it', 'danger');
        header("Location: my-kpis");
        exit();
    }

    if (!in_array($editing_kpi['status'], ['Draft', 'Rejected'], true)) {
        send_notification($current_user_id, 'Only draft or rejected KPIs can be edited', 'warning');
        header("Location: view-kpi?id=" . (int) $editing_kpi['kpi_id']);
        exit();
    }
}

if (!$user_dept || !$user_dept['department_id']) {
    send_notification($_SESSION['user_id'], 'Please complete your employee profile first', 'warning');
    header("Location: my-profile");
    exit();
}

// Fetch KPI categories
$categories = [];
$result = $conn->query("SELECT * FROM kpi_categories ORDER BY category_name");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

// Default year
$current_year = date('Y');
?>

<style>
.form-section {
    background: var(--surface-card);
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    margin-bottom: 20px;
}

.section-title i { color: var(--brand-600); }

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--ink-900);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}

.quarterly-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
}

.quarter-input {
    padding: 15px;
    background: var(--surface-hover);
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
}

.quarter-label {
    font-weight: 600;
    color: var(--brand-700);
    margin-bottom: 10px;
    display: block;
}

.hint-text {
    font-size: 13px;
    color: var(--ink-300);
    margin-top: 5px;
}

.quarter-total {
    margin-top: 15px;
    padding: 12px;
    background: var(--amber-bg);
    border: 1px solid var(--amber-border);
    border-radius: var(--radius-sm);
    text-align: center;
}

#targetWarning { color: var(--red-fg); margin-left: 10px; }

.form-section.form-actions-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
}

@media (max-width: 768px) {
    .quarterly-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 420px) {
    .quarterly-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-bullseye" aria-hidden="true"></i> <?php echo $editing_kpi ? 'Edit KPI' : 'Create New KPI'; ?></h1>
        <p>Define a SMART KPI with annual and quarterly targets for performance tracking.</p>
    </div>
    <div class="hero-actions">
        <a href="my-kpis" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to My KPIs
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i> KPI Details</h3>
    </div>
    
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Department:</strong> <?php echo h($user_dept['department_name'] ?? ''); ?><br>
            <strong>Note:</strong> Create SMART KPIs (Specific, Measurable, Achievable, Relevant, Time-bound) for effective performance tracking.
        </div>
        
        <form method="POST" action="kpi-process" id="kpiForm" data-form-tabs="sections">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="department_id" value="<?php echo (int) $user_dept['department_id']; ?>">
            
            <!-- Basic Information -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> Basic Information
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="fiscal_year" class="required">Fiscal Year</label>
                        <select id="fiscal_year" name="fiscal_year" class="form-control" required>
                            <option value="<?php echo $current_year; ?>" selected><?php echo $current_year; ?></option>
                            <option value="<?php echo $current_year + 1; ?>"><?php echo $current_year + 1; ?></option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="category_id" class="required">KPI Category</label>
                        <select id="category_id" name="category_id" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int) $cat['category_id']; ?>">
                                    <?php echo h($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint-text">Choose the category that best fits your KPI</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="kpi_title" class="required">KPI Title</label>
                    <input type="text" id="kpi_title" name="kpi_title" class="form-control" 
                           placeholder="e.g., Increase project completion rate" required maxlength="200">
                    <div class="hint-text">Clear and concise title describing what you want to achieve</div>
                </div>
                
                <div class="form-group">
                    <label for="kpi_description">KPI Description</label>
                    <textarea id="kpi_description" name="kpi_description" class="form-control" rows="3" 
                              placeholder="Provide a detailed description of this KPI..."></textarea>
                    <div class="hint-text">Explain the purpose and context of this KPI</div>
                </div>
                
                <div class="form-group">
                    <label for="measurement_criteria" class="required">Measurement Criteria</label>
                    <textarea id="measurement_criteria" name="measurement_criteria" class="form-control" rows="3" 
                              placeholder="How will this KPI be measured? What data sources will be used?" required></textarea>
                    <div class="hint-text">Define clear, specific criteria for measuring success</div>
                </div>
            </div>
            
            <!-- Targets & Metrics -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-bullseye"></i> Targets & Metrics
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="target_value" class="required">Annual Target Value</label>
                        <input type="number" id="target_value" name="target_value" class="form-control" 
                               step="0.01" placeholder="100" required>
                        <div class="hint-text">Total target for the entire year</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="unit_of_measure" class="required">Unit of Measure</label>
                        <input type="text" id="unit_of_measure" name="unit_of_measure" class="form-control" 
                               placeholder="e.g., %, projects, hours, reports" required maxlength="50">
                        <div class="hint-text">How is this KPI measured? (percentage, count, hours, etc.)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="weight_percentage" class="required">Weight (%)</label>
                        <input type="number" id="weight_percentage" name="weight_percentage" class="form-control" 
                               min="0" max="100" step="0.01" placeholder="20" required>
                        <div class="hint-text">Importance of this KPI (total should not exceed 100%)</div>
                    </div>
                </div>
            </div>
            
            <!-- Quarterly Targets -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-calendar-alt"></i> Quarterly Targets
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Break down your annual target into quarterly milestones. The sum of quarterly targets should equal your annual target.
                </div>
                
                <div class="quarterly-grid">
                    <div class="quarter-input">
                        <label for="q1_target" class="quarter-label">Q1 Target (Jan-Mar)</label>
                        <input type="number" id="q1_target" name="q1_target" class="form-control" 
                               step="0.01" placeholder="25" required>
                    </div>
                    
                    <div class="quarter-input">
                        <label for="q2_target" class="quarter-label">Q2 Target (Apr-Jun)</label>
                        <input type="number" id="q2_target" name="q2_target" class="form-control" 
                               step="0.01" placeholder="25" required>
                    </div>
                    
                    <div class="quarter-input">
                        <label for="q3_target" class="quarter-label">Q3 Target (Jul-Sep)</label>
                        <input type="number" id="q3_target" name="q3_target" class="form-control" 
                               step="0.01" placeholder="25" required>
                    </div>
                    
                    <div class="quarter-input">
                        <label for="q4_target" class="quarter-label">Q4 Target (Oct-Dec)</label>
                        <input type="number" id="q4_target" name="q4_target" class="form-control" 
                               step="0.01" placeholder="25" required>
                    </div>
                </div>
                
                <div class="quarter-total">
                    <strong>Quarterly Total:</strong> <span id="quarterlyTotal">0</span> / <span id="annualTarget">0</span>
                    <span id="targetWarning" style="display: none;">
                        ?? Quarterly targets don't match annual target!
                    </span>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="form-section form-actions-row">
                <button type="submit" name="save_draft" class="btn btn-secondary">
                    <i class="fas fa-save"></i> Save as Draft
                </button>
                <button type="submit" name="submit_kpi" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Submit for Review
                </button>
                <a href="my-kpis" class="btn btn-danger">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Calculate quarterly total
function calculateQuarterlyTotal() {
    const q1 = parseFloat(document.getElementById('q1_target').value) || 0;
    const q2 = parseFloat(document.getElementById('q2_target').value) || 0;
    const q3 = parseFloat(document.getElementById('q3_target').value) || 0;
    const q4 = parseFloat(document.getElementById('q4_target').value) || 0;
    const annual = parseFloat(document.getElementById('target_value').value) || 0;
    
    const total = q1 + q2 + q3 + q4;
    
    document.getElementById('quarterlyTotal').textContent = total.toFixed(2);
    document.getElementById('annualTarget').textContent = annual.toFixed(2);
    
    const warning = document.getElementById('targetWarning');
    if (Math.abs(total - annual) > 0.01 && annual > 0) {
        warning.style.display = 'inline';
    } else {
        warning.style.display = 'none';
    }
}

// Attach event listeners
document.getElementById('target_value').addEventListener('input', calculateQuarterlyTotal);
document.getElementById('q1_target').addEventListener('input', calculateQuarterlyTotal);
document.getElementById('q2_target').addEventListener('input', calculateQuarterlyTotal);
document.getElementById('q3_target').addEventListener('input', calculateQuarterlyTotal);
document.getElementById('q4_target').addEventListener('input', calculateQuarterlyTotal);

// Auto-distribute annual target to quarters
document.getElementById('target_value').addEventListener('change', function() {
    const annual = parseFloat(this.value) || 0;
    const perQuarter = (annual / 4).toFixed(2);
    
    if (confirm('Would you like to evenly distribute the annual target across all quarters?')) {
        document.getElementById('q1_target').value = perQuarter;
        document.getElementById('q2_target').value = perQuarter;
        document.getElementById('q3_target').value = perQuarter;
        document.getElementById('q4_target').value = perQuarter;
        calculateQuarterlyTotal();
    }
});

// Form validation
document.getElementById('kpiForm').addEventListener('submit', function(e) {
    const annual = parseFloat(document.getElementById('target_value').value) || 0;
    const q1 = parseFloat(document.getElementById('q1_target').value) || 0;
    const q2 = parseFloat(document.getElementById('q2_target').value) || 0;
    const q3 = parseFloat(document.getElementById('q3_target').value) || 0;
    const q4 = parseFloat(document.getElementById('q4_target').value) || 0;
    const total = q1 + q2 + q3 + q4;
    
    if (Math.abs(total - annual) > 0.01) {
        if (!confirm('Warning: Quarterly targets (' + total.toFixed(2) + ') do not match annual target (' + annual.toFixed(2) + '). Do you want to continue?')) {
            e.preventDefault();
            return false;
        }
    }
});

// Initialize
calculateQuarterlyTotal();
</script>
<?php if ($editing_kpi): ?>
<script>
// Edit mode: pre-fill the create form and switch it to an update.
(function () {
    const kpi = <?php echo json_encode([
        'kpi_id' => (int) $editing_kpi['kpi_id'],
        'fiscal_year' => (int) $editing_kpi['fiscal_year'],
        'category_id' => (int) $editing_kpi['category_id'],
        'kpi_title' => (string) $editing_kpi['kpi_title'],
        'kpi_description' => (string) ($editing_kpi['kpi_description'] ?? ''),
        'measurement_criteria' => (string) ($editing_kpi['measurement_criteria'] ?? ''),
        'target_value' => (string) ($editing_kpi['target_value'] ?? ''),
        'unit_of_measure' => (string) ($editing_kpi['unit_of_measure'] ?? ''),
        'weight_percentage' => (string) ($editing_kpi['weight_percentage'] ?? ''),
        'q1_target' => (string) ($editing_kpi['q1_target'] ?? ''),
        'q2_target' => (string) ($editing_kpi['q2_target'] ?? ''),
        'q3_target' => (string) ($editing_kpi['q3_target'] ?? ''),
        'q4_target' => (string) ($editing_kpi['q4_target'] ?? ''),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    const form = document.getElementById('kpiForm');
    if (!form) {
        return;
    }

    form.querySelector('input[name="action"]').value = 'update';

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'kpi_id';
    idInput.value = kpi.kpi_id;
    form.appendChild(idInput);

    const year = document.getElementById('fiscal_year');
    if (year && !Array.from(year.options).some(function (o) { return Number(o.value) === kpi.fiscal_year; })) {
        year.add(new Option(String(kpi.fiscal_year), String(kpi.fiscal_year)), 0);
    }

    ['fiscal_year', 'category_id', 'kpi_title', 'kpi_description', 'measurement_criteria', 'target_value',
     'unit_of_measure', 'weight_percentage', 'q1_target', 'q2_target', 'q3_target', 'q4_target'].forEach(function (field) {
        const el = document.getElementById(field);
        if (el) {
            el.value = kpi[field];
        }
    });

    const heading = document.querySelector('.card-header h3');
    if (heading) {
        heading.innerHTML = '<i class="fas fa-edit"></i> Edit KPI';
    }

    calculateQuarterlyTotal();
})();
</script>
<?php endif; ?>
