<?php
declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';

$page_title = 'Manage Risk Ratings';

if (!ims_allowed('risk_ratings.view', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Programs Manager', 'Reviewer', 'Project Officer', 'ED'], 'Open the risk ratings list')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

$conn->set_charset('utf8mb4');

require_once 'includes/header.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* -----------------------------------------------------------------------------
   Logged-in user details for automatic signing
----------------------------------------------------------------------------- */
$currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$currentUserRole = trim((string)($_SESSION['role'] ?? ''));
$currentUserName = trim((string)($_SESSION['full_name'] ?? ''));

if ($currentUserId > 0 && $currentUserName === '') {
    $meStmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1");
    if ($meStmt) {
        $meStmt->bind_param('i', $currentUserId);
        $meStmt->execute();
        $meRow = $meStmt->get_result()->fetch_assoc();
        $meStmt->close();
        if (!empty($meRow['full_name'])) {
            $currentUserName = trim((string)$meRow['full_name']);
        }
    }
}
if ($currentUserName === '') {
    $currentUserName = 'Current User';
}

$canPrepare = ims_allowed('risk_ratings.sign_prepared', ['Reviewer'], 'Sign the Prepared By section of risk ratings');
$canReview  = ims_allowed('risk_ratings.sign_reviewed', ['Administrator', 'Programs Lead', 'Programs Manager'], 'Sign the Reviewed By section of risk ratings');
$canApprove = ims_allowed('risk_ratings.sign_approved', ['Administrator', 'ED'], 'Sign the Approved by ED section of risk ratings');

$signaturePermissions = [
    'prep' => [
        'allowed' => $canPrepare,
        'roles'   => ['Reviewer'],
        'label'   => 'Prepared By',
    ],
    'rev' => [
        'allowed' => $canReview,
        'roles'   => ['Administrator', 'Programs Lead', 'Programs Manager'],
        'label'   => 'Reviewed By',
    ],
    'app' => [
        'allowed' => $canApprove,
        'roles'   => ['Administrator', 'ED'],
        'label'   => 'Approved by ED',
    ],
];

/* -----------------------------------------------------------------------------
   Handle delete
----------------------------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'], $_POST['rr_id']) &&
    $_POST['action'] === 'delete'
) {
    $deleteId = (int)$_POST['rr_id'];

    if ($deleteId <= 0) {
        $_SESSION['error'] = 'Invalid risk rating ID.';
        header('Location: manage_risk_ratings');
        exit();
    }

    try {
        $fileRow = null;
        $fs = $conn->prepare("
            SELECT generated_pdf_path, generated_excel_path
            FROM risk_ratings
            WHERE id = ?
            LIMIT 1
        ");
        if ($fs) {
            $fs->bind_param('i', $deleteId);
            $fs->execute();
            $fileRow = $fs->get_result()->fetch_assoc();
            $fs->close();
        }

        if ($fileRow === null) {
            throw new Exception('Risk rating not found (ID: ' . $deleteId . ').');
        }

        $conn->begin_transaction();

        $deleteQueries = [
            "DELETE FROM risk_rating_criteria_scores WHERE risk_rating_id = ?",
            "DELETE FROM risk_rating_checks WHERE risk_rating_id = ?",
            "DELETE FROM risk_rating_sections WHERE risk_rating_id = ?",
            "DELETE FROM risk_ratings WHERE id = ?",
        ];

        foreach ($deleteQueries as $sql) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }

            $stmt->bind_param('i', $deleteId);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception('Execute failed: ' . $err);
            }
            $stmt->close();
        }

        $conn->commit();

        foreach (['generated_pdf_path', 'generated_excel_path'] as $key) {
            if (!empty($fileRow[$key])) {
                $absolutePath = dirname(__DIR__) . '/' . ltrim((string)$fileRow[$key], '/');
                if (file_exists($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }

        $_SESSION['success'] = 'Risk rating deleted successfully.';
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignore) {
        }
        $_SESSION['error'] = 'Delete failed: ' . $e->getMessage();
    }

    $qs = http_build_query(array_filter([
        'search'         => $_POST['search'] ?? '',
        'status'         => $_POST['status'] ?? '',
        'rating'         => $_POST['rating'] ?? '',
        'opportunity_id' => $_POST['opportunity_id'] ?? '',
        'page'           => ((int)($_POST['page'] ?? 1) > 1) ? (int)$_POST['page'] : '',
    ], static fn($v) => $v !== '' && $v !== null && $v !== 0));

    header('Location: manage_risk_ratings' . ($qs ? '?' . $qs : ''));
    exit();
}

/* -----------------------------------------------------------------------------
   Filters
----------------------------------------------------------------------------- */
$search            = trim((string)($_GET['search'] ?? ''));
$filterStatus      = trim((string)($_GET['status'] ?? ''));
$filterRating      = trim((string)($_GET['rating'] ?? ''));
$filterOpportunity = (int)($_GET['opportunity_id'] ?? 0);
$page              = max(1, (int)($_GET['page'] ?? 1));
$perPage           = 20;
$offset            = ($page - 1) * $perPage;

/* -----------------------------------------------------------------------------
   WHERE builder
----------------------------------------------------------------------------- */
$whereParts = [];
$bindTypes  = '';
$bindParams = [];

if ($search !== '') {
    $whereParts[] = '(a.startup_name LIKE ? OR a.email LIKE ? OR a.contact_person LIKE ? OR a.founder_names LIKE ?)';
    $like = '%' . $search . '%';
    $bindTypes .= 'ssss';
    array_push($bindParams, $like, $like, $like, $like);
}

if ($filterStatus !== '') {
    $whereParts[] = 'rr.status = ?';
    $bindTypes .= 's';
    $bindParams[] = $filterStatus;
}

if ($filterRating !== '') {
    $whereParts[] = 'rr.overall_rating_label = ?';
    $bindTypes .= 's';
    $bindParams[] = $filterRating;
}

if ($filterOpportunity > 0) {
    $whereParts[] = 'a.opportunity_id = ?';
    $bindTypes .= 'i';
    $bindParams[] = $filterOpportunity;
}

$whereSQL = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

/* -----------------------------------------------------------------------------
   Count
----------------------------------------------------------------------------- */
$totalRows = 0;
$countSql = "
    SELECT COUNT(*) AS total
    FROM risk_ratings rr
    INNER JOIN applications a ON a.application_id = rr.application_id
    LEFT JOIN application_opportunities o ON o.opportunity_id = a.opportunity_id
    $whereSQL
";
$cStmt = $conn->prepare($countSql);
if ($cStmt) {
    if ($bindTypes !== '') {
        $cStmt->bind_param($bindTypes, ...$bindParams);
    }
    $cStmt->execute();
    $totalRows = (int)($cStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $cStmt->close();
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* -----------------------------------------------------------------------------
   Data rows
----------------------------------------------------------------------------- */
$ratings = [];
$dataSql = "
    SELECT
        rr.id AS rr_id,
        rr.application_id,
        rr.overall_rating,
        rr.overall_rating_label,
        rr.recommendation,
        rr.status,
        rr.generated_pdf_path,
        rr.generated_pdf_name,
        rr.generated_excel_path,
        rr.generated_excel_name,
        rr.updated_at,
        rr.prepared_by,
        rr.prepared_by_name,
        rr.prepared_by_signature,
        rr.reviewed_by_user_id,
        rr.reviewed_by_name,
        rr.reviewed_by_signature,
        rr.reviewer_comments,
        rr.approved_by_user_id,
        rr.approved_by_name,
        rr.approved_by_signature,
        a.startup_name,
        a.contact_person,
        a.founder_names,
        a.email,
        o.opportunity_title,
        pu.full_name AS prepared_by_full_name
    FROM risk_ratings rr
    INNER JOIN applications a ON a.application_id = rr.application_id
    LEFT JOIN application_opportunities o ON o.opportunity_id = a.opportunity_id
    LEFT JOIN users pu ON pu.user_id = rr.prepared_by
    $whereSQL
    ORDER BY rr.updated_at DESC, rr.id DESC
    LIMIT ? OFFSET ?
";
$dStmt = $conn->prepare($dataSql);
if ($dStmt) {
    $dt = $bindTypes . 'ii';
    $dp = array_merge($bindParams, [$perPage, $offset]);
    $dStmt->bind_param($dt, ...$dp);
    $dStmt->execute();
    $res = $dStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ratings[] = $row;
    }
    $dStmt->close();
}

/* -----------------------------------------------------------------------------
   Opportunities
----------------------------------------------------------------------------- */
$opportunities = [];
$oRes = $conn->query("
    SELECT opportunity_id, opportunity_title
    FROM application_opportunities
    ORDER BY opportunity_title ASC
");
if ($oRes) {
    while ($o = $oRes->fetch_assoc()) {
        $opportunities[] = $o;
    }
}

/* -----------------------------------------------------------------------------
   Stats
----------------------------------------------------------------------------- */
$stats = [
    'total'     => 0,
    'generated' => 0,
    'draft'     => 0,
    'minor'     => 0,
    'moderate'  => 0,
    'major'     => 0,
    'severe'    => 0,
    'has_pdf'   => 0,
    'has_excel' => 0,
];

$sRes = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'generated') AS generated,
        SUM(status = 'draft') AS draft,
        SUM(overall_rating_label = 'Minor') AS minor,
        SUM(overall_rating_label = 'Moderate') AS moderate,
        SUM(overall_rating_label = 'Major') AS major,
        SUM(overall_rating_label = 'Severe') AS severe,
        SUM(generated_pdf_path IS NOT NULL AND generated_pdf_path <> '') AS has_pdf,
        SUM(generated_excel_path IS NOT NULL AND generated_excel_path <> '') AS has_excel
    FROM risk_ratings
");
if ($sRes) {
    $stats = array_merge($stats, $sRes->fetch_assoc() ?: []);
}

/* -----------------------------------------------------------------------------
   Helpers
----------------------------------------------------------------------------- */
function ratingBadgeClass(string $label): string
{
    return match (strtolower(trim($label))) {
        'severe'   => 'rb-severe',
        'major'    => 'rb-major',
        'moderate' => 'rb-moderate',
        default    => 'rb-minor',
    };
}

function scoreCircleClass(string $label): string
{
    return match (strtolower(trim($label))) {
        'severe'   => 'sc-severe',
        'major'    => 'sc-major',
        'moderate' => 'sc-moderate',
        default    => 'sc-minor',
    };
}

function recClass(string $rec): string
{
    return match (trim($rec)) {
        'Proceed'                 => 'rc-proceed',
        'Proceed with Conditions' => 'rc-cond',
        'Hold'                    => 'rc-hold',
        'Decline'                 => 'rc-decline',
        default                   => 'rc-default',
    };
}

function buildQS(array $override = []): string
{
    $base = [
        'search'         => $_GET['search'] ?? '',
        'status'         => $_GET['status'] ?? '',
        'rating'         => $_GET['rating'] ?? '',
        'opportunity_id' => $_GET['opportunity_id'] ?? '',
        'page'           => $_GET['page'] ?? 1,
    ];

    return http_build_query(array_filter(
        array_merge($base, $override),
        static fn($v) => $v !== '' && $v !== '0' && $v !== 0 && $v !== null
    ));
}

function sigStatus(array $rr): array
{
    $steps = [
        'prepared' => !empty($rr['prepared_by_name']) && !empty($rr['prepared_by_signature']),
        'reviewed' => !empty($rr['reviewed_by_name']) && !empty($rr['reviewed_by_signature']),
        'approved' => !empty($rr['approved_by_name']) && !empty($rr['approved_by_signature']),
    ];

    return [
        'steps' => $steps,
        'done'  => count(array_filter($steps)),
        'total' => 3,
    ];
}

function buildSigArea(string $prefix, bool $canSign, array $allowedRoles, string $stepLabel): string
{
    $rolesText = htmlspecialchars(implode(', ', $allowedRoles), ENT_QUOTES, 'UTF-8');
    $stepLabelEsc = htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8');

    if (!$canSign) {
        return '
        <div class="sig-role-lock">
            <div class="sig-role-lock-icon"><i class="fas fa-lock"></i></div>
            <div class="sig-role-lock-text">
                <div class="sig-role-lock-title">You cannot sign this section</div>
                <div class="sig-role-lock-sub">
                    Only these roles can sign <strong>' . $stepLabelEsc . '</strong>: ' . $rolesText . '
                </div>
            </div>
        </div>';
    }

    return '
    <div class="sig-area">
        <div class="sig-tabs">
            <button type="button" class="sig-tab active" onclick="switchSigTab(\'' . $prefix . '\', \'draw\', this)">
                <i class="fas fa-pen me-1"></i>Draw
            </button>
            <button type="button" class="sig-tab" onclick="switchSigTab(\'' . $prefix . '\', \'upload\', this)">
                <i class="fas fa-upload me-1"></i>Upload
            </button>
        </div>
        <div class="sig-tab-body">
            <div class="sig-pane active" id="' . $prefix . 'DrawPane">
                <div class="sig-canvas-wrap no-border">
                    <canvas id="' . $prefix . 'Canvas" class="sig-canvas" height="120"></canvas>
                    <div class="sig-hint" id="' . $prefix . 'Hint">Sign here...</div>
                </div>
                <div class="sig-actions-row">
                    <button type="button" class="btn btn-sm btn-light border" onclick="clearCanvas(\'' . $prefix . '\')">
                        <i class="fas fa-eraser me-1"></i>Clear
                    </button>
                </div>
            </div>
            <div class="sig-pane" id="' . $prefix . 'UploadPane">
                <div class="sig-dropzone no-border" id="' . $prefix . 'Zone" onclick="document.getElementById(\'' . $prefix . 'File\').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>Click or drag to upload (PNG / JPG)</span>
                </div>
                <input type="file" id="' . $prefix . 'File" accept="image/*" style="display:none" onchange="handleUpload(\'' . $prefix . '\', this)">
                <img id="' . $prefix . 'UploadPreview" class="sig-upload-preview" alt="">
            </div>
        </div>
    </div>';
}
?>

<link rel="stylesheet" href="css/manage-risk-rating.css">

<div class="modal-overlay" id="deleteModal">
    <div class="del-modal">
        <div class="dm-icon"><i class="fas fa-trash-alt"></i></div>
        <h5>Delete Risk Rating?</h5>
        <p>
            Permanently delete the risk rating for<br>
            <span class="dm-name" id="deleteModalName">-</span><br>
            including all sections, checks, scores and files.
            This <strong>cannot be undone</strong>.
        </p>
        <div class="dm-btns">
            <button type="button" class="btn btn-light border" onclick="closeDeleteModal()">
                <i class="fas fa-times me-1"></i> Cancel
            </button>
            <form method="post" action="manage_risk_ratings" id="deleteForm" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="rr_id" id="deleteRrId" value="">
                <input type="hidden" name="search" value="<?= h($search) ?>">
                <input type="hidden" name="status" value="<?= h($filterStatus) ?>">
                <input type="hidden" name="rating" value="<?= h($filterRating) ?>">
                <input type="hidden" name="opportunity_id" value="<?= h($filterOpportunity ?: '') ?>">
                <input type="hidden" name="page" value="<?= h($page) ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt me-1"></i> Yes, Delete
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="wfModal">
    <div class="wf-modal">
        <div class="wf-head">
            <div class="wf-head-top">
                <div>
                    <h4><i class="fas fa-signature me-2" style="color:#ff6b35"></i>Signature Workflow</h4>
                    <div class="wf-sub" id="wfStartupName">-</div>
                </div>
                <button type="button" class="wf-close-btn" onclick="closeWf()">&times;</button>
            </div>

            <div class="wf-step-tabs">
                <div class="wf-step-tab active" id="wfTab0" onclick="goStep(0)">
                    <div class="step-num">1</div>
                    <span class="step-lbl">Prepared By</span>
                </div>
                <div class="wf-step-tab" id="wfTab1" onclick="goStep(1)">
                    <div class="step-num">2</div>
                    <span class="step-lbl">Reviewed By</span>
                </div>
                <div class="wf-step-tab" id="wfTab2" onclick="goStep(2)">
                    <div class="step-num">3</div>
                    <span class="step-lbl">Approved by ED</span>
                </div>
            </div>
        </div>

        <div class="wf-body">
            <div class="wf-pane active" id="wfPane0">
                <div class="wf-sec-label"><i class="fas fa-user-edit"></i> Prepared By</div>
                <div class="sig-saved" id="prepSaved">
                    <img id="prepSavedImg" src="" alt="">
                    <div class="sig-saved-info">
                        <div class="sig-saved-name" id="prepSavedName">-</div>
                        <div class="sig-saved-sub"><i class="fas fa-check-circle me-1"></i>Signature on record</div>
                    </div>
                    <button type="button" class="sig-saved-clear" onclick="clearSaved('prep')">
                        <i class="fas fa-times me-1"></i>Remove
                    </button>
                </div>
                <div class="wf-current-user-box">
                    <div class="wf-current-user-label">Signed by</div>
                    <div class="wf-current-user-name" id="prepCurrentUserName"><?= h($currentUserName) ?></div>
                    <div class="wf-current-user-role"><?= h($currentUserRole) ?></div>
                </div>
                <input type="hidden" id="prepUserId" value="<?= (int)$currentUserId ?>">
                <input type="hidden" id="prepUserName" value="<?= h($currentUserName) ?>">
                <?= buildSigArea('prep', $signaturePermissions['prep']['allowed'], $signaturePermissions['prep']['roles'], $signaturePermissions['prep']['label']) ?>
            </div>

            <div class="wf-pane" id="wfPane1">
                <div class="wf-sec-label"><i class="fas fa-user-check"></i> Reviewed By</div>
                <div class="sig-saved" id="revSaved">
                    <img id="revSavedImg" src="" alt="">
                    <div class="sig-saved-info">
                        <div class="sig-saved-name" id="revSavedName">-</div>
                        <div class="sig-saved-sub"><i class="fas fa-check-circle me-1"></i>Signature on record</div>
                    </div>
                    <button type="button" class="sig-saved-clear" onclick="clearSaved('rev')">
                        <i class="fas fa-times me-1"></i>Remove
                    </button>
                </div>
                <div class="wf-current-user-box">
                    <div class="wf-current-user-label">Signed by</div>
                    <div class="wf-current-user-name" id="revCurrentUserName"><?= h($currentUserName) ?></div>
                    <div class="wf-current-user-role"><?= h($currentUserRole) ?></div>
                </div>
                <input type="hidden" id="revUserId" value="<?= (int)$currentUserId ?>">
                <input type="hidden" id="revUserName" value="<?= h($currentUserName) ?>">
                <?= buildSigArea('rev', $signaturePermissions['rev']['allowed'], $signaturePermissions['rev']['roles'], $signaturePermissions['rev']['label']) ?>

                <div class="wf-sec-label mt-3"><i class="fas fa-comment-alt"></i> Reviewer Comments</div>
                <textarea class="wf-comments" id="reviewerComments" placeholder="Enter reviewer comments..."></textarea>
            </div>

            <div class="wf-pane" id="wfPane2">
                <div class="wf-sec-label"><i class="fas fa-user-shield"></i> Approved by ED</div>
                <div class="sig-saved" id="appSaved">
                    <img id="appSavedImg" src="" alt="">
                    <div class="sig-saved-info">
                        <div class="sig-saved-name" id="appSavedName">-</div>
                        <div class="sig-saved-sub"><i class="fas fa-check-circle me-1"></i>Signature on record</div>
                    </div>
                    <button type="button" class="sig-saved-clear" onclick="clearSaved('app')">
                        <i class="fas fa-times me-1"></i>Remove
                    </button>
                </div>
                <div class="wf-current-user-box">
                    <div class="wf-current-user-label">Signed by</div>
                    <div class="wf-current-user-name" id="appCurrentUserName"><?= h($currentUserName) ?></div>
                    <div class="wf-current-user-role"><?= h($currentUserRole) ?></div>
                </div>
                <input type="hidden" id="appUserId" value="<?= (int)$currentUserId ?>">
                <input type="hidden" id="appUserName" value="<?= h($currentUserName) ?>">
                <?= buildSigArea('app', $signaturePermissions['app']['allowed'], $signaturePermissions['app']['roles'], $signaturePermissions['app']['label']) ?>
            </div>
        </div>

        <div class="wf-footer">
            <div class="wf-status" id="wfStatus"></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-light border" onclick="closeWf()">Close</button>
                <button type="button" class="wf-save-btn" id="wfSaveBtn" onclick="saveWorkflow()">
                    <i class="fas fa-save"></i> Save &amp; Regenerate PDF
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid py-4">
    <div class="mrr-wrap">

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="mrr-page-header">
            <div>
                <h2><i class="fas fa-shield-alt me-2 text-primary"></i>Manage Risk Ratings</h2>
                <div class="sub">Due diligence risk assessments for all startups</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="risk_ratings_report" class="btn btn-success">
                    <i class="fas fa-file-pdf me-1"></i> Generate Risk Report
                </a>
                <a href="startups-shortlisting" class="btn btn-light border">
                    <i class="fas fa-arrow-left me-1"></i> Back to Shortlisting
                </a>
            </div>
        </div>

        <div class="stats-grid reports-stats">
            <a href="risk_ratings_report" class="stat-card-link" title="View full Risk Ratings Report">
                <div class="stat-card">
                    <div class="stat-icon bg-blue"><i class="fas fa-shield-halved" aria-hidden="true"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Total <i class="fas fa-external-link-alt link-icon" aria-hidden="true"></i></span>
                        <span class="stat-value"><?= number_format((int)($stats['total'] ?? 0)) ?></span>
                    </div>
                </div>
            </a>
            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Generated</span>
                    <span class="stat-value"><?= number_format((int)($stats['generated'] ?? 0)) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-slate"><i class="fas fa-file-pen" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Draft</span>
                    <span class="stat-value"><?= number_format((int)($stats['draft'] ?? 0)) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-teal"><i class="fas fa-signal" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Minor</span>
                    <span class="stat-value"><?= number_format((int)($stats['minor'] ?? 0)) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-blue"><i class="fas fa-gauge" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Moderate</span>
                    <span class="stat-value"><?= number_format((int)($stats['moderate'] ?? 0)) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-amber"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Major</span>
                    <span class="stat-value"><?= number_format((int)($stats['major'] ?? 0)) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-red"><i class="fas fa-radiation" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Severe</span>
                    <span class="stat-value"><?= number_format((int)($stats['severe'] ?? 0)) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-purple"><i class="fas fa-file-pdf" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">PDFs</span>
                    <span class="stat-value"><?= number_format((int)($stats['has_pdf'] ?? 0)) ?></span>
                </div>
            </div>
        </div>

        <div class="mrr-filters">
            <form method="get" action="manage_risk_ratings" class="filters-bar" role="search" aria-label="Filter risk ratings">
                <div class="fg form-group filters-grow">
                    <label>Search</label>
                    <input type="text" name="search" class="fg-search" placeholder="Startup, email, contact..." value="<?= h($search) ?>">
                </div>

                <div class="fg form-group">
                    <label>Opportunity</label>
                    <select name="opportunity_id" class="fg-select">
                        <option value="">All Opportunities</option>
                        <?php foreach ($opportunities as $opp): ?>
                            <option value="<?= (int)$opp['opportunity_id'] ?>" <?= ($filterOpportunity === (int)$opp['opportunity_id']) ? 'selected' : '' ?>>
                                <?= h($opp['opportunity_title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fg form-group">
                    <label>Risk Level</label>
                    <select name="rating" class="fg-select">
                        <option value="">All Levels</option>
                        <?php foreach (['Minor', 'Moderate', 'Major', 'Severe'] as $r): ?>
                            <option value="<?= h($r) ?>" <?= ($filterRating === $r) ? 'selected' : '' ?>><?= h($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fg form-group">
                    <label>Status</label>
                    <select name="status" class="fg-select">
                        <option value="">All Statuses</option>
                        <option value="draft" <?= ($filterStatus === 'draft') ? 'selected' : '' ?>>Draft</option>
                        <option value="generated" <?= ($filterStatus === 'generated') ? 'selected' : '' ?>>Generated</option>
                    </select>
                </div>

                <div class="fg">
                    <label>&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="height:38px;border-radius:10px;">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                        <a href="manage_risk_ratings" class="btn btn-light border" style="height:38px;border-radius:10px;display:inline-flex;align-items:center;">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <div class="mrr-card">
            <div class="mrr-card-head">
                <div class="d-flex align-items-center gap-3">
                    <span class="ctitle"><i class="fas fa-list me-1"></i> All Risk Ratings</span>
                    <span class="count-pill"><?= number_format($totalRows) ?> record<?= $totalRows !== 1 ? 's' : '' ?></span>
                </div>
                <?php if ($search !== '' || $filterStatus !== '' || $filterRating !== '' || $filterOpportunity > 0): ?>
                    <a href="manage_risk_ratings" class="btn btn-sm btn-light border">
                        <i class="fas fa-filter me-1"></i> Filtered - Clear
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($ratings)): ?>
                <div class="mrr-empty">
                    <i class="fas fa-shield-alt"></i>
                    <div class="em">No risk ratings found</div>
                    <div class="esub">
                        <?= ($search !== '' || $filterStatus !== '' || $filterRating !== '' || $filterOpportunity > 0)
                            ? 'Try adjusting your filters'
                            : 'Risk ratings will appear here once created from the shortlisting page' ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="mrr-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Startup</th>
                                <th>Opportunity</th>
                                <th>Risk Score</th>
                                <th>Recommendation</th>
                                <th>Status</th>
                                <th>Files</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ratings as $i => $rr): ?>
                                <?php
                                $label    = (string)($rr['overall_rating_label'] ?? 'Minor');
                                $score    = (float)($rr['overall_rating'] ?? 1);
                                $rec      = (string)($rr['recommendation'] ?? '');
                                $status   = (string)($rr['status'] ?? 'draft');
                                $hasPdf   = !empty($rr['generated_pdf_path']);
                                $hasExcel = !empty($rr['generated_excel_path']);
                                $rowNum   = $offset + $i + 1;
                                $sig      = sigStatus($rr);

                                $wfPayload = json_encode([
                                    'rrId'        => (int)$rr['rr_id'],
                                    'appId'       => (int)$rr['application_id'],
                                    'startupName' => $rr['startup_name'] ?? '',
                                    'prepSig'     => ims_upload_url($rr['prepared_by_signature'] ?? ''),
                                    'revSig'      => ims_upload_url($rr['reviewed_by_signature'] ?? ''),
                                    'appSig'      => ims_upload_url($rr['approved_by_signature'] ?? ''),
                                    'revComments' => $rr['reviewer_comments'] ?? '',
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                ?>
                                <tr>
                                    <td style="color:#9ca3af;font-size:12px"><?= $rowNum ?></td>

                                    <td>
                                        <div class="sn-name"><?= h($rr['startup_name'] ?: '-') ?></div>
                                        <div class="sn-meta">
                                            <?= h($rr['founder_names'] ?: ($rr['contact_person'] ?? '')) ?>
                                            <?php if (!empty($rr['email'])): ?>
                                                &middot; <?= h($rr['email']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td style="font-size:12px;color:#6b7280;max-width:150px">
                                        <?= h($rr['opportunity_title'] ?: '-') ?>
                                    </td>

                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="score-circle <?= h(scoreCircleClass($label)) ?>">
                                                <?= number_format($score, 1) ?>
                                            </span>
                                            <span class="risk-badge <?= h(ratingBadgeClass($label)) ?>">
                                                <?= h($label) ?>
                                            </span>
                                        </div>
                                    </td>

                                    <td>
                                        <?php if ($rec !== ''): ?>
                                            <span class="rec-badge <?= h(recClass($rec)) ?>"><?= h($rec) ?></span>
                                        <?php else: ?>
                                            <span style="color:#d1d5db;font-size:12px">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="status-badge <?= $status === 'generated' ? 'sb-generated' : 'sb-draft' ?>">
                                            <i class="fas <?= $status === 'generated' ? 'fa-check-circle' : 'fa-pencil-alt' ?>" style="font-size:10px"></i>
                                            <?= ucfirst(h($status)) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="file-btns">
                                            <?php if ($hasPdf): ?>
                                                <a href="<?= h(ims_upload_url($rr['generated_pdf_path'])) ?>" target="_blank" class="fbtn fbtn-pdf" title="<?= h($rr['generated_pdf_name'] ?? '') ?>">
                                                    <i class="fas fa-file-pdf"></i> View
                                                </a>
                                            <?php else: ?>
                                                <span class="fbtn fbtn-none"><i class="fas fa-file-pdf"></i> No PDF</span>
                                            <?php endif; ?>

                                            <?php if ($hasExcel): ?>
                                                <a href="<?= h(ims_upload_url($rr['generated_excel_path'])) ?>" target="_blank" class="fbtn fbtn-excel" title="<?= h($rr['generated_excel_name'] ?? '') ?>">
                                                    <i class="fas fa-file-excel"></i> Excel
                                                </a>
                                            <?php else: ?>
                                                <span class="fbtn fbtn-none"><i class="fas fa-file-excel"></i> No Excel</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td style="white-space:nowrap">
                                        <div style="font-size:12px;color:#374151">
                                            <?= h(date('d M Y', strtotime($rr['updated_at'] ?? 'now'))) ?>
                                        </div>
                                        <div style="font-size:11px;color:#9ca3af">
                                            <?= h(date('H:i', strtotime($rr['updated_at'] ?? 'now'))) ?>
                                            <?php $pn = trim((string)($rr['prepared_by_full_name'] ?? '')); ?>
                                            <?php if ($pn !== ''): ?>
                                                &middot; <?= h($pn) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="action-btns">
                                            <a href="risk_rating?application_id=<?= (int)$rr['application_id'] ?>" class="abtn abtn-edit" title="Edit Risk Rating">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <?php if ($hasPdf): ?>
                                                <button type="button" class="abtn abtn-workflow" onclick='openWf(<?= $wfPayload ?>)' title="Signature Workflow">
                                                    <i class="fas fa-signature"></i>
                                                    <?php if ($sig['done'] > 0): ?>
                                                        <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:1px 6px;font-size:10px">
                                                            <?= $sig['done'] ?>/3
                                                        </span>
                                                    <?php endif; ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="abtn abtn-disabled" title="Generate PDF first to enable workflow">
                                                    <i class="fas fa-signature"></i>
                                                </span>
                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="abtn abtn-delete"
                                                onclick="confirmDelete(<?= (int)$rr['rr_id'] ?>, '<?= addslashes(strip_tags((string)($rr['startup_name'] ?? ''))) ?>')"
                                                title="Delete this risk rating">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>

                                        <?php if ($hasPdf): ?>
                                            <div class="sig-progress" title="Prepared / Reviewed / Approved">
                                                <span class="sig-pip <?= $sig['steps']['prepared'] ? 'done' : '' ?>"></span>
                                                <span class="sig-pip <?= $sig['steps']['reviewed'] ? 'done' : '' ?>"></span>
                                                <span class="sig-pip <?= $sig['steps']['approved'] ? 'done' : '' ?>"></span>
                                                <span class="sig-prog-label"><?= $sig['done'] ?>/3 signed</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mrr-pagination">
                    <div class="pi">
                        Showing <?= number_format($totalRows > 0 ? $offset + 1 : 0) ?>-<?= number_format(min($offset + $perPage, $totalRows)) ?>
                        of <?= number_format($totalRows) ?> records
                    </div>
                    <div class="pag-links">
                        <?php if ($page > 1): ?>
                            <a href="?<?= buildQS(['page' => 1]) ?>"><i class="fas fa-angle-double-left"></i></a>
                            <a href="?<?= buildQS(['page' => $page - 1]) ?>"><i class="fas fa-angle-left"></i></a>
                        <?php else: ?>
                            <span class="dis"><i class="fas fa-angle-double-left"></i></span>
                            <span class="dis"><i class="fas fa-angle-left"></i></span>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="active"><?= $p ?></span>
                            <?php else: ?>
                                <a href="?<?= buildQS(['page' => $p]) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= buildQS(['page' => $page + 1]) ?>"><i class="fas fa-angle-right"></i></a>
                            <a href="?<?= buildQS(['page' => $totalPages]) ?>"><i class="fas fa-angle-double-right"></i></a>
                        <?php else: ?>
                            <span class="dis"><i class="fas fa-angle-right"></i></span>
                            <span class="dis"><i class="fas fa-angle-double-right"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.LOGGED_IN_USER = <?= json_encode([
    'id'   => $currentUserId,
    'name' => $currentUserName,
    'role' => $currentUserRole,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

window.SIGNATURE_PERMISSIONS = <?= json_encode($signaturePermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="js/manage-risk-rating.js"></script>

<?php require_once 'includes/footer.php'; ?>