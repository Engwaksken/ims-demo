<?php
ob_start();


require_once 'includes/config.php';
require_once 'includes/jobs.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

// Same roles as the includes/admin-jobs.php API.
check_role(['Administrator', 'HR', 'Operations/Admin', 'Executive Director', 'Programs Lead']);

$jobs       = jobs_get_all_admin();
$stats      = jobs_get_stats();
$page_title = 'Manage Jobs';

include 'includes/header.php';
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
/* jobs.php: page-only layout. Modal overlay, switches and toast come from
   css/opportunities.css (.jm-overlay / .jm-toggle / .jm-ltoggle / .jm-toast). */
.jb-title { font-weight: 700; color: var(--ink-700); display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.jb-sub { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; font-size: var(--fs-xs); }
.jb-sub i { margin-right: 3px; }
.jb-deadline { font-weight: 600; color: var(--ink-700); white-space: nowrap; }
.jb-deadline-sub { font-size: var(--fs-xs); }
.jb-deadline-sub.soon { color: var(--red-fg); font-weight: 700; }
.jb-status-sel { min-width: 120px; padding-top: 6px; padding-bottom: 6px; }
.jb-acts { display: flex; gap: 6px; flex-wrap: nowrap; }
.jb-req { color: var(--red-fg); }
.jm-form-grid > .form-group { margin-bottom: 0; }
.jb-tall { min-height: 110px; }
</style>

<div class="page-stack">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-briefcase" aria-hidden="true"></i> Manage Jobs</h1>
            <p>Create, edit and manage job postings, toggle featured / urgent, or open the public listings.</p>
        </div>
        <div class="hero-actions">
            <a href="jobs-listings" target="_blank" class="btn">
                <i class="fas fa-external-link-alt" aria-hidden="true"></i> View Listings
            </a>
            <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus" aria-hidden="true"></i> Post New Job
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-briefcase" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Jobs</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Published</div>
                <div class="stat-value"><?= $stats['published'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-edit" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Drafts</div>
                <div class="stat-value"><?= $stats['draft'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Applications</div>
                <div class="stat-value"><?= $stats['total_applications'] ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list" aria-hidden="true"></i> Job Postings</h3>
        </div>
        <div class="card-body">
            <!-- Toolbar -->
            <div class="filters-bar" role="search" aria-label="Filter jobs">
                <div class="search-input-wrap filters-grow">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="text" class="form-control" id="jmSearch"
                           placeholder="Search jobs..." oninput="jmFilter()" aria-label="Search jobs">
                </div>
                <select class="form-control" id="jmStatus" onchange="jmFilter()" aria-label="Filter by status">
                    <option value="">All Statuses</option>
                    <option>Published</option>
                    <option>Draft</option>
                    <option>Closed</option>
                    <option>Archived</option>
                </select>
                <select class="form-control" id="jmType" onchange="jmFilter()" aria-label="Filter by type">
                    <option value="">All Types</option>
                    <option>Full-Time</option>
                    <option>Part-Time</option>
                    <option>Contract</option>
                    <option>Internship</option>
                    <option>Remote</option>
                    <option>Volunteer</option>
                </select>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-stack" id="jmTable">
                    <thead>
                        <tr>
                            <th>Job Title</th>
                            <th>Type</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th>Featured</th>
                            <th>Urgent</th>
                            <th>Apps</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($jobs)): ?>
                        <tr class="jm-empty">
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="fas fa-briefcase" aria-hidden="true"></i>
                                    <p>No jobs yet. Click <strong>Post New Job</strong> to get started.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($jobs as $j):
                            $dl = jobs_deadline_info($j);
                        ?>
                        <tr data-title="<?= htmlspecialchars(strtolower($j['job_title'])) ?>"
                            data-status="<?= htmlspecialchars($j['status']) ?>"
                            data-type="<?= htmlspecialchars($j['job_type']) ?>">

                            <!-- Title -->
                            <td data-label="Job Title">
                                <div class="jb-title">
                                    <?= htmlspecialchars($j['job_title']) ?>
                                    <?php if ($j['is_featured']): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-star" aria-hidden="true"></i> Featured
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($j['is_urgent']): ?>
                                        <span class="badge badge-danger">
                                            <i class="fas fa-fire" aria-hidden="true"></i> Urgent
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="jb-sub cell-muted">
                                    <?php if ($j['department']): ?>
                                        <span><i class="fas fa-building" aria-hidden="true"></i><?= htmlspecialchars($j['department']) ?></span>
                                        <span aria-hidden="true">&middot;</span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-map-marker-alt" aria-hidden="true"></i><?= htmlspecialchars($j['location'] ?? '-') ?></span>
                                </div>
                            </td>

                            <!-- Type -->
                            <td data-label="Type">
                                <span class="badge badge-info"><?= htmlspecialchars($j['job_type']) ?></span>
                            </td>

                            <!-- Deadline -->
                            <td data-label="Deadline">
                                <?php if ($dl): ?>
                                    <div class="jb-deadline"><?= $dl['formatted'] ?></div>
                                    <div class="jb-deadline-sub cell-muted <?= $dl['is_soon'] ? 'soon' : '' ?>">
                                        <?= $dl['days_left'] ?>d left
                                    </div>
                                <?php else: ?>
                                    <span class="cell-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- Status select -->
                            <td data-label="Status">
                                <select class="form-control jb-status-sel" aria-label="Job status"
                                        onchange="jmChangeStatus(<?= $j['job_id'] ?>, this.value)">
                                    <?php foreach (['Draft', 'Published', 'Closed', 'Archived'] as $s): ?>
                                        <option <?= $j['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <!-- Featured toggle -->
                            <td data-label="Featured">
                                <button type="button" class="jm-toggle <?= $j['is_featured'] ? 'on' : '' ?>"
                                        onclick="jmToggle(<?= $j['job_id'] ?>, 'is_featured', this)"
                                        title="Toggle featured" aria-label="Toggle featured"></button>
                            </td>

                            <!-- Urgent toggle -->
                            <td data-label="Urgent">
                                <button type="button" class="jm-toggle <?= $j['is_urgent'] ? 'on' : '' ?>"
                                        onclick="jmToggle(<?= $j['job_id'] ?>, 'is_urgent', this)"
                                        title="Toggle urgent" aria-label="Toggle urgent"></button>
                            </td>

                            <!-- App count -->
                            <td data-label="Apps">
                                <span class="badge badge-secondary">
                                    <i class="fas fa-users" aria-hidden="true"></i>
                                    <?= intval($j['app_count']) ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td data-label="Actions">
                                <div class="jb-acts">
                                    <button type="button" class="btn btn-sm btn-info" title="View listing" aria-label="View listing"
                                            onclick="window.open('jobs-listings#job-<?= $j['job_id'] ?>','_blank')">
                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-soft" title="Edit" aria-label="Edit"
                                            onclick="jmOpenEdit(<?= $j['job_id'] ?>)">
                                        <i class="fas fa-pen" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" title="Delete" aria-label="Delete"
                                            onclick="jmOpenDelete(<?= $j['job_id'] ?>, <?= htmlspecialchars(json_encode((string)($j['job_title'])), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fas fa-trash" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div><!-- /.page-stack -->


<!-- --------------------------------------------------------------------------
     JOB FORM MODAL
--------------------------------------------------------------------------- -->
<div class="jm-overlay" id="jmJobModal">
    <div class="jm-modal">
        <div class="jm-modal-hdr">
            <h3><i class="fas fa-briefcase"></i> <span id="jmModalTitle">Post New Job</span></h3>
            <button type="button" class="jm-modal-close" onclick="jmClose('jmJobModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="jm-modal-body">
            <input type="hidden" id="f_job_id">

            <!-- Basic information -->
            <div class="modal-section-label"><i class="fas fa-info-circle" aria-hidden="true"></i> Basic Information</div>
            <div class="jm-form-grid">
                <div class="form-group jm-form-full">
                    <label>Job Title <span class="jb-req">*</span></label>
                    <input type="text" class="form-control" id="f_job_title" placeholder="e.g. Software Engineer">
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" class="form-control" id="f_department" placeholder="e.g. Technology">
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" class="form-control" id="f_location" placeholder="e.g. Kampala, Uganda">
                </div>
                <div class="form-group">
                    <label>Job Type</label>
                    <select class="form-control" id="f_job_type">
                        <option>Full-Time</option><option>Part-Time</option><option>Contract</option>
                        <option>Internship</option><option>Volunteer</option><option>Remote</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Experience Level</label>
                    <select class="form-control" id="f_experience_level">
                        <option>Entry Level</option><option>Mid Level</option><option>Senior Level</option>
                        <option>Lead / Manager</option><option>Executive</option>
                    </select>
                </div>
            </div>

            <!-- Compensation -->
            <div class="modal-section-label"><i class="fas fa-money-bill-wave" aria-hidden="true"></i> Compensation</div>
            <div class="jm-form-grid cols3">
                <div class="form-group">
                    <label>Min Salary</label>
                    <input type="number" class="form-control" id="f_salary_min" placeholder="e.g. 1 500 000">
                </div>
                <div class="form-group">
                    <label>Max Salary</label>
                    <input type="number" class="form-control" id="f_salary_max" placeholder="e.g. 3 000 000">
                </div>
                <div class="form-group">
                    <label>Currency</label>
                    <select class="form-control" id="f_salary_currency">
                        <option value="UGX">UGX</option><option value="USD">USD</option>
                        <option value="KES">KES</option><option value="EUR">EUR</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pay Period</label>
                    <select class="form-control" id="f_salary_period">
                        <option>Month</option><option>Year</option><option>Hour</option>
                    </select>
                </div>
                <div class="form-group jm-form-col-span2">
                    <div class="jm-toggle-row jm-toggle-row-fill">
                        <div class="jm-toggle-row-lbl">
                            Show Salary
                            <small>Display compensation on the listing</small>
                        </div>
                        <button type="button" class="jm-ltoggle on" id="t_show_salary"
                                onclick="this.classList.toggle('on')"></button>
                    </div>
                </div>
            </div>

            <!-- Job content -->
            <div class="modal-section-label"><i class="fas fa-align-left" aria-hidden="true"></i> Job Content</div>
            <div class="jm-form-grid">
                <div class="form-group jm-form-full">
                    <label>Description <span class="jb-req">*</span></label>
                    <textarea class="form-control jb-tall" id="f_description"
                              placeholder="Describe the role and its purpose..."></textarea>
                </div>
                <div class="form-group jm-form-full">
                    <label>Responsibilities</label>
                    <textarea class="form-control" id="f_responsibilities"
                              placeholder="Key duties (one per line)..."></textarea>
                </div>
                <div class="form-group">
                    <label>Requirements</label>
                    <textarea class="form-control" id="f_requirements"
                              placeholder="Required skills and qualifications..."></textarea>
                </div>
                <div class="form-group">
                    <label>Benefits</label>
                    <textarea class="form-control" id="f_benefits"
                              placeholder="Perks, benefits, culture..."></textarea>
                </div>
            </div>

            <!-- Application settings -->
            <div class="modal-section-label"><i class="fas fa-paper-plane" aria-hidden="true"></i> Application Settings</div>
            <div class="jm-form-grid">
                <div class="form-group">
                    <label>Application Deadline</label>
                    <input type="date" class="form-control" id="f_deadline">
                </div>
                <div class="form-group">
                    <label>Max Applicants</label>
                    <input type="number" class="form-control" id="f_max_applicants"
                           placeholder="Leave empty for unlimited">
                </div>
                <div class="form-group jm-form-full">
                    <label>External Apply URL</label>
                    <input type="url" class="form-control" id="f_apply_url"
                           placeholder="https://... (leave empty to use internal form)">
                </div>
            </div>

            <!-- Visibility & status -->
            <div class="modal-section-label"><i class="fas fa-eye" aria-hidden="true"></i> Visibility &amp; Status</div>
            <div class="jm-form-grid">
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" id="f_status">
                        <option value="Draft">Draft</option>
                        <option value="Published">Published</option>
                        <option value="Closed">Closed</option>
                        <option value="Archived">Archived</option>
                    </select>
                </div>
                <div class="form-group jm-toggle-stack">
                    <div class="jm-toggle-row">
                        <div class="jm-toggle-row-lbl">
                            Featured <small>Highlighted on listings</small>
                        </div>
                        <button type="button" class="jm-ltoggle" id="t_is_featured"
                                onclick="this.classList.toggle('on')"></button>
                    </div>
                    <div class="jm-toggle-row">
                        <div class="jm-toggle-row-lbl">
                            Urgent <small>Shows urgency badge</small>
                        </div>
                        <button type="button" class="jm-ltoggle" id="t_is_urgent"
                                onclick="this.classList.toggle('on')"></button>
                    </div>
                </div>
            </div>
        </div><!-- /.jm-modal-body -->

        <div class="jm-modal-foot">
            <button type="button" class="btn btn-secondary" onclick="jmClose('jmJobModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn btn-primary" id="jmSaveBtn" onclick="jmSave()">
                <i class="fas fa-save"></i> <span id="jmSaveTxt">Post Job</span>
            </button>
        </div>
    </div>
</div>


<!-- --------------------------------------------------------------------------
     DELETE CONFIRM MODAL
--------------------------------------------------------------------------- -->
<div class="jm-overlay" id="jmDelModal">
    <div class="jm-modal jm-modal-sm">
        <div class="jm-confirm-body">
            <div class="jm-confirm-icon"><i class="fas fa-trash-alt"></i></div>
            <div class="jm-confirm-title">Delete Job Posting?</div>
            <p class="jm-confirm-msg">
                You're about to permanently delete<br>
                "<span class="jm-confirm-name" id="jmDelName"></span>".<br>
                This action <strong>cannot be undone</strong>.
            </p>
        </div>
        <div class="jm-modal-foot jm-modal-foot-center">
            <button type="button" class="btn btn-secondary"  onclick="jmClose('jmDelModal')">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="jmConfirmDelete()">
                <i class="fas fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>


<!-- Toast -->
<div class="jm-toast" id="jmToast">
    <span class="jt-icon" id="jmToastIcon"></span>
    <span id="jmToastMsg"></span>
</div>


<script>
(function () {
    'use strict';

    /* -- Toast ---------------------------------------------------------------- */
    window.jmToast = function (msg, ok) {
        ok = ok !== false;
        var el = document.getElementById('jmToast');
        el.className = 'jm-toast ' + (ok ? 'ok' : 'err');
        document.getElementById('jmToastIcon').innerHTML = ok
            ? '<i class="fas fa-check-circle"></i>'
            : '<i class="fas fa-exclamation-circle"></i>';
        document.getElementById('jmToastMsg').textContent = msg;
        el.classList.add('show');
        setTimeout(function () { el.classList.remove('show'); }, 3200);
    };

    /* -- Modals --------------------------------------------------------------- */
    window.jmOpen  = function (id) { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; };
    window.jmClose = function (id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = '';       };

    document.querySelectorAll('.jm-overlay').forEach(function (ov) {
        ov.addEventListener('click', function (e) { if (e.target === ov) jmClose(ov.id); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.jm-overlay.open').forEach(function (o) { jmClose(o.id); });
        }
    });

    /* -- Filter table --------------------------------------------------------- */
    window.jmFilter = function () {
        var q  = document.getElementById('jmSearch').value.toLowerCase();
        var fs = document.getElementById('jmStatus').value;
        var ft = document.getElementById('jmType').value;
        document.querySelectorAll('#jmTable tbody tr:not(.jm-empty)').forEach(function (tr) {
            var show = (!q  || (tr.dataset.title  || '').includes(q))
                    && (!fs || tr.dataset.status  === fs)
                    && (!ft || tr.dataset.type    === ft);
            tr.style.display = show ? '' : 'none';
        });
    };

    /* -- Field helpers -------------------------------------------------------- */
    function fv(id)       { return document.getElementById(id).value; }
    function fset(id, v)  { var el = document.getElementById(id); if (el) el.value = (v != null ? v : ''); }
    function togOn(id, v) { var el = document.getElementById(id); if (el) el.classList.toggle('on', !!+v); }

    function jmResetForm() {
        ['f_job_id','f_job_title','f_department','f_location','f_salary_min','f_salary_max',
         'f_description','f_responsibilities','f_requirements','f_benefits',
         'f_apply_url','f_deadline','f_max_applicants'].forEach(function (id) { fset(id, ''); });
        fset('f_job_type',         'Full-Time');
        fset('f_experience_level', 'Entry Level');
        fset('f_salary_currency',  'UGX');
        fset('f_salary_period',    'Month');
        fset('f_status',           'Draft');
        togOn('t_show_salary', 1);
        togOn('t_is_featured', 0);
        togOn('t_is_urgent',   0);
    }

    /* -- Open create ---------------------------------------------------------- */
    window.openCreateModal = function () {
        jmResetForm();
        document.getElementById('jmModalTitle').textContent = 'Post New Job';
        document.getElementById('jmSaveTxt').textContent    = 'Post Job';
        jmOpen('jmJobModal');
    };

    /* -- Open edit ------------------------------------------------------------ */
    window.jmOpenEdit = async function (id) {
        var res  = await fetch('includes/admin-jobs.php?action=get&id=' + id);
        var data = await res.json();
        if (!data.ok) { jmToast(data.msg, false); return; }
        var j = data.job;

        fset('f_job_id',           j.job_id);
        fset('f_job_title',        j.job_title);
        fset('f_department',       j.department);
        fset('f_location',         j.location);
        fset('f_job_type',         j.job_type);
        fset('f_experience_level', j.experience_level);
        fset('f_salary_min',       j.salary_min);
        fset('f_salary_max',       j.salary_max);
        fset('f_salary_currency',  j.salary_currency);
        fset('f_salary_period',    j.salary_period);
        togOn('t_show_salary',     j.show_salary);
        fset('f_description',      j.description);
        fset('f_responsibilities', j.responsibilities);
        fset('f_requirements',     j.requirements);
        fset('f_benefits',         j.benefits);
        fset('f_apply_url',        j.apply_url);
        fset('f_deadline',         j.deadline);
        fset('f_max_applicants',   j.max_applicants);
        fset('f_status',           j.status);
        togOn('t_is_featured',     j.is_featured);
        togOn('t_is_urgent',       j.is_urgent);

        document.getElementById('jmModalTitle').textContent = 'Edit Job';
        document.getElementById('jmSaveTxt').textContent    = 'Update Job';
        jmOpen('jmJobModal');
    };

    /* -- Save ----------------------------------------------------------------- */
    window.jmSave = async function () {
        var title = fv('f_job_title').trim();
        if (!title) { jmToast('Job title is required.', false); return; }

        var btn = document.getElementById('jmSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        var payload = {
            job_id: fv('f_job_id'), job_title: title,
            department: fv('f_department'), location: fv('f_location'),
            job_type: fv('f_job_type'), experience_level: fv('f_experience_level'),
            salary_min: fv('f_salary_min'), salary_max: fv('f_salary_max'),
            salary_currency: fv('f_salary_currency'), salary_period: fv('f_salary_period'),
            show_salary: document.getElementById('t_show_salary').classList.contains('on') ? 1 : 0,
            description: fv('f_description'), responsibilities: fv('f_responsibilities'),
            requirements: fv('f_requirements'), benefits: fv('f_benefits'),
            apply_url: fv('f_apply_url'), deadline: fv('f_deadline'),
            max_applicants: fv('f_max_applicants'), status: fv('f_status'),
            is_featured: document.getElementById('t_is_featured').classList.contains('on') ? 1 : 0,
            is_urgent:   document.getElementById('t_is_urgent').classList.contains('on')   ? 1 : 0,
        };

        try {
            var res  = await fetch('includes/admin-jobs.php?action=save', { method: 'POST', body: JSON.stringify(payload) });
            var data = await res.json();
            if (data.ok) { jmToast(data.msg); jmClose('jmJobModal'); setTimeout(function () { location.reload(); }, 800); }
            else jmToast(data.msg, false);
        } catch (_) { jmToast('Request failed.', false); }

        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> <span id="jmSaveTxt">Save Job</span>';
    };

    /* -- Delete --------------------------------------------------------------- */
    var jmDelId = null;

    window.jmOpenDelete = function (id, name) {
        jmDelId = id;
        document.getElementById('jmDelName').textContent = name;
        jmOpen('jmDelModal');
    };

    window.jmConfirmDelete = async function () {
        var res  = await fetch('includes/admin-jobs.php?action=delete&id=' + jmDelId);
        var data = await res.json();
        if (data.ok) { jmToast(data.msg); jmClose('jmDelModal'); setTimeout(function () { location.reload(); }, 800); }
        else jmToast(data.msg, false);
    };

    /* -- Toggle --------------------------------------------------------------- */
    window.jmToggle = async function (id, field, el) {
        var res  = await fetch('includes/admin-jobs.php?action=toggle&id=' + id + '&field=' + field);
        var data = await res.json();
        if (data.ok) el.classList.toggle('on');
        else jmToast(data.msg, false);
    };

    /* -- Status change -------------------------------------------------------- */
    window.jmChangeStatus = async function (id, val) {
        var res  = await fetch('includes/admin-jobs.php?action=status&id=' + id + '&val=' + encodeURIComponent(val));
        var data = await res.json();
        if (data.ok) jmToast('Status updated.');
        else jmToast(data.msg, false);
    };

}());
</script>

<?php include 'includes/footer.php'; ?>