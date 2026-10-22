<?php
declare(strict_types=1);

$page_title = 'Application Opportunities';
require_once 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';

check_role(IMS_ALL_ROLES);

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function strip_html_preview(?string $html, int $limit = 220): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$html)));
    if ($text === '') return '';
    if (mb_strlen($text) <= $limit) return $text;
    return rtrim(mb_substr($text, 0, $limit)) . '...';
}

function valid_filter_value(string $value, array $allowed): bool
{
    return in_array($value, $allowed, true);
}

$cohorts = [];
$cohortResult = $conn->query("
    SELECT
        id AS cohort_id,
        name AS cohort_name,
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

if ($cohortResult) {
    while ($row = $cohortResult->fetch_assoc()) {
        $row['cohort_id'] = (int)($row['cohort_id'] ?? 0);
        $cohorts[] = $row;
    }
} else {
    error_log('Failed to fetch cohorts: ' . $conn->error);
}

$allowed_statuses = ['Draft', 'Published', 'Closed', 'Completed'];
$allowed_types    = ['Incubation', 'Acceleration', 'Funding', 'Mentorship', 'Training', 'Other'];

$filter_status = trim((string)($_GET['status'] ?? ''));
$filter_type   = trim((string)($_GET['type'] ?? ''));
$filter_cohort = (int)($_GET['cohort_id'] ?? 0);

if ($filter_status !== '' && !valid_filter_value($filter_status, $allowed_statuses)) $filter_status = '';
if ($filter_type !== '' && !valid_filter_value($filter_type, $allowed_types)) $filter_type = '';

$sql = "
    SELECT
        o.*,
        u.full_name AS created_by_name,
        c.name AS cohort_name,
        (SELECT COUNT(*) FROM applications a WHERE a.opportunity_id = o.opportunity_id) AS total_applications,
        (SELECT COUNT(*) FROM applications a2 WHERE a2.opportunity_id = o.opportunity_id AND a2.status = 'Submitted') AS pending_review
    FROM application_opportunities o
    LEFT JOIN users u ON o.created_by = u.user_id
    LEFT JOIN cohorts c ON c.id = o.cohort_id
    WHERE 1=1
";

$types = '';
$params = [];

if ($filter_status !== '') {
    $sql .= " AND o.status = ?";
    $types .= 's';
    $params[] = $filter_status;
}
if ($filter_type !== '') {
    $sql .= " AND o.opportunity_type = ?";
    $types .= 's';
    $params[] = $filter_type;
}
if ($filter_cohort > 0) {
    $sql .= " AND o.cohort_id = ?";
    $types .= 'i';
    $params[] = $filter_cohort;
}

$sql .= " ORDER BY o.is_featured DESC, o.created_at DESC, o.opportunity_id DESC";

$opportunities = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['total_applications'] = (int)($row['total_applications'] ?? 0);
        $row['pending_review'] = (int)($row['pending_review'] ?? 0);
        $row['is_featured'] = (int)($row['is_featured'] ?? 0);
        $opportunities[] = $row;
    }
    $stmt->close();
}

$total_opportunities = count($opportunities);
$published = count(array_filter($opportunities, fn($o) => ($o['status'] ?? '') === 'Published'));
$draft = count(array_filter($opportunities, fn($o) => ($o['status'] ?? '') === 'Draft'));
$total_apps = array_sum(array_map(fn($o) => (int)$o['total_applications'], $opportunities));
?>

<link rel="stylesheet" href="css/opportunities.css">
<style>
.rich-help{font-size:12px;color:#64748b;margin-top:6px;line-height:1.5}
.html-toolbar{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 7px}
.html-toolbar button{border:1px solid #dbe2ea;background:#fff;border-radius:7px;padding:6px 9px;cursor:pointer;font-size:12px}
.content-repeater{display:flex;flex-direction:column;gap:14px}
.content-row,.bullet-row{border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fafafa}
.content-row-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}
.content-row-head strong{font-size:13px;color:#334155}
.btn-icon-danger{border:0;background:#fee2e2;color:#b91c1c;width:34px;height:34px;border-radius:8px;cursor:pointer}
.bullet-list{display:flex;flex-direction:column;gap:8px;margin-top:10px}
.bullet-row{display:flex;gap:8px;padding:8px;background:#fff}
.bullet-row input{flex:1}
.form-note{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px;color:#9a4f0b;font-size:13px}
.opp-panel-head > .filters-bar{flex:0 1 auto;min-width:0;margin:0 0 0 auto}
@media(max-width:760px){.modal-card{width:96vw!important}.form-grid{grid-template-columns:1fr!important}}
</style>

<div class="opp-hero">
    <div class="opp-hero-left">
        <div class="opp-eyebrow"><span class="opp-dot"></span> Programs</div>
        <h1>Application Opportunities</h1>
        <p>Create programme opportunities, link them to cohorts and publish formatted application information.</p>
    </div>
    <div class="opp-hero-actions">
        <button type="button" onclick="openModal('createOpportunityModal')" class="btn btn-white">
            <i class="fas fa-plus"></i> Create Opportunity
        </button>
    </div>
</div>

<div class="stats-grid reports-stats">
    <div class="stat-card"><div class="stat-icon bg-primary"><i class="fas fa-bullhorn"></i></div><div class="stat-info"><span class="stat-label">Total</span><span class="stat-value"><?= $total_opportunities ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div><div class="stat-info"><span class="stat-label">Published</span><span class="stat-value"><?= $published ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-edit"></i></div><div class="stat-info"><span class="stat-label">Drafts</span><span class="stat-value"><?= $draft ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-file-alt"></i></div><div class="stat-info"><span class="stat-label">Applications</span><span class="stat-value"><?= $total_apps ?></span></div></div>
</div>

<div class="opp-panel">
    <div class="opp-panel-head">
        <h3><i class="fas fa-bullhorn" style="color:var(--brand-500);"></i> Opportunities</h3>
        <form method="GET" class="filters-bar" role="search" aria-label="Filter opportunities">
            <select name="cohort_id" class="form-control" style="min-width:170px">
                <option value="">All Cohorts</option>
                <?php foreach ($cohorts as $cohort): ?>
                    <option value="<?= (int)$cohort['cohort_id'] ?>" <?= $filter_cohort === (int)$cohort['cohort_id'] ? 'selected' : '' ?>>
                        <?= h($cohort['cohort_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="type" class="form-control"><option value="">All Types</option><?php foreach($allowed_types as $type): ?><option value="<?= h($type) ?>" <?= $filter_type===$type?'selected':'' ?>><?= h($type) ?></option><?php endforeach; ?></select>
            <select name="status" class="form-control"><option value="">All Statuses</option><?php foreach($allowed_statuses as $status): ?><option value="<?= h($status) ?>" <?= $filter_status===$status?'selected':'' ?>><?= h($status) ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($filter_status !== '' || $filter_type !== '' || $filter_cohort > 0): ?><a href="application-opportunities" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
        </form>
    </div>

    <div class="opp-panel-body">
        <?php if (!$opportunities): ?>
            <div class="opp-empty">
                <div class="opp-empty-icon"><i class="fas fa-bullhorn"></i></div>
                <h4>No Opportunities Found</h4>
                <p>Create your first opportunity or change the filters.</p>
            </div>
        <?php else: ?>
            <div class="opp-list">
            <?php foreach($opportunities as $i => $opp):
                $status = (string)($opp['status'] ?? 'Draft');
                $badgeMap = ['Published'=>'badge-published','Draft'=>'badge-draft','Closed'=>'badge-closed','Completed'=>'badge-completed'];
            ?>
                <div class="opp-card" style="animation-delay:<?= $i*50 ?>ms">
                    <div class="opp-card-head">
                        <div class="opp-card-title-row"><div class="opp-card-title" title="<?= h($opp['opportunity_title']) ?>"><?= h($opp['opportunity_title']) ?><?php if(!empty($opp['is_featured'])): ?> <span class="opp-featured-badge"><i class="fas fa-star"></i> Featured</span><?php endif; ?></div></div>
                        <span class="badge <?= $badgeMap[$status] ?? 'badge-secondary' ?>"><?= h($status) ?></span>
                    </div>
                    <div class="opp-meta">
                        <span class="opp-meta-item"><i class="fas fa-layer-group"></i> <?= h($opp['cohort_name'] ?? 'No cohort') ?></span>
                        <span class="opp-meta-item"><i class="fas fa-tag"></i> <?= h($opp['opportunity_type']) ?></span>
                        <span class="opp-meta-item"><i class="fas fa-calendar"></i> Deadline: <?= !empty($opp['deadline']) ? date('d M Y', strtotime($opp['deadline'])) : 'N/A' ?></span>
                        <span class="opp-meta-item"><i class="fas fa-file-alt"></i> <?= (int)$opp['total_applications'] ?> applications</span>
                    </div>
                    <div class="opp-actions">
                        <a href="view-opportunity?id=<?= (int)$opp['opportunity_id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
                        <a href="manage-applications?opportunity_id=<?= (int)$opp['opportunity_id'] ?>" class="btn btn-info btn-sm"><i class="fas fa-file-alt"></i> Applications</a>
                        <?php if($status==='Draft'): ?><button type="button" onclick="publishOpportunity(<?= (int)$opp['opportunity_id'] ?>)" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Publish</button><?php endif; ?>
                        <?php if($status==='Published'): ?><button type="button" onclick="closeOpportunity(<?= (int)$opp['opportunity_id'] ?>)" class="btn btn-warning btn-sm"><i class="fas fa-lock"></i> Close</button><?php endif; ?>
                        <?php if($status==='Closed'): ?><a href="includes/opportunity-process.php?action=reopen&id=<?= (int)$opp['opportunity_id'] ?>&csrf_token=<?= h(csrf_token()) ?>" class="btn btn-success btn-sm" onclick="return confirm('Reopen this opportunity?')"><i class="fas fa-unlock"></i> Reopen</a><?php endif; ?>
                        <?php if($status!=='Completed'): ?><a href="includes/opportunity-process.php?action=complete&id=<?= (int)$opp['opportunity_id'] ?>&csrf_token=<?= h(csrf_token()) ?>" class="btn btn-dark btn-sm" onclick="return confirm('Mark as completed?')"><i class="fas fa-flag-checkered"></i> Complete</a><?php endif; ?>
                        <a href="edit-opportunity?id=<?= (int)$opp['opportunity_id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                        <a href="includes/opportunity-process.php?action=toggle_featured&id=<?= (int)$opp['opportunity_id'] ?>&csrf_token=<?= h(csrf_token()) ?>" class="btn btn-light btn-sm"><i class="fas fa-star"></i> <?= !empty($opp['is_featured'])?'Unfeature':'Feature' ?></a>
                        <?php if($status==='Draft'): ?><a href="includes/opportunity-process.php?action=delete&id=<?= (int)$opp['opportunity_id'] ?>&csrf_token=<?= h(csrf_token()) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this draft opportunity?')"><i class="fas fa-trash"></i> Delete</a><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="createOpportunityModal" class="modal">
    <div class="modal-card" style="max-width:1000px">
        <div class="modal-head">
            <h3><i class="fas fa-plus-circle" style="color:var(--brand-500)"></i> Create Application Opportunity</h3>
            <button type="button" class="modal-close" onclick="closeModal('createOpportunityModal')">&times;</button>
        </div>

        <form method="POST" action="includes/opportunity-process.php" id="opportunityForm" data-form-tabs="label-sections">
            <input type="hidden" name="action" value="create">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-section-label">Basic Information</div>

                    <div class="form-group full">
                        <label class="form-label required">Opportunity Title</label>
                        <input type="text" name="opportunity_title" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Cohort</label>
                        <?php if (!$cohorts): ?>
                            <div class="form-note" style="margin-bottom:8px">
                                <i class="fas fa-triangle-exclamation"></i>
                                No cohorts were found in the <strong>cohorts</strong> table.
                            </div>
                        <?php endif; ?>
                        <select name="cohort_id" class="form-control" required <?= !$cohorts ? 'disabled' : '' ?>>
                            <option value="">Select cohort...</option>
                            <?php foreach($cohorts as $cohort): ?>
                                <option value="<?= (int)$cohort['cohort_id'] ?>">
                                    <?= h($cohort['cohort_name']) ?>
                                    <?php if (!empty($cohort['start_date'])): ?>
                                        — <?= date('M Y', strtotime((string)$cohort['start_date'])) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Type</label>
                        <select name="opportunity_type" class="form-control" required>
                            <option value="">Select type...</option>
                            <?php foreach($allowed_types as $type): ?><option value="<?= h($type) ?>"><?= h($type) ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label required">Short Description</label>
                        <div class="html-toolbar">
                            <button type="button" onclick="wrapHtml('description','<strong>','</strong>')"><b>B</b></button>
                            <button type="button" onclick="wrapHtml('description','<p>','</p>')">P</button>
                            <button type="button" onclick="wrapHtml('description','<ul><li>','</li></ul>')">• List</button>
                        </div>
                        <textarea id="description" name="description" class="form-control" required placeholder="<p>Short introduction...</p>"></textarea>
                        <div class="rich-help">Allowed HTML: &lt;p&gt;, &lt;br&gt;, &lt;strong&gt;, &lt;b&gt;, &lt;em&gt;, &lt;i&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;a&gt;, &lt;h2&gt;, &lt;h3&gt;, &lt;h4&gt;.</div>
                    </div>

                    <div class="form-section-label">Application Details Sections</div>
                    <div class="form-group full">
                        <div class="form-note">Add as many headings as required. Each section can contain formatted HTML descriptions and bullet points.</div>
                        <div id="contentSections" class="content-repeater" style="margin-top:12px"></div>
                        <button type="button" class="btn btn-secondary btn-sm" style="margin-top:10px" onclick="addContentSection()"><i class="fas fa-plus"></i> Add Heading &amp; Description</button>
                    </div>

                    <div class="form-section-label">Eligibility Criteria</div>

                    <div class="form-group full">
                        <label class="form-label">Eligibility Heading</label>
                        <input type="text" name="eligibility_heading" class="form-control" value="Eligibility Criteria">
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Eligibility Description</label>
                        <div class="html-toolbar">
                            <button type="button" onclick="wrapHtml('eligibility_description_html','<strong>','</strong>')"><b>B</b></button>
                            <button type="button" onclick="wrapHtml('eligibility_description_html','<p>','</p>')">P</button>
                            <button type="button" onclick="wrapHtml('eligibility_description_html','<h3>','</h3>')">H3</button>
                        </div>
                        <textarea id="eligibility_description_html" name="eligibility_description_html" class="form-control" placeholder="<p>Applicants should meet the following conditions...</p>"></textarea>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Eligibility Bullets</label>
                        <div id="eligibilityBullets" class="bullet-list"></div>
                        <button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px" onclick="addEligibilityBullet()"><i class="fas fa-plus"></i> Add Bullet</button>
                        <div class="rich-help">You may use &lt;strong&gt; inside bullets, e.g. &lt;strong&gt;Registered venture:&lt;/strong&gt; Must be legally registered.</div>
                    </div>

                    <div class="form-section-label">Required Documents</div>
                    <div class="form-group full">
                        <label class="form-label">Heading</label>
                        <input type="text" name="documents_heading" class="form-control" value="Required Documents">
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Description</label>
                        <textarea name="required_documents" class="form-control" placeholder="<p>Prepare the following documents...</p>"></textarea>
                    </div>

                    <div class="form-section-label">Dates &amp; Capacity</div>

                    <div class="form-group"><label class="form-label required">Start Date</label><input type="date" name="start_date" class="form-control" required></div>
                    <div class="form-group"><label class="form-label required">Application Deadline</label><input type="date" name="deadline" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Winner Announcement Date</label><input type="date" name="announcement_date" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Maximum Applicants</label><input type="number" name="max_applicants" min="1" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Available Slots</label><input type="number" name="available_slots" min="1" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Minimum Team Size</label><input type="number" name="min_team_size" value="1" min="1" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Maximum Team Size</label><input type="number" name="max_team_size" min="1" class="form-control"></div>

                    <div class="form-group full"><label class="form-label">Allowed Sectors</label><input type="text" name="sectors_allowed" class="form-control" placeholder="EdTech, FinTech, HealthTech"></div>
                    <div class="form-group full"><label class="form-check"><input type="checkbox" name="is_featured" value="1"> Mark as Featured Opportunity</label></div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" onclick="closeModal('createOpportunityModal')" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" name="save_draft" class="btn btn-warning"><i class="fas fa-save"></i> Save as Draft</button>
                <button type="submit" name="publish" class="btn btn-success"><i class="fas fa-check"></i> Publish Now</button>
            </div>
        </form>
    </div>
</div>

<template id="contentSectionTemplate">
    <div class="content-row">
        <div class="content-row-head"><strong>Application Detail Section</strong><button type="button" class="btn-icon-danger" onclick="this.closest('.content-row').remove()"><i class="fas fa-trash"></i></button></div>
        <div class="form-group"><label class="form-label">Heading</label><input type="text" data-field="heading" class="form-control" placeholder="e.g. About the Fellowship"></div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea data-field="description_html" class="form-control" placeholder="<p>Description with <strong>important text</strong>.</p>"></textarea>
        </div>
        <div><label class="form-label">Bullets</label><div class="bullet-list section-bullets"></div><button type="button" class="btn btn-secondary btn-sm" onclick="addSectionBullet(this)"><i class="fas fa-plus"></i> Add Bullet</button></div>
    </div>
</template>

<script>
let contentIndex = 0;
let eligibilityBulletIndex = 0;

function wrapHtml(id, before, after) {
    const field = document.getElementById(id);
    if (!field) return;
    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? field.value.length;
    const selected = field.value.substring(start, end);
    field.setRangeText(before + selected + after, start, end, 'end');
    field.focus();
}

function renumberSections() {
    document.querySelectorAll('#contentSections .content-row').forEach((row, index) => {
        row.querySelectorAll('[data-field]').forEach(el => {
            el.name = `content_sections[${index}][${el.dataset.field}]`;
        });
        row.querySelectorAll('.section-bullets input').forEach((el, bIndex) => {
            el.name = `content_sections[${index}][bullets][${bIndex}]`;
        });
    });
}

function addContentSection() {
    const node = document.getElementById('contentSectionTemplate').content.cloneNode(true);
    document.getElementById('contentSections').appendChild(node);
    renumberSections();
}

function addSectionBullet(btn) {
    const list = btn.parentElement.querySelector('.section-bullets');
    const row = document.createElement('div');
    row.className = 'bullet-row';
    row.innerHTML = '<input type="text" class="form-control" placeholder="<strong>Label:</strong> bullet text"><button type="button" class="btn-icon-danger" onclick="this.parentElement.remove();renumberSections()"><i class="fas fa-trash"></i></button>';
    list.appendChild(row);
    renumberSections();
}

function addEligibilityBullet() {
    const row = document.createElement('div');
    row.className = 'bullet-row';
    row.innerHTML = `<input type="text" name="eligibility_bullets[${eligibilityBulletIndex++}]" class="form-control" placeholder="<strong>Requirement:</strong> Details"><button type="button" class="btn-icon-danger" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>`;
    document.getElementById('eligibilityBullets').appendChild(row);
}

function publishOpportunity(id){if(confirm('Publish this opportunity?')) location.href='includes/opportunity-process.php?action=publish&id='+id + '&csrf_token=<?= h(csrf_token()) ?>'}
function closeOpportunity(id){if(confirm('Close this opportunity?')) location.href='includes/opportunity-process.php?action=close&id='+id + '&csrf_token=<?= h(csrf_token()) ?>'}

addContentSection();
addEligibilityBullet();
</script>

<?php include 'includes/footer.php'; ?>
