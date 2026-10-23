<?php
declare(strict_types=1);

$page_title = 'Edit Opportunity';
require_once 'includes/header.php';

check_role(['Administrator', 'MEAL Lead', 'Programs Lead', 'Program Manager', 'Program Director']);

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function safe_date_value(mixed $value): string
{
    $value = trim((string)($value ?? ''));
    return ($value === '' || str_starts_with($value, '0000')) ? '' : $value;
}

function safe_datetime_display(mixed $value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '' || str_starts_with($value, '0000')) return '-';

    $ts = strtotime($value);
    return $ts ? date('d M Y, g:i A', $ts) : '-';
}

function decode_json_array(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

$opportunity_id = (int)($_GET['id'] ?? 0);

if ($opportunity_id < 1) {
    $_SESSION['error'] = 'Invalid opportunity ID.';
    header('Location: application-opportunities');
    exit;
}

$stmt = $conn->prepare('SELECT * FROM application_opportunities WHERE opportunity_id = ? LIMIT 1');
if (!$stmt) {
    $_SESSION['error'] = 'Unable to load the opportunity.';
    header('Location: application-opportunities');
    exit;
}

$stmt->bind_param('i', $opportunity_id);
$stmt->execute();
$opportunity = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$opportunity) {
    $_SESSION['error'] = 'Opportunity not found.';
    header('Location: application-opportunities');
    exit;
}


$cohorts = [];
$result = $conn->query("
    SELECT
        id,
        name,
        slug,
        start_date,
        end_date,
        application_status,
        status,
        sort_order
    FROM cohorts
    ORDER BY
        CASE WHEN status = 'Active' THEN 0 ELSE 1 END,
        sort_order ASC,
        start_date DESC,
        id DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cohorts[] = $row;
    }
}

$app_count = 0;
$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM applications WHERE opportunity_id = ?');
if ($stmt) {
    $stmt->bind_param('i', $opportunity_id);
    $stmt->execute();
    $app_count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

$content_sections = decode_json_array($opportunity['content_sections'] ?? null);
$eligibility_bullets = decode_json_array($opportunity['eligibility_bullets'] ?? null);

$status = (string)($opportunity['status'] ?? 'Draft');
$has_applications = $app_count > 0;

$badge_map = [
    'Published' => 'badge-available',
    'Draft' => 'badge-pending',
    'Closed' => 'badge-returned',
    'Completed' => 'badge-purple',
];
$badge_class = $badge_map[$status] ?? 'badge-returned';

$allowed_types = ['Incubation', 'Acceleration', 'Funding', 'Mentorship', 'Training', 'Other'];
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
/* Edit opportunity: section heads inside the tabs, unsaved-change dots,
   repeatable section/bullet rows and the sticky save bar. */
.eo-dirty-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--brand-500); display: none; }
.eo-tab-btn.dirty .eo-dirty-dot { display: block; }
.eo-section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.eo-section-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: var(--ink-700); }
.eo-section-head h3 i { color: var(--brand-500); }
.eo-check { margin-top: 16px; }
.eo-save-bar { position: sticky; bottom: 0; z-index: 100; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 20px; background: var(--surface-card); border-top: 1px solid var(--ink-100); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); flex-wrap: wrap; }
.eo-save-status { font-size: 12px; font-weight: 600; color: var(--ink-300); }
.eo-save-status.unsaved { color: var(--amber-fg); }
.eo-save-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.eo-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
.eo-meta-item { display: flex; flex-direction: column; gap: 4px; }
.eo-meta-label { font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-300); }
.eo-meta-value { font-size: 13.5px; font-weight: 600; color: var(--ink-700); }
.rich-toolbar { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 7px; }
.repeat-list { display: flex; flex-direction: column; gap: 14px; }
.repeat-card { border: 1px solid var(--ink-100); border-radius: var(--radius-md); padding: 14px; background: var(--surface-hover); }
.repeat-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.repeat-card-head strong { font-size: 13px; color: var(--ink-700); }
.repeat-bullets { display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px; }
.repeat-bullet { display: flex; gap: 8px; align-items: center; }
.repeat-bullet .form-control { flex: 1; }
.eo-info-links { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-top: 20px; }
.eo-info-link { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: var(--ink-50); border: 1px solid var(--ink-100); border-radius: var(--radius-md); font-size: 13px; font-weight: 700; }
@media (max-width: 700px) { .eo-save-bar { padding: 12px; } }
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-edit" aria-hidden="true"></i> Edit Opportunity</h1>
        <p>
            <?= h($opportunity['opportunity_title'] ?? '') ?>
            &middot; <?= h($opportunity['opportunity_type'] ?? 'Opportunity') ?>
            &middot; <span class="badge <?= h($badge_class) ?>"><?= h($status) ?></span>
        </p>
    </div>

    <div class="hero-actions">
        <a href="application-opportunities" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
        <a href="view-opportunity?id=<?= $opportunity_id ?>" class="btn"><i class="fas fa-eye" aria-hidden="true"></i> View</a>
    </div>
</div>

<?php if ($has_applications && $status !== 'Draft'): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
    <div>
        <strong>This opportunity has <?= $app_count ?> existing application<?= $app_count === 1 ? '' : 's' ?>.</strong>
        Applicant-facing changes may affect existing applicants.
    </div>
</div>
<?php endif; ?>

<form method="POST" action="includes/opportunity-process.php" id="opportunityEditForm">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="opportunity_id" value="<?= $opportunity_id ?>">

  <div class="tab-shell">
    <div class="tab-header">
        <button type="button" class="tab-btn eo-tab-btn active" data-tab="basic"><i class="fas fa-info-circle" aria-hidden="true"></i> Basic <span class="eo-dirty-dot"></span></button>
        <button type="button" class="tab-btn eo-tab-btn" data-tab="details"><i class="fas fa-layer-group" aria-hidden="true"></i> Application Details <span class="eo-dirty-dot"></span></button>
        <button type="button" class="tab-btn eo-tab-btn" data-tab="eligibility"><i class="fas fa-list-check" aria-hidden="true"></i> Eligibility <span class="eo-dirty-dot"></span></button>
        <button type="button" class="tab-btn eo-tab-btn" data-tab="documents"><i class="fas fa-file-alt" aria-hidden="true"></i> Documents <span class="eo-dirty-dot"></span></button>
        <button type="button" class="tab-btn eo-tab-btn" data-tab="timeline"><i class="fas fa-calendar" aria-hidden="true"></i> Timeline <span class="eo-dirty-dot"></span></button>
        <button type="button" class="tab-btn eo-tab-btn" data-tab="capacity"><i class="fas fa-users" aria-hidden="true"></i> Capacity <span class="eo-dirty-dot"></span></button>
        <button type="button" class="tab-btn eo-tab-btn" data-tab="settings"><i class="fas fa-cog" aria-hidden="true"></i> Settings <span class="eo-dirty-dot"></span></button>
        <button type="button" class="tab-btn eo-tab-btn" data-tab="info"><i class="fas fa-chart-bar" aria-hidden="true"></i> Record Info</button>
    </div>

    <div class="tab-panels">
    <section class="tab-panel eo-tab-panel active" id="tab-basic">
        <div class="eo-section-head"><h3><i class="fas fa-info-circle" aria-hidden="true"></i> Basic Information</h3></div>
        <div class="form-grid">
            <div class="form-group full">
                <label class="form-label required">Opportunity Title</label>
                <input type="text" name="opportunity_title" class="form-control" value="<?= h($opportunity['opportunity_title']) ?>" required data-tab="basic">
            </div>

            <div class="form-group">
                <label class="form-label required">Cohort</label>
                <select name="cohort_id" class="form-control" required data-tab="basic">
                    <option value="">Select cohort...</option>
                    <?php foreach ($cohorts as $cohort): ?>
                        <option value="<?= (int)$cohort['id'] ?>" <?= (int)($opportunity['cohort_id'] ?? 0) === (int)$cohort['id'] ? 'selected' : '' ?>>
                            <?= h($cohort['name']) ?>
                            <?= !empty($cohort['start_date']) ? ' — ' . h(date('M Y', strtotime((string)$cohort['start_date']))) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label required">Type</label>
                <select name="opportunity_type" class="form-control" required data-tab="basic">
                    <?php foreach ($allowed_types as $type): ?>
                        <option value="<?= h($type) ?>" <?= ($opportunity['opportunity_type'] ?? '') === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group full">
                <label class="form-label required">Short Description</label>
                <div class="rich-toolbar">
                    <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('description','<strong>','</strong>')"><strong>B</strong></button>
                    <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('description','<p>','</p>')">P</button>
                    <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('description','<h3>','</h3>')">H3</button>
                    <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('description','<ul><li>','</li></ul>')">• List</button>
                </div>
                <textarea id="description" name="description" class="form-control" rows="7" required data-tab="basic"><?= h($opportunity['description']) ?></textarea>
                <div class="form-hint">HTML supported: p, br, strong, b, em, i, u, ul, ol, li, a, h2, h3, h4, blockquote.</div>
            </div>
        </div>
    </section>

    <section class="tab-panel eo-tab-panel" id="tab-details">
        <div class="eo-section-head">
            <h3><i class="fas fa-layer-group" aria-hidden="true"></i> Application Detail Sections</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addContentSection()"><i class="fas fa-plus" aria-hidden="true"></i> Add Section</button>
        </div>
        <div id="contentSections" class="repeat-list">
            <?php foreach ($content_sections as $index => $section): ?>
                <div class="repeat-card content-section">
                    <div class="repeat-card-head">
                        <strong>Section <?= $index + 1 ?></strong>
                        <button type="button" class="btn btn-danger btn-sm" aria-label="Remove section" onclick="removeContentSection(this)"><i class="fas fa-trash" aria-hidden="true"></i></button>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Heading</label>
                        <input type="text" class="form-control cs-heading" value="<?= h($section['heading'] ?? '') ?>" data-tab="details">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control cs-description" rows="6" data-tab="details"><?= h($section['description_html'] ?? '') ?></textarea>
                        <div class="form-hint">You may use HTML tags such as &lt;strong&gt;, &lt;p&gt;, &lt;h3&gt;, lists and links.</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Bullets</label>
                        <div class="repeat-bullets">
                            <?php foreach (($section['bullets'] ?? []) as $bullet): ?>
                                <div class="repeat-bullet">
                                    <input type="text" class="form-control cs-bullet" value="<?= h($bullet) ?>" data-tab="details">
                                    <button type="button" class="btn btn-danger btn-sm" aria-label="Remove bullet" onclick="removeBullet(this)"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addSectionBullet(this)"><i class="fas fa-plus" aria-hidden="true"></i> Add Bullet</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="tab-panel eo-tab-panel" id="tab-eligibility">
        <div class="eo-section-head"><h3><i class="fas fa-list-check" aria-hidden="true"></i> Eligibility Criteria</h3></div>
        <div class="form-group">
            <label class="form-label">Heading</label>
            <input type="text" name="eligibility_heading" class="form-control" value="<?= h($opportunity['eligibility_heading'] ?? 'Eligibility Criteria') ?>" data-tab="eligibility">
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <div class="rich-toolbar">
                <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('eligibility_description_html','<strong>','</strong>')"><strong>B</strong></button>
                <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('eligibility_description_html','<p>','</p>')">P</button>
                <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('eligibility_description_html','<h3>','</h3>')">H3</button>
            </div>
            <textarea id="eligibility_description_html" name="eligibility_description_html" class="form-control" rows="7" data-tab="eligibility"><?= h($opportunity['eligibility_description_html'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Eligibility Bullets</label>
            <div id="eligibilityBullets" class="repeat-bullets">
                <?php foreach ($eligibility_bullets as $bullet): ?>
                    <div class="repeat-bullet">
                        <input type="text" class="form-control eligibility-bullet" value="<?= h($bullet) ?>" data-tab="eligibility">
                        <button type="button" class="btn btn-danger btn-sm" aria-label="Remove bullet" onclick="removeBullet(this)"><i class="fas fa-trash" aria-hidden="true"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-secondary btn-sm" onclick="addEligibilityBullet()"><i class="fas fa-plus" aria-hidden="true"></i> Add Bullet</button>
            <div class="form-hint">Bullets may include &lt;strong&gt; text.</div>
        </div>

        <?php if ($has_applications && $status === 'Published'): ?>
            <div class="field-error"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Changing eligibility may affect existing applicants.</div>
        <?php endif; ?>
    </section>

    <section class="tab-panel eo-tab-panel" id="tab-documents">
        <div class="eo-section-head"><h3><i class="fas fa-file-alt" aria-hidden="true"></i> Required Documents</h3></div>
        <div class="form-group">
            <label class="form-label">Heading</label>
            <input type="text" name="documents_heading" class="form-control" value="<?= h($opportunity['documents_heading'] ?? 'Required Documents') ?>" data-tab="documents">
        </div>

        <div class="form-group">
            <label class="form-label">Description / Requirements</label>
            <div class="rich-toolbar">
                <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('required_documents','<strong>','</strong>')"><strong>B</strong></button>
                <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('required_documents','<p>','</p>')">P</button>
                <button type="button" class="btn btn-soft btn-sm" onclick="wrapHtml('required_documents','<ul><li>','</li></ul>')">• List</button>
            </div>
            <textarea id="required_documents" name="required_documents" class="form-control" rows="7" data-tab="documents"><?= h($opportunity['required_documents'] ?? '') ?></textarea>
        </div>
    </section>

    <section class="tab-panel eo-tab-panel" id="tab-timeline">
        <div class="eo-section-head"><h3><i class="fas fa-calendar" aria-hidden="true"></i> Timeline</h3></div>
        <div class="form-grid cols-3">
            <div class="form-group">
                <label class="form-label required">Start Date</label>
                <input type="date" id="start_date" name="start_date" class="form-control" value="<?= h(safe_date_value($opportunity['start_date'])) ?>" required data-tab="timeline">
            </div>
            <div class="form-group">
                <label class="form-label required">Application Deadline</label>
                <input type="date" id="deadline" name="deadline" class="form-control" value="<?= h(safe_date_value($opportunity['deadline'])) ?>" required data-tab="timeline">
            </div>
            <div class="form-group">
                <label class="form-label">Winner Announcement</label>
                <input type="date" id="announcement_date" name="announcement_date" class="form-control" value="<?= h(safe_date_value($opportunity['announcement_date'])) ?>" data-tab="timeline">
            </div>
        </div>
    </section>

    <section class="tab-panel eo-tab-panel" id="tab-capacity">
        <div class="eo-section-head"><h3><i class="fas fa-users" aria-hidden="true"></i> Capacity &amp; Team Requirements</h3></div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Maximum Applicants</label>
                <input type="number" name="max_applicants" class="form-control" min="1" value="<?= h($opportunity['max_applicants']) ?>" data-tab="capacity">
            </div>
            <div class="form-group">
                <label class="form-label">Available Slots</label>
                <input type="number" name="available_slots" class="form-control" min="1" value="<?= h($opportunity['available_slots']) ?>" data-tab="capacity">
            </div>
            <div class="form-group">
                <label class="form-label">Minimum Team Size</label>
                <input type="number" id="min_team_size" name="min_team_size" class="form-control" min="1" value="<?= h($opportunity['min_team_size'] ?: 1) ?>" data-tab="capacity">
            </div>
            <div class="form-group">
                <label class="form-label">Maximum Team Size</label>
                <input type="number" id="max_team_size" name="max_team_size" class="form-control" min="1" value="<?= h($opportunity['max_team_size']) ?>" data-tab="capacity">
            </div>
        </div>
    </section>

    <section class="tab-panel eo-tab-panel" id="tab-settings">
        <div class="eo-section-head"><h3><i class="fas fa-cog" aria-hidden="true"></i> Settings</h3></div>
        <div class="form-group">
            <label class="form-label">Allowed Sectors</label>
            <input type="text" name="sectors_allowed" class="form-control" value="<?= h($opportunity['sectors_allowed']) ?>" placeholder="EdTech, FinTech, HealthTech" data-tab="settings">
            <div class="form-hint">Comma-separated. Leave empty if the opportunity is not restricted by sector.</div>
        </div>

        <label class="form-check eo-check">
            <input type="checkbox" name="is_featured" value="1" <?= !empty($opportunity['is_featured']) ? 'checked' : '' ?> data-tab="settings">
            Featured Opportunity
        </label>
    </section>

    <section class="tab-panel eo-tab-panel" id="tab-info">
        <div class="eo-section-head"><h3><i class="fas fa-chart-bar" aria-hidden="true"></i> Record Information</h3></div>
        <div class="eo-meta-grid">
            <div class="eo-meta-item"><span class="eo-meta-label">Cohort</span><span class="eo-meta-value"><?php
                $cohortName = '-';
                foreach ($cohorts as $cohort) {
                    if ((int)$cohort['id'] === (int)($opportunity['cohort_id'] ?? 0)) {
                        $cohortName = $cohort['name'];
                        break;
                    }
                }
                echo h($cohortName);
            ?></span></div>
            <div class="eo-meta-item"><span class="eo-meta-label">Status</span><span class="eo-meta-value"><span class="badge <?= h($badge_class) ?>"><?= h($status) ?></span></span></div>
            <div class="eo-meta-item"><span class="eo-meta-label">Applications</span><span class="eo-meta-value"><?= $app_count ?></span></div>
            <div class="eo-meta-item"><span class="eo-meta-label">Created</span><span class="eo-meta-value"><?= h(safe_datetime_display($opportunity['created_at'] ?? null)) ?></span></div>
            <div class="eo-meta-item"><span class="eo-meta-label">Updated</span><span class="eo-meta-value"><?= h(safe_datetime_display($opportunity['updated_at'] ?? null)) ?></span></div>
            <div class="eo-meta-item"><span class="eo-meta-label">Published</span><span class="eo-meta-value"><?= h(safe_datetime_display($opportunity['published_at'] ?? null)) ?></span></div>
        </div>

        <div class="eo-info-links">
            <a class="eo-info-link" href="view-opportunity?id=<?= $opportunity_id ?>"><i class="fas fa-eye" aria-hidden="true"></i> View Opportunity</a>
            <a class="eo-info-link" href="manage-applications?opportunity_id=<?= $opportunity_id ?>"><i class="fas fa-file-alt" aria-hidden="true"></i> Manage Applications</a>
            <a class="eo-info-link" href="application-opportunities"><i class="fas fa-list" aria-hidden="true"></i> All Opportunities</a>
        </div>
    </section>
    </div>
  </div>

    <div class="eo-save-bar">
        <div class="eo-save-status" id="saveStatus">All changes saved</div>
        <div class="eo-save-actions">
            <a href="view-opportunity?id=<?= $opportunity_id ?>" class="btn btn-secondary btn-sm"><i class="fas fa-times" aria-hidden="true"></i> Cancel</a>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save" aria-hidden="true"></i> Save Changes</button>
        </div>
    </div>
</form>

</div><!-- /.page-stack -->

<template id="contentSectionTemplate">
    <div class="repeat-card content-section">
        <div class="repeat-card-head">
            <strong>Application Detail Section</strong>
            <button type="button" class="btn btn-danger btn-sm" aria-label="Remove section" onclick="removeContentSection(this)"><i class="fas fa-trash" aria-hidden="true"></i></button>
        </div>
        <div class="form-group">
            <label class="form-label">Heading</label>
            <input type="text" class="form-control cs-heading" data-tab="details">
        </div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea class="form-control cs-description" rows="6" data-tab="details"></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Bullets</label>
            <div class="repeat-bullets"></div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addSectionBullet(this)"><i class="fas fa-plus" aria-hidden="true"></i> Add Bullet</button>
        </div>
    </div>
</template>


<?php include 'includes/footer.php'; ?>

<script>
(function(){
    const form = document.getElementById('opportunityEditForm');
    const saveStatus = document.getElementById('saveStatus');

    function switchTab(name){
        document.querySelectorAll('.eo-tab-btn').forEach(btn=>btn.classList.toggle('active',btn.dataset.tab===name));
        document.querySelectorAll('.eo-tab-panel').forEach(panel=>panel.classList.toggle('active',panel.id==='tab-'+name));
        history.replaceState(null,'','#'+name);
    }

    document.querySelectorAll('.eo-tab-btn').forEach(btn=>btn.addEventListener('click',()=>switchTab(btn.dataset.tab)));

    const initial = location.hash.replace('#','');
    if (['basic','details','eligibility','documents','timeline','capacity','settings','info'].includes(initial)) switchTab(initial);

    function markDirty(tab){
        if(!tab) return;
        const btn=document.querySelector('.eo-tab-btn[data-tab="'+tab+'"]');
        if(btn) btn.classList.add('dirty');
        saveStatus.textContent='Unsaved changes';
        saveStatus.className='eo-save-status unsaved';
    }

    document.addEventListener('input',e=>{
        const target=e.target;
        if(target && target.dataset && target.dataset.tab) markDirty(target.dataset.tab);
    });
    document.addEventListener('change',e=>{
        const target=e.target;
        if(target && target.dataset && target.dataset.tab) markDirty(target.dataset.tab);
    });

    window.wrapHtml=function(id,before,after){
        const el=document.getElementById(id);
        if(!el) return;
        const start=el.selectionStart ?? el.value.length;
        const end=el.selectionEnd ?? el.value.length;
        const selected=el.value.substring(start,end);
        el.setRangeText(before+selected+after,start,end,'end');
        el.dispatchEvent(new Event('input',{bubbles:true}));
        el.focus();
    };

    window.removeBullet=function(btn){
        btn.closest('.repeat-bullet')?.remove();
        renumberAll();
        markDirty(btn.closest('.eo-tab-panel')?.id.replace('tab-',''));
    };

    window.addEligibilityBullet=function(){
        const row=document.createElement('div');
        row.className='repeat-bullet';
        row.innerHTML='<input type="text" class="form-control eligibility-bullet" data-tab="eligibility" placeholder="<strong>Requirement:</strong> details"><button type="button" class="btn btn-danger btn-sm" aria-label="Remove bullet" onclick="removeBullet(this)"><i class="fas fa-trash" aria-hidden="true"></i></button>';
        document.getElementById('eligibilityBullets').appendChild(row);
        renumberAll();
        markDirty('eligibility');
    };

    window.addSectionBullet=function(btn){
        const row=document.createElement('div');
        row.className='repeat-bullet';
        row.innerHTML='<input type="text" class="form-control cs-bullet" data-tab="details" placeholder="<strong>Label:</strong> bullet text"><button type="button" class="btn btn-danger btn-sm" aria-label="Remove bullet" onclick="removeBullet(this)"><i class="fas fa-trash" aria-hidden="true"></i></button>';
        btn.parentElement.querySelector('.repeat-bullets').appendChild(row);
        renumberAll();
        markDirty('details');
    };

    window.addContentSection=function(){
        const node=document.getElementById('contentSectionTemplate').content.cloneNode(true);
        document.getElementById('contentSections').appendChild(node);
        renumberAll();
        markDirty('details');
    };

    window.removeContentSection=function(btn){
        btn.closest('.content-section')?.remove();
        renumberAll();
        markDirty('details');
    };

    function renumberAll(){
        document.querySelectorAll('#contentSections .content-section').forEach((section,index)=>{
            const title=section.querySelector('.repeat-card-head strong');
            if(title) title.textContent='Section '+(index+1);

            const heading=section.querySelector('.cs-heading');
            const desc=section.querySelector('.cs-description');
            if(heading) heading.name=`content_sections[${index}][heading]`;
            if(desc) desc.name=`content_sections[${index}][description_html]`;

            section.querySelectorAll('.cs-bullet').forEach((input,bIndex)=>{
                input.name=`content_sections[${index}][bullets][${bIndex}]`;
            });
        });

        document.querySelectorAll('#eligibilityBullets .eligibility-bullet').forEach((input,index)=>{
            input.name=`eligibility_bullets[${index}]`;
        });
    }

    renumberAll();

    form.addEventListener('submit',function(e){
        renumberAll();

        const start=document.getElementById('start_date').value;
        const deadline=document.getElementById('deadline').value;
        const announcement=document.getElementById('announcement_date').value;
        const min=parseInt(document.getElementById('min_team_size').value || '1',10);
        const maxRaw=document.getElementById('max_team_size').value;
        const max=maxRaw === '' ? null : parseInt(maxRaw,10);

        if(start && deadline && deadline < start){
            e.preventDefault();
            switchTab('timeline');
            alert('Application deadline cannot be before the start date.');
            return;
        }

        if(deadline && announcement && announcement < deadline){
            e.preventDefault();
            switchTab('timeline');
            alert('Winner announcement date cannot be before the application deadline.');
            return;
        }

        if(min < 1 || (max !== null && max < min)){
            e.preventDefault();
            switchTab('capacity');
            alert('Please check the minimum and maximum team sizes.');
            return;
        }

        <?php if ($has_applications && $status === 'Published'): ?>
        if(!confirm('This published opportunity already has applications. Save the applicant-facing changes?')){
            e.preventDefault();
            return;
        }
        <?php endif; ?>

        saveStatus.textContent='Saving...';
        saveStatus.className='eo-save-status';
    });
})();
</script>
