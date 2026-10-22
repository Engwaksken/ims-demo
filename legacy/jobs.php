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

<div class="jm-wrap">

    <!-- -- Page header -------------------------------------------------------- -->
    <div class="jm-page-hdr">
        <div>
            <h2><i class="fas fa-briefcase"></i> Manage Jobs</h2>
            <p>Create, edit, and manage job postings. Toggle featured / urgent or copy the embed code.</p>
        </div>
        <div class="jm-hdr-actions">
            <a href="jobs-listings" target="_blank" class="jm-btn jm-btn-ghost">
                <i class="fas fa-external-link-alt"></i> View Listings
            </a>
            <button class="jm-btn jm-btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Post New Job
            </button>
        </div>
    </div>

    <!-- -- Stats -------------------------------------------------------------- -->
    <div class="jm-stats">
        <div class="jm-stat">
            <div class="jm-stat-icon orange"><i class="fas fa-briefcase"></i></div>
            <div>
                <div class="jm-stat-val"><?= $stats['total'] ?></div>
                <div class="jm-stat-lbl">Total Jobs</div>
            </div>
        </div>
        <div class="jm-stat">
            <div class="jm-stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="jm-stat-val"><?= $stats['published'] ?></div>
                <div class="jm-stat-lbl">Published</div>
            </div>
        </div>
        <div class="jm-stat">
            <div class="jm-stat-icon amber"><i class="fas fa-edit"></i></div>
            <div>
                <div class="jm-stat-val"><?= $stats['draft'] ?></div>
                <div class="jm-stat-lbl">Drafts</div>
            </div>
        </div>
        <div class="jm-stat">
            <div class="jm-stat-icon blue"><i class="fas fa-users"></i></div>
            <div>
                <div class="jm-stat-val"><?= $stats['total_applications'] ?></div>
                <div class="jm-stat-lbl">Applications</div>
            </div>
        </div>
    </div>

    <!-- -- Toolbar ------------------------------------------------------------ -->
    <div class="jm-toolbar filters-bar" role="search" aria-label="Filter jobs">
        <div class="jm-search-wrap filters-grow">
            <i class="fas fa-search"></i>
            <input type="text" class="jm-search" id="jmSearch"
                   placeholder="Search jobs..." oninput="jmFilter()">
        </div>
        <select class="jm-filter" id="jmStatus" onchange="jmFilter()">
            <option value="">All Statuses</option>
            <option>Published</option>
            <option>Draft</option>
            <option>Closed</option>
            <option>Archived</option>
        </select>
        <select class="jm-filter" id="jmType" onchange="jmFilter()">
            <option value="">All Types</option>
            <option>Full-Time</option>
            <option>Part-Time</option>
            <option>Contract</option>
            <option>Internship</option>
            <option>Remote</option>
            <option>Volunteer</option>
        </select>
    </div>

    <!-- -- Table -------------------------------------------------------------- -->
    <div class="jm-table-card">
        <table class="jm-table" id="jmTable">
            <thead>
                <tr>
                    <th class="col-title">Job Title</th>
                    <th>Type</th>
                    <th>Deadline</th>
                    <th>Status</th>
                    <th>Featured</th>
                    <th>Urgent</th>
                    <th>Apps</th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($jobs)): ?>
                <tr class="jm-empty">
                    <td colspan="8">
                        <i class="fas fa-briefcase"></i>
                        <p>No jobs yet. Click <strong>Post New Job</strong> to get started.</p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($jobs as $j):
                    $dl = jobs_deadline_info($j);
                    $status_pill = match($j['status']) {
                        'Published' => 'jm-pill-green',
                        'Draft'     => 'jm-pill-amber',
                        'Closed'    => 'jm-pill-red',
                        default     => 'jm-pill-gray',
                    };
                ?>
                <tr data-title="<?= htmlspecialchars(strtolower($j['job_title'])) ?>"
                    data-status="<?= htmlspecialchars($j['status']) ?>"
                    data-type="<?= htmlspecialchars($j['job_type']) ?>">

                    <!-- Title -->
                    <td>
                        <div class="jm-job-title">
                            <?= htmlspecialchars($j['job_title']) ?>
                            <?php if ($j['is_featured']): ?>
                                <span class="jm-badge-feat">
                                    <i class="fas fa-star"></i> Featured
                                </span>
                            <?php endif; ?>
                            <?php if ($j['is_urgent']): ?>
                                <span class="jm-badge-urgent">
                                    <i class="fas fa-fire"></i> Urgent
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="jm-job-sub">
                            <?php if ($j['department']): ?>
                                <span><i class="fas fa-building"></i> <?= htmlspecialchars($j['department']) ?></span>
                                <span class="jm-job-sub-sep">�</span>
                            <?php endif; ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($j['location'] ?? '-') ?></span>
                        </div>
                    </td>

                    <!-- Type -->
                    <td>
                        <span class="jm-pill jm-pill-blue"><?= htmlspecialchars($j['job_type']) ?></span>
                    </td>

                    <!-- Deadline -->
                    <td>
                        <?php if ($dl): ?>
                            <div class="jm-deadline"><?= $dl['formatted'] ?></div>
                            <div class="jm-deadline-sub <?= $dl['is_soon'] ? 'soon' : '' ?>">
                                <?= $dl['days_left'] ?>d left
                            </div>
                        <?php else: ?>
                            <span class="jm-no-value">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- Status select -->
                    <td>
                        <select class="jm-status-sel"
                                onchange="jmChangeStatus(<?= $j['job_id'] ?>, this.value)">
                            <?php foreach (['Draft', 'Published', 'Closed', 'Archived'] as $s): ?>
                                <option <?= $j['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>

                    <!-- Featured toggle -->
                    <td>
                        <button class="jm-toggle <?= $j['is_featured'] ? 'on' : '' ?>"
                                onclick="jmToggle(<?= $j['job_id'] ?>, 'is_featured', this)"
                                title="Toggle featured"></button>
                    </td>

                    <!-- Urgent toggle -->
                    <td>
                        <button class="jm-toggle <?= $j['is_urgent'] ? 'on' : '' ?>"
                                onclick="jmToggle(<?= $j['job_id'] ?>, 'is_urgent', this)"
                                title="Toggle urgent"></button>
                    </td>

                    <!-- App count -->
                    <td>
                        <span class="jm-app-count">
                            <i class="fas fa-users"></i>
                            <?= intval($j['app_count']) ?>
                        </span>
                    </td>

                    <!-- Actions -->
                    <td>
                        <div class="jm-acts">
                            <button class="jm-act jm-act-view" title="View listing"
                                    onclick="window.open('jobs-listings#job-<?= $j['job_id'] ?>','_blank')">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="jm-act jm-act-edit" title="Edit"
                                    onclick="jmOpenEdit(<?= $j['job_id'] ?>)">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="jm-act jm-act-del" title="Delete"
                                    onclick="jmOpenDelete(<?= $j['job_id'] ?>, <?= htmlspecialchars(json_encode((string)($j['job_title'])), ENT_QUOTES, 'UTF-8') ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div><!-- /.jm-wrap -->


<!-- --------------------------------------------------------------------------
     JOB FORM MODAL
--------------------------------------------------------------------------- -->
<div class="jm-overlay" id="jmJobModal">
    <div class="jm-modal">
        <div class="jm-modal-hdr">
            <h3><i class="fas fa-briefcase"></i> <span id="jmModalTitle">Post New Job</span></h3>
            <button class="jm-modal-close" onclick="jmClose('jmJobModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="jm-modal-body">
            <input type="hidden" id="f_job_id">

            <!-- Basic information -->
            <div class="jm-section-ttl"><i class="fas fa-info-circle"></i> Basic Information</div>
            <div class="jm-form-grid">
                <div class="jm-fg jm-form-full">
                    <label>Job Title <span>*</span></label>
                    <input type="text" class="jm-fi" id="f_job_title" placeholder="e.g. Software Engineer">
                </div>
                <div class="jm-fg">
                    <label>Department</label>
                    <input type="text" class="jm-fi" id="f_department" placeholder="e.g. Technology">
                </div>
                <div class="jm-fg">
                    <label>Location</label>
                    <input type="text" class="jm-fi" id="f_location" placeholder="e.g. Kampala, Uganda">
                </div>
                <div class="jm-fg">
                    <label>Job Type</label>
                    <select class="jm-fs" id="f_job_type">
                        <option>Full-Time</option><option>Part-Time</option><option>Contract</option>
                        <option>Internship</option><option>Volunteer</option><option>Remote</option>
                    </select>
                </div>
                <div class="jm-fg">
                    <label>Experience Level</label>
                    <select class="jm-fs" id="f_experience_level">
                        <option>Entry Level</option><option>Mid Level</option><option>Senior Level</option>
                        <option>Lead / Manager</option><option>Executive</option>
                    </select>
                </div>
            </div>

            <!-- Compensation -->
            <div class="jm-section-ttl"><i class="fas fa-money-bill-wave"></i> Compensation</div>
            <div class="jm-form-grid cols3">
                <div class="jm-fg">
                    <label>Min Salary</label>
                    <input type="number" class="jm-fi" id="f_salary_min" placeholder="e.g. 1 500 000">
                </div>
                <div class="jm-fg">
                    <label>Max Salary</label>
                    <input type="number" class="jm-fi" id="f_salary_max" placeholder="e.g. 3 000 000">
                </div>
                <div class="jm-fg">
                    <label>Currency</label>
                    <select class="jm-fs" id="f_salary_currency">
                        <option value="UGX">UGX</option><option value="USD">USD</option>
                        <option value="KES">KES</option><option value="EUR">EUR</option>
                    </select>
                </div>
                <div class="jm-fg">
                    <label>Pay Period</label>
                    <select class="jm-fs" id="f_salary_period">
                        <option>Month</option><option>Year</option><option>Hour</option>
                    </select>
                </div>
                <div class="jm-fg jm-form-col-span2">
                    <div class="jm-toggle-row jm-toggle-row-fill">
                        <div class="jm-toggle-row-lbl">
                            Show Salary
                            <small>Display compensation on the listing</small>
                        </div>
                        <button class="jm-ltoggle on" id="t_show_salary"
                                onclick="this.classList.toggle('on')"></button>
                    </div>
                </div>
            </div>

            <!-- Job content -->
            <div class="jm-section-ttl"><i class="fas fa-align-left"></i> Job Content</div>
            <div class="jm-form-grid">
                <div class="jm-fg jm-form-full">
                    <label>Description <span>*</span></label>
                    <textarea class="jm-fta jm-fta-tall" id="f_description"
                              placeholder="Describe the role and its purpose..."></textarea>
                </div>
                <div class="jm-fg jm-form-full">
                    <label>Responsibilities</label>
                    <textarea class="jm-fta" id="f_responsibilities"
                              placeholder="Key duties (one per line)..."></textarea>
                </div>
                <div class="jm-fg">
                    <label>Requirements</label>
                    <textarea class="jm-fta" id="f_requirements"
                              placeholder="Required skills and qualifications..."></textarea>
                </div>
                <div class="jm-fg">
                    <label>Benefits</label>
                    <textarea class="jm-fta" id="f_benefits"
                              placeholder="Perks, benefits, culture..."></textarea>
                </div>
            </div>

            <!-- Application settings -->
            <div class="jm-section-ttl"><i class="fas fa-paper-plane"></i> Application Settings</div>
            <div class="jm-form-grid">
                <div class="jm-fg">
                    <label>Application Deadline</label>
                    <input type="date" class="jm-fi" id="f_deadline">
                </div>
                <div class="jm-fg">
                    <label>Max Applicants</label>
                    <input type="number" class="jm-fi" id="f_max_applicants"
                           placeholder="Leave empty for unlimited">
                </div>
                <div class="jm-fg jm-form-full">
                    <label>External Apply URL</label>
                    <input type="url" class="jm-fi" id="f_apply_url"
                           placeholder="https://... (leave empty to use internal form)">
                </div>
            </div>

            <!-- Visibility & status -->
            <div class="jm-section-ttl"><i class="fas fa-eye"></i> Visibility &amp; Status</div>
            <div class="jm-form-grid">
                <div class="jm-fg">
                    <label>Status</label>
                    <select class="jm-fs" id="f_status">
                        <option value="Draft">Draft</option>
                        <option value="Published">Published</option>
                        <option value="Closed">Closed</option>
                        <option value="Archived">Archived</option>
                    </select>
                </div>
                <div class="jm-fg jm-toggle-stack">
                    <div class="jm-toggle-row">
                        <div class="jm-toggle-row-lbl">
                            Featured <small>Highlighted on listings</small>
                        </div>
                        <button class="jm-ltoggle" id="t_is_featured"
                                onclick="this.classList.toggle('on')"></button>
                    </div>
                    <div class="jm-toggle-row">
                        <div class="jm-toggle-row-lbl">
                            Urgent <small>Shows urgency badge</small>
                        </div>
                        <button class="jm-ltoggle" id="t_is_urgent"
                                onclick="this.classList.toggle('on')"></button>
                    </div>
                </div>
            </div>
        </div><!-- /.jm-modal-body -->

        <div class="jm-modal-foot">
            <button class="jm-btn jm-btn-ghost" onclick="jmClose('jmJobModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="jm-btn jm-btn-primary" id="jmSaveBtn" onclick="jmSave()">
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
            <button class="jm-btn jm-btn-ghost"  onclick="jmClose('jmDelModal')">Cancel</button>
            <button class="jm-btn jm-btn-danger" onclick="jmConfirmDelete()">
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