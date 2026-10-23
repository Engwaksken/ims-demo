<?php
ob_start();
session_start();
// require_once 'includes/config.php';
require_once 'includes/header.php';

$page_title    = 'Risk Rating - New Section Entry';

if (!ims_allowed('risk_ratings.edit', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer'], 'Create and edit risk ratings')) {
    $_SESSION['error'] = "Access denied.";
    header("Location: dashboard");
    exit();
}

// -- Helpers -------------------------------------------------------------------

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function post_str(string $key): ?string
{
    return (isset($_POST[$key]) && $_POST[$key] !== '')
        ? trim($_POST[$key])
        : null;
}

function post_int(string $key): ?int
{
    return (isset($_POST[$key]) && is_numeric($_POST[$key]) && $_POST[$key] !== '')
        ? (int)$_POST[$key]
        : null;
}

// -- AJAX: INSERT --------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (post_str('action') === 'insert')) {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorised.']);
        exit();
    }

    $response = ['success' => false, 'message' => '', 'id' => null];

    $pillar_key   = post_str('pillar_key');
    $pillar_label = post_str('pillar_label');

    if (empty($pillar_key) || empty($pillar_label)) {
        $response['message'] = 'pillar_key and pillar_label are required.';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    $risk_rating_id    = post_int('risk_rating_id');
    $rating_score      = post_int('rating_score');
    $rating_label      = post_str('rating_label');
    $key_findings      = post_str('key_findings');
    $recommendations   = post_str('recommendations');
    $follow_up_actions = post_str('follow_up_actions');
    $notes             = post_str('notes');

    $sql = "INSERT INTO risk_rating_sections
                (risk_rating_id, pillar_key, pillar_label, rating_score, rating_label,
                 key_findings, recommendations, follow_up_actions, notes,
                 created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 NOW(), NOW())";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        $response['message'] = 'Prepare failed: ' . $conn->error;
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    $stmt->bind_param(
        'ississsss',
        $risk_rating_id,
        $pillar_key,
        $pillar_label,
        $rating_score,
        $rating_label,
        $key_findings,
        $recommendations,
        $follow_up_actions,
        $notes
    );

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        $response = [
            'success' => true,
            'message' => 'Record inserted successfully.',
            'id'      => $new_id,
        ];
    } else {
        $err = $stmt->error;
        $stmt->close();
        $response['message'] = 'Execute failed: ' . $err;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// -- Page-level flash messages -------------------------------------------------

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$success_msg     = $_SESSION['success'] ?? '';
$error_msg       = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<style>
/* risk_rating_form.php: layout and JS states unique to this page.
   Cards, buttons, alerts and form controls come from reports.css. */
#toast { display: none; align-items: flex-start; gap: 12px; position: fixed; right: 20px; bottom: 20px; z-index: 1000; max-width: 360px; padding: 14px 16px; border: 1px solid var(--slate-border); border-radius: var(--radius-lg); background: var(--surface-card); box-shadow: var(--shadow-lg); }
#toast.show { display: flex; }
#toast.success { background: var(--green-bg); border-color: var(--green-border); color: var(--green-fg); }
#toast.error { background: var(--red-bg); border-color: var(--red-border); color: var(--red-fg); }
#toast .toast-body { display: flex; flex-direction: column; gap: 2px; }
#toast .toast-icon { font-weight: 800; }
.rrf-tag { display: inline-grid; place-items: center; min-width: 28px; height: 24px; margin-right: 4px; padding: 0 6px; border-radius: var(--radius-sm); background: var(--brand-50); color: var(--brand-700); font-size: var(--fs-xs); font-weight: 800; }
.rrf-pills { display: flex; flex-wrap: wrap; gap: 8px; }
#pillarPills .pill { padding: 6px 12px; border: 1px solid var(--ink-100); border-radius: var(--radius-pill); background: var(--surface-card); color: var(--ink-500); font-size: var(--fs-sm); font-weight: 600; cursor: pointer; user-select: none; }
#pillarPills .pill:hover { border-color: var(--brand-400); color: var(--brand-700); }
#pillarPills .pill.selected { background: var(--brand-50); border-color: var(--brand-500); color: var(--brand-700); }
.rrf-score-row { display: flex; align-items: center; gap: 14px; }
.rrf-score-row input[type="range"] { flex: 1 1 auto; accent-color: var(--brand-500); }
.score-display { min-width: 48px; padding: 6px 10px; border-radius: var(--radius-md); background: var(--brand-50); color: var(--brand-700); font-size: var(--fs-lg); font-weight: 800; text-align: center; }
.score-display.null-val { background: var(--ink-50); color: var(--ink-300); }
.err-msg { display: none; color: var(--red-fg); font-size: var(--fs-xs); font-weight: 600; }
.err-msg.visible { display: block; }
.error-field { border-color: var(--red-fg) !important; }
.char-counter { color: var(--ink-300); font-size: var(--fs-xs); }
.char-counter.warn { color: var(--amber-fg); }
.char-counter.over { color: var(--red-fg); font-weight: 700; }
.rrf-counter-row { display: flex; justify-content: space-between; gap: 8px; }
.rrf-counter-row .char-counter { margin-left: auto; }
.rrf-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.rrf-actions .cell-muted { margin: 0 0 0 auto; }
#rrsForm { display: flex; flex-direction: column; gap: 20px; }
.btn-spinner { display: none; width: 14px; height: 14px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: rrf-spin .7s linear infinite; }
#submitBtn.loading .btn-spinner { display: inline-block; }
@keyframes rrf-spin { to { transform: rotate(360deg); } }
</style>

<div id="toast" role="alert" aria-live="assertive">
    <div class="toast-icon" id="toastIcon"></div>
    <div class="toast-body">
        <strong id="toastTitle"></strong>
        <small id="toastMsg"></small>
    </div>
</div>

<div class="page-stack">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-shield-alt" aria-hidden="true"></i> New Risk Rating Section</h1>
            <p>Add a pillar entry to an existing risk rating record. Required fields are marked with <span class="required-mark">*</span>.</p>
        </div>
    </div>

    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?= h($success_msg) ?></div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <form id="rrsForm" novalidate autocomplete="off">

        <div class="card">
            <div class="card-header">
                <h3><span class="rrf-tag">01</span> Reference</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="risk_rating_id">
                            Risk Rating ID
                            <span class="cell-muted">optional: integer or NULL</span>
                        </label>
                        <input
                            type="number"
                            id="risk_rating_id"
                            name="risk_rating_id"
                            class="form-control"
                            placeholder="e.g. 1"
                            min="1"
                        >
                        <span class="err-msg" id="err_risk_rating_id">Must be a positive integer or left empty.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><span class="rrf-tag">02</span> Pillar</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>
                        Pillar Key <span class="required-mark">*</span>
                    </label>

                    <div class="rrf-pills" id="pillarPills">
                        <span class="pill" data-val="summary">summary</span>
                        <span class="pill" data-val="governance">governance</span>
                        <span class="pill" data-val="operational">operational</span>
                        <span class="pill" data-val="delivery">delivery</span>
                        <span class="pill" data-val="fiducial">fiducial</span>
                        <span class="pill" data-val="safeguarding">safeguarding</span>
                        <span class="pill" data-val="reputational">reputational</span>
                        <span class="pill" data-val="policies_docs_verified">policies &amp; docs</span>
                    </div>

                    <input type="hidden" id="pillar_key" name="pillar_key">
                    <span class="err-msg" id="err_pillar_key">Please select a pillar key.</span>
                </div>

                <div class="form-group">
                    <label for="pillar_label">
                        Pillar Label <span class="required-mark">*</span>
                    </label>
                    <input
                        type="text"
                        id="pillar_label"
                        name="pillar_label"
                        class="form-control"
                        placeholder="e.g. Governance Risks"
                        maxlength="255"
                    >
                    <span class="char-counter" id="cc_pillar_label">0 / 255</span>
                    <span class="err-msg" id="err_pillar_label">Pillar label is required.</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><span class="rrf-tag">03</span> Rating</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="rating_score_range">
                            Rating Score
                            <span class="cell-muted" id="scoreHint">NULL</span>
                        </label>

                        <div class="rrf-score-row">
                            <input
                                type="range"
                                id="rating_score_range"
                                min="0"
                                max="20"
                                step="1"
                                value="0"
                                aria-label="Rating score"
                            >
                            <div class="score-display null-val" id="scoreDisplay">—</div>
                        </div>

                        <span class="cell-muted">
                            Drag to set (0 = NULL). Composite scores use 13; Minor = 1; Moderate = 2; Major = 3.
                        </span>

                        <input type="hidden" id="rating_score" name="rating_score">
                    </div>

                    <div class="form-group">
                        <label for="rating_label">Rating Label</label>
                        <select id="rating_label" name="rating_label" class="form-control">
                            <option value="">None / NULL</option>
                            <option value="Minor">Minor</option>
                            <option value="Moderate">Moderate</option>
                            <option value="Major">Major</option>
                            <option value="Critical">Critical</option>
                            <option value="Composite">Composite</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><span class="rrf-tag">04</span> Content</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="key_findings">Key Findings</label>
                    <textarea
                        id="key_findings"
                        name="key_findings"
                        class="form-control"
                        placeholder="Describe the key findings for this pillar..."
                        rows="4"
                    ></textarea>
                    <div class="rrf-counter-row">
                        <span class="err-msg" id="err_key_findings"></span>
                        <span class="char-counter align-right" id="cc_key_findings">0</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="recommendations">Recommendations</label>
                    <textarea
                        id="recommendations"
                        name="recommendations"
                        class="form-control"
                        placeholder="List recommendations for this pillar..."
                        rows="3"
                    ></textarea>
                    <span class="char-counter" id="cc_recommendations">0</span>
                </div>

                <div class="form-group">
                    <label for="follow_up_actions">Follow-up Actions</label>
                    <textarea
                        id="follow_up_actions"
                        name="follow_up_actions"
                        class="form-control"
                        placeholder="Specify follow-up actions to be taken..."
                        rows="3"
                    ></textarea>
                    <span class="char-counter" id="cc_follow_up_actions">0</span>
                </div>

                <div class="form-group">
                    <label for="notes">
                        Notes
                        <span class="cell-muted">internal use</span>
                    </label>
                    <textarea
                        id="notes"
                        name="notes"
                        class="form-control"
                        placeholder="Any internal notes, status flags, or caveats..."
                        rows="3"
                    ></textarea>
                    <span class="char-counter" id="cc_notes">0</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body rrf-actions">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <div class="btn-spinner"></div>
                    <span class="btn-text">Insert Record</span>
                </button>

                <button type="button" class="btn btn-secondary" id="resetBtn">Clear Form</button>
                <span class="action-info cell-muted" id="actionInfo">All times stored as UTC</span>
            </div>
        </div>
    </form>

    <p class="cell-muted">
        <span>risk_rating_sections . INSERT INTO</span>
        <span id="tsDisplay"></span>
    </p>

</div><!-- /.page-stack -->

<script>
(function tick() {
    document.getElementById('tsDisplay').textContent =
        new Date().toISOString().replace('T', ' ').split('.')[0] + ' UTC';
    setTimeout(tick, 1000);
})();

const pillarInput = document.getElementById('pillar_key');
const labelInput  = document.getElementById('pillar_label');

const PILLAR_LABELS = {
    summary: 'Summary of Findings and Recommendation',
    governance: 'Governance Risks',
    operational: 'Operational Risks',
    delivery: 'Delivery Risks',
    fiducial: 'Fiducial Risks',
    safeguarding: 'Safeguarding Risks',
    reputational: 'Reputational Risks',
    policies_docs_verified: 'Policies and Documents Verified'
};

document.querySelectorAll('#pillarPills .pill').forEach((pill) => {
    pill.addEventListener('click', () => {
        document.querySelectorAll('#pillarPills .pill').forEach((p) => p.classList.remove('selected'));
        pill.classList.add('selected');

        const val = pill.dataset.val;
        pillarInput.value = val;

        const current = labelInput.value.trim();
        const isKnown = Object.values(PILLAR_LABELS).includes(current) || current === '';

        if (isKnown) {
            labelInput.value = PILLAR_LABELS[val] || '';
            updateCounter('pillar_label', 255);
        }

        document.getElementById('err_pillar_key').classList.remove('visible');
    });
});

function updateCounter(id, max) {
    const el = document.getElementById(id);
    const cc = document.getElementById('cc_' + id);
    if (!el || !cc) return;

    const len = el.value.length;
    cc.textContent = max ? `${len} / ${max}` : `${len}`;
    cc.classList.toggle('warn', max ? len > max * 0.85 : false);
    cc.classList.toggle('over', max ? len > max : false);
}

['pillar_label', 'key_findings', 'recommendations', 'follow_up_actions', 'notes'].forEach((id) => {
    const el = document.getElementById(id);
    const max = id === 'pillar_label' ? 255 : 0;
    if (el) {
        el.addEventListener('input', () => updateCounter(id, max));
    }
});

const slider      = document.getElementById('rating_score_range');
const display     = document.getElementById('scoreDisplay');
const scoreHidden = document.getElementById('rating_score');
const scoreHint   = document.getElementById('scoreHint');

function syncSlider(val) {
    if (val === 0) {
        display.textContent = '—';
        display.classList.add('null-val');
        scoreHidden.value = '';
        scoreHint.textContent = 'NULL';
    } else {
        display.textContent = val;
        display.classList.remove('null-val');
        scoreHidden.value = val;
        scoreHint.textContent = val;
    }
}

slider.addEventListener('input', () => syncSlider(parseInt(slider.value, 10)));
syncSlider(0);

function validate() {
    let valid = true;

    const pkErr = document.getElementById('err_pillar_key');
    if (!pillarInput.value) {
        pkErr.classList.add('visible');
        valid = false;
    } else {
        pkErr.classList.remove('visible');
    }

    const pl = labelInput.value.trim();
    const plErr = document.getElementById('err_pillar_label');
    if (!pl) {
        plErr.classList.add('visible');
        labelInput.classList.add('error-field');
        valid = false;
    } else {
        plErr.classList.remove('visible');
        labelInput.classList.remove('error-field');
    }

    const rrid = document.getElementById('risk_rating_id').value;
    const rridErr = document.getElementById('err_risk_rating_id');
    const rridField = document.getElementById('risk_rating_id');

    if (rrid !== '' && (isNaN(rrid) || parseInt(rrid, 10) < 1)) {
        rridErr.classList.add('visible');
        rridField.classList.add('error-field');
        valid = false;
    } else {
        rridErr.classList.remove('visible');
        rridField.classList.remove('error-field');
    }

    return valid;
}

let toastTimer;
function showToast(success, title, msg) {
    clearTimeout(toastTimer);

    const toast   = document.getElementById('toast');
    const icon    = document.getElementById('toastIcon');
    const titleEl = document.getElementById('toastTitle');
    const msgEl   = document.getElementById('toastMsg');

    toast.className = success ? 'show success' : 'show error';
    icon.className = success ? 'toast-icon s' : 'toast-icon e';
    icon.textContent = success ? '' : '×';
    titleEl.textContent = title;
    msgEl.textContent = msg;

    toastTimer = setTimeout(() => {
        toast.className = '';
    }, 5000);
}

document.getElementById('rrsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!validate()) return;

    const btn  = document.getElementById('submitBtn');
    const info = document.getElementById('actionInfo');

    btn.classList.add('loading');
    btn.disabled = true;
    info.textContent = 'Inserting...';

    const data = new FormData(this);
    data.append('action', 'insert');

    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            body: data
        });

        const json = await res.json();

        if (json.success) {
            showToast(true, 'Record Inserted', `New ID: ${json.id} - ${new Date().toISOString()}`);
            info.textContent = `Last insert: ID ${json.id}`;
            resetForm(false);
        } else {
            showToast(false, 'Insert Failed', json.message || 'Insert failed.');
            info.textContent = 'Error - see notification';
        }
    } catch (err) {
        showToast(false, 'Network Error', err.message);
        info.textContent = 'Request failed';
    } finally {
        btn.classList.remove('loading');
        btn.disabled = false;
    }
});

function resetForm(clearInfo = true) {
    document.getElementById('rrsForm').reset();
    pillarInput.value = '';
    scoreHidden.value = '';
    slider.value = 0;
    syncSlider(0);

    document.querySelectorAll('#pillarPills .pill').forEach((p) => p.classList.remove('selected'));
    document.querySelectorAll('.error-field').forEach((el) => el.classList.remove('error-field'));
    document.querySelectorAll('.err-msg.visible').forEach((el) => el.classList.remove('visible'));

    ['pillar_label', 'key_findings', 'recommendations', 'follow_up_actions', 'notes'].forEach((id) => {
        updateCounter(id, id === 'pillar_label' ? 255 : 0);
    });

    if (clearInfo) {
        document.getElementById('actionInfo').textContent = 'All times stored as UTC';
    }
}

document.getElementById('resetBtn').addEventListener('click', () => resetForm());
</script>

<?php require_once 'includes/footer.php'; ?>
