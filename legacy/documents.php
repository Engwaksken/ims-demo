<?php
declare(strict_types=1);

$page_title = 'Documents Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/documents-process.php';
require_once __DIR__ . '/includes/auth.php';
check_role(IMS_ALL_ROLES);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$isAdmin = ims_allowed('documents.edit_all', ['Administrator'], 'Edit, move and delete any document or folder');

$currentFolderId = max(0, (int)($_GET['folder'] ?? 0));
$search = trim((string)($_GET['search'] ?? ''));
$filterProject = max(0, (int)($_GET['project'] ?? 0));
$filterProgram = max(0, (int)($_GET['program'] ?? 0));

$currentFolder = $currentFolderId > 0
    ? doc_get_folder($conn, $currentFolderId, $userId, $userRole)
    : null;

if ($currentFolderId > 0 && !$currentFolder) {
    $_SESSION['error'] = 'Folder not found or you do not have permission to view it.';
    header('Location: documents');
    exit;
}

$folders = doc_get_folders(
    $conn,
    $currentFolderId,
    $userId,
    $userRole,
    $search,
    $filterProject,
    $filterProgram
);

$documents = doc_get_documents(
    $conn,
    $currentFolderId,
    $userId,
    $userRole,
    $search,
    $filterProject,
    $filterProgram
);

$projects = getProjects($conn);
$programs = getPrograms($conn);
$users = doc_get_active_users($conn);

$folderPath = doc_get_breadcrumbs(
    $conn,
    $currentFolderId,
    $userId,
    $userRole
);

$stats = doc_repository_stats($conn, $userId, $userRole);

$folderTypes = [
    'Inception Report',
    'Quarterly Report',
    'Annual Report',
    'End Report',
    'Policy',
    'Image',
    'Video',
    'Other',
];

$canCreateHere = $currentFolder
    ? doc_can($conn, 'folder', $currentFolderId, $userId, $userRole, 'edit')
    : true;

$autoOpenUpload = (
    $currentFolderId > 0
    &&
    (string)($_GET['upload'] ?? '') === '1'
    &&
    $canCreateHere
);
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
.doc-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    margin-bottom:16px
}
.doc-toolbar-left,.doc-toolbar-right{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap
}
.doc-breadcrumbs{
    display:flex;align-items:center;gap:6px;flex-wrap:wrap;
    margin-bottom:14px;font-size:12px;color:var(--ink-300)
}
.doc-breadcrumbs a{
    color:var(--brand-600);font-weight:700;text-decoration:none
}
.doc-breadcrumbs i{font-size:9px;color:var(--ink-200)}

.doc-repo-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px
}
.doc-list-card{
    min-width:0;
    display:flex;align-items:center;gap:12px;
    padding:13px 14px;
    border:1px solid var(--ink-100);
    border-radius:12px;
    background:var(--surface-card);
    box-shadow:0 2px 8px rgba(15,23,42,.04);
    transition:.18s ease
}
.doc-list-card:hover{
    transform:translateY(-1px);
    border-color:var(--brand-200);
    box-shadow:0 6px 16px rgba(15,23,42,.07)
}
.doc-folder-card{cursor:pointer}
.doc-card-icon{
    width:44px;height:44px;display:grid;place-items:center;
    flex-shrink:0;border-radius:10px;font-size:19px
}
.doc-folder-icon{background:#fff7ed;color:#ea580c}
.doc-card-main{min-width:0;flex:1}
.doc-card-name{
    color:var(--ink-700);font-size:12.5px;font-weight:800;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis
}
.doc-card-meta{
    margin-top:4px;color:var(--ink-300);font-size:10.5px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis
}
.doc-card-actions{
    display:flex;align-items:center;gap:5px;flex-shrink:0
}
.doc-perm-badges{
    display:flex;gap:4px;flex-wrap:wrap;margin-top:6px
}
.doc-perm-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 7px;border-radius:999px;
    background:var(--ink-50);color:var(--ink-400);
    font-size:9px;font-weight:800
}
.doc-folder-count{
    display:inline-flex;align-items:center;gap:4px;
    margin-top:5px;color:#64748b;font-size:10px;font-weight:700
}
.doc-empty{
    grid-column:1/-1;text-align:center;padding:55px 20px;color:var(--ink-300)
}
.doc-empty i{font-size:34px;opacity:.45;margin-bottom:10px}
/* Filter row: layout from .filters-bar (style.css) */
.doc-filter-row{margin-bottom:16px}
.doc-filter-search{position:relative}
.doc-filter-search i{
    position:absolute;left:11px;top:50%;transform:translateY(-50%);
    font-size:11px;color:var(--ink-200)
}
.doc-filter-search input{padding-left:30px}
.doc-tabs{
    display:flex;gap:0;overflow-x:auto;margin-bottom:16px;border-bottom:2px solid var(--ink-100)
}
.doc-tab-btn{
    border:0;background:transparent;padding:10px 14px;margin-bottom:-2px;
    border-bottom:2px solid transparent;font-size:12px;font-weight:800;
    color:var(--ink-300);cursor:pointer;white-space:nowrap
}
.doc-tab-btn.active{color:var(--brand-600);border-bottom-color:var(--brand-500)}
.doc-tab-panel{display:none}.doc-tab-panel.active{display:block}
.doc-rights-grid{
    display:grid;grid-template-columns:minmax(160px,1fr) repeat(3,90px);
    gap:8px;align-items:center
}
.doc-rights-head{
    font-size:10px;font-weight:800;color:var(--ink-300);text-transform:uppercase
}
.doc-rights-row{display:contents}
.doc-rights-name{font-size:12px;font-weight:700;color:var(--ink-600)}
.doc-check{text-align:center}
.doc-check input{width:17px;height:17px}
.doc-type-other{display:none;margin-top:8px}
.doc-share-note{
    margin-top:10px;padding:10px 12px;border:1px solid var(--blue-border);
    border-radius:8px;background:var(--blue-bg);color:var(--blue-fg);
    font-size:11px;line-height:1.5
}
@media(max-width:1100px){
    .doc-repo-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:700px){
    .doc-repo-grid{grid-template-columns:1fr}
    .doc-rights-grid{grid-template-columns:minmax(120px,1fr) repeat(3,65px)}
}

.doc-confirm-modal .modal-card {
    max-width: 470px;
}

.doc-confirm-icon {
    width: 58px;
    height: 58px;

    display: grid;
    place-items: center;

    margin: 0 auto 12px;

    border-radius: 50%;

    background: var(--red-bg);
    color: var(--red-fg);

    font-size: 24px;
}

.doc-confirm-title {
    margin-bottom: 5px;

    color: var(--ink-700);

    font-size: 16px;
    font-weight: 800;

    text-align: center;
}

.doc-confirm-message {
    color: var(--ink-300);

    font-size: 12px;
    line-height: 1.6;

    text-align: center;
}

.doc-edit-summary {
    display: flex;
    align-items: center;
    gap: 10px;

    margin-bottom: 14px;
    padding: 11px 12px;

    border: 1px solid var(--ink-100);
    border-radius: 9px;

    background: var(--ink-50);
}

.doc-edit-summary i {
    color: var(--brand-500);

    font-size: 20px;
}

.doc-edit-summary strong {
    display: block;

    color: var(--ink-700);

    font-size: 12px;
}

.doc-edit-summary small {
    color: var(--ink-300);

    font-size: 10.5px;
}


/* ==========================================================================
   DRIVE / ONEDRIVE STYLE NEW MENU
   ========================================================================== */

.doc-new-wrap {
    position: relative;
}

.doc-new-menu {
    display: none;

    position: absolute;
    top: calc(100% + 7px);
    right: 0;

    z-index: 1000;

    width: 220px;

    overflow: hidden;

    border: 1px solid var(--ink-100);
    border-radius: 11px;

    background: var(--surface-card);

    box-shadow: 0 15px 35px rgba(15,23,42,.14);
}

.doc-new-menu.open {
    display: block;
}

.doc-new-menu-head {
    padding: 9px 12px;

    border-bottom: 1px solid var(--ink-50);

    color: var(--ink-300);

    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.doc-new-action {
    width: 100%;

    display: flex;
    align-items: center;
    gap: 10px;

    padding: 11px 12px;

    border: 0;

    background: transparent;
    color: var(--ink-600);

    font-family: inherit;
    font-size: 12px;
    font-weight: 700;

    text-align: left;

    cursor: pointer;
}

.doc-new-action:hover {
    background: var(--ink-50);
    color: var(--brand-600);
}

.doc-new-action-icon {
    width: 30px;
    height: 30px;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: var(--brand-50);
    color: var(--brand-500);

    font-size: 13px;
}

.doc-library-section {
    margin-bottom: 22px;
}

.doc-library-head {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 10px;

    margin-bottom: 10px;
}

.doc-library-title {
    display: flex;
    align-items: center;
    gap: 8px;

    margin: 0;

    color: var(--ink-600);

    font-size: 12px;
    font-weight: 800;
}

.doc-library-title i {
    color: var(--brand-500);
}

.doc-library-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 26px;
    height: 24px;

    padding: 0 7px;

    border-radius: 999px;

    background: var(--ink-50);
    color: var(--ink-300);

    font-size: 10px;
    font-weight: 800;
}

.doc-current-folder-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    margin-bottom: 14px;
    padding: 11px 13px;

    border: 1px solid #fed7aa;
    border-left: 4px solid #f97316;
    border-radius: 9px;

    background: #fff7ed;
}

.doc-current-folder-main {
    display: flex;
    align-items: center;
    gap: 9px;

    min-width: 0;
}

.doc-current-folder-main i {
    color: #ea580c;
    font-size: 18px;
}

.doc-current-folder-name {
    min-width: 0;

    color: #9a3412;

    font-size: 12px;
    font-weight: 800;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.doc-current-folder-note {
    margin-top: 2px;

    color: #c2410c;

    font-size: 10px;
}

.doc-section-empty {
    padding: 24px 16px;

    border: 1px dashed var(--ink-100);
    border-radius: 10px;

    color: var(--ink-300);

    font-size: 11px;
    text-align: center;
}


/* ==========================================================================
   ADD NEW CHOOSER MODAL
   ========================================================================== */

.doc-add-new-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.doc-add-new-option {
    width: 100%;

    display: flex;
    align-items: center;
    gap: 12px;

    padding: 16px;

    border: 1px solid var(--ink-100);
    border-radius: 11px;

    background: var(--surface-card);
    color: var(--ink-600);

    font-family: inherit;
    text-align: left;

    cursor: pointer;

    transition:
        border-color .18s ease,
        background .18s ease,
        transform .18s ease,
        box-shadow .18s ease;
}

.doc-add-new-option:hover {
    transform: translateY(-1px);

    border-color: var(--brand-300);

    background: var(--brand-50);

    box-shadow: 0 6px 14px rgba(15,23,42,.07);
}

.doc-add-new-option-icon {
    width: 46px;
    height: 46px;

    display: grid;
    place-items: center;

    flex-shrink: 0;

    border-radius: 10px;

    background: #fff7ed;
    color: #ea580c;

    font-size: 18px;
}

.doc-add-new-option.file .doc-add-new-option-icon {
    background: #eff6ff;
    color: #2563eb;
}

.doc-add-new-option-title {
    color: var(--ink-700);

    font-size: 12.5px;
    font-weight: 800;
}

.doc-add-new-option-text {
    margin-top: 3px;

    color: var(--ink-300);

    font-size: 10.5px;
    line-height: 1.45;
}

.doc-add-location {
    display: flex;
    align-items: flex-start;
    gap: 9px;

    margin-bottom: 14px;
    padding: 10px 12px;

    border: 1px solid #fed7aa;
    border-left: 4px solid #f97316;
    border-radius: 8px;

    background: #fff7ed;
    color: #9a3412;

    font-size: 11px;
    line-height: 1.45;
}

.doc-add-location i {
    margin-top: 2px;
    color: #ea580c;
}

@media (max-width: 620px) {
    .doc-add-new-grid {
        grid-template-columns: 1fr;
    }
}


/* ==========================================================================
   ONEDRIVE-STYLE LIBRARY
   ========================================================================== */

.doc-drive-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.doc-drive-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.doc-drive-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.doc-drive-item {
    position: relative;
    min-width: 0;
    min-height: 108px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    border: 1px solid var(--ink-100);
    border-radius: 10px;
    background: var(--surface-card);
    box-shadow: 0 1px 4px rgba(15,23,42,.04);
    cursor: default;
    user-select: none;
    transition: .15s ease;
}

.doc-drive-item:hover,
.doc-drive-item.selected {
    border-color: #fdba74;
    background: #fffaf5;
    box-shadow: 0 5px 14px rgba(15,23,42,.07);
}

.doc-drive-item.folder {
    cursor: pointer;
}

.doc-drive-icon {
    width: 48px;
    height: 48px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 9px;
    font-size: 21px;
}

.doc-drive-item.folder .doc-drive-icon {
    background: #fff7ed;
    color: #ea580c;
}

.doc-drive-main {
    flex: 1;
    min-width: 0;
}

.doc-drive-name {
    color: var(--ink-700);
    font-size: 12.5px;
    font-weight: 800;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.doc-drive-meta {
    margin-top: 5px;
    color: var(--ink-300);
    font-size: 10.5px;
    line-height: 1.4;
}

.doc-drive-more {
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: var(--ink-300);
    cursor: pointer;
}

.doc-drive-more:hover {
    background: var(--ink-50);
    color: var(--ink-600);
}

.doc-context-menu {
    display: none;
    position: fixed;
    z-index: 100000;
    width: 205px;
    overflow: hidden;
    border: 1px solid var(--ink-100);
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 16px 40px rgba(15,23,42,.20);
}

.doc-context-menu.open {
    display: block;
}

.doc-context-item {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 12px;
    border: 0;
    background: transparent;
    color: var(--ink-600);
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 700;
    text-align: left;
    cursor: pointer;
}

.doc-context-item:hover {
    background: var(--ink-50);
}

.doc-context-item.danger {
    color: #b91c1c;
}

.doc-context-sep {
    height: 1px;
    background: var(--ink-50);
}

.doc-upload-simple {
    padding: 12px;
    border: 1px dashed var(--ink-100);
    border-radius: 9px;
    background: var(--ink-50);
}

.doc-upload-choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 10px;
}

.doc-upload-choice {
    min-height: 115px;
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 14px;
    border: 1px solid var(--ink-100);
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
}

.doc-upload-choice:hover {
    border-color: var(--brand-300);
    background: var(--brand-50);
}

.doc-upload-choice i {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 9px;
    background: #eff6ff;
    color: #2563eb;
    font-size: 18px;
}

.doc-upload-choice.folder i {
    background: #fff7ed;
    color: #ea580c;
}

.doc-hidden-file-input {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
}

.doc-folder-upload-summary {
    margin-top: 8px;
    color: var(--ink-300);
    font-size: 10.5px;
}

@media (max-width: 1200px) {
    .doc-drive-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {
    .doc-drive-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {
    .doc-drive-grid,
    .doc-upload-choice-grid {
        grid-template-columns: 1fr;
    }
}

.doc-move-option {
    white-space: pre;
}


/* ==========================================================================
   DRAG AND DROP
   ========================================================================== */

.doc-drive-item[draggable="true"] {
    cursor: grab;
}

.doc-drive-item.dragging {
    opacity: .45;
}

.doc-drive-item.folder.doc-drop-target-active,
.doc-breadcrumbs a.doc-drop-target-active {
    outline: 2px dashed var(--brand-500);
    outline-offset: 2px;
    background: var(--brand-50);
    border-radius: 8px;
}

.doc-drop-overlay {
    display: none;

    position: fixed;
    inset: 0;

    z-index: 999998;

    align-items: center;
    justify-content: center;

    background: rgba(37, 99, 235, .10);
    border: 3px dashed #2563eb;

    pointer-events: none;
}

.doc-drop-overlay.active {
    display: flex;
}

.doc-drop-overlay-card {
    display: flex;
    align-items: center;
    gap: 10px;

    padding: 16px 24px;

    border-radius: 14px;

    background: #fff;
    color: #1d4ed8;

    font-size: 14px;
    font-weight: 800;

    box-shadow: 0 12px 30px rgba(15,23,42,.18);
}

.doc-drop-overlay-card i {
    font-size: 20px;
}

.doc-upload-progress-toast {
    display: none;

    position: fixed;
    right: 20px;
    bottom: 20px;

    z-index: 999998;

    align-items: center;
    gap: 10px;

    padding: 12px 16px;

    border-radius: 12px;

    background: #1f2937;
    color: #fff;

    font-size: 12px;
    font-weight: 700;

    box-shadow: 0 12px 30px rgba(15,23,42,.25);
}

.doc-upload-progress-toast.active {
    display: flex;
}

.doc-upload-progress-toast i {
    color: #93c5fd;
}


/* ==========================================================================
   DOCUMENT MODAL DISPLAY FIX
   Keeps modal header/body/footer inside one centred card.
   ========================================================================== */

#addNewModal,
#createFolderModal,
#editFolderModal,
#deleteFolderModal,
#uploadModal,
#uploadFolderModal,
#editFileModal,
#deleteDocumentModal,
#permissionsModal,
#moveModal {
    position: fixed !important;
    inset: 0 !important;

    z-index: 999999 !important;

    width: 100vw !important;
    height: 100vh !important;

    padding: 24px !important;

    overflow-y: auto !important;

    background: rgba(15, 23, 42, .58) !important;

    box-sizing: border-box !important;
}

#addNewModal.open,
#createFolderModal.open,
#editFolderModal.open,
#deleteFolderModal.open,
#uploadModal.open,
#uploadFolderModal.open,
#editFileModal.open,
#deleteDocumentModal.open,
#permissionsModal.open,
#moveModal.open {
    display: flex !important;

    align-items: center !important;
    justify-content: center !important;
}

#addNewModal .modal-card,
#createFolderModal .modal-card,
#editFolderModal .modal-card,
#deleteFolderModal .modal-card,
#uploadModal .modal-card,
#uploadFolderModal .modal-card,
#editFileModal .modal-card,
#deleteDocumentModal .modal-card,
#permissionsModal .modal-card,
#moveModal .modal-card {
    position: relative !important;

    width: min(100%, 760px) !important;
    max-height: calc(100vh - 48px) !important;

    margin: auto !important;

    display: flex !important;
    flex-direction: column !important;

    overflow: hidden !important;

    border-radius: 16px !important;

    background: #fff !important;

    box-shadow: 0 24px 70px rgba(15, 23, 42, .28) !important;

    transform: none !important;
}

#addNewModal .modal-card {
    width: min(100%, 720px) !important;
}

#createFolderModal .modal-card {
    width: min(100%, 680px) !important;
}

#uploadModal .modal-card,
#uploadFolderModal .modal-card,
#editFileModal .modal-card {
    width: min(100%, 720px) !important;
}

#permissionsModal .modal-card {
    width: min(100%, 820px) !important;
}

#deleteDocumentModal .modal-card {
    width: min(100%, 470px) !important;
}

#moveModal .modal-card {
    width: min(100%, 560px) !important;
}

#addNewModal .modal-head,
#createFolderModal .modal-head,
#editFolderModal .modal-head,
#deleteFolderModal .modal-head,
#uploadModal .modal-head,
#uploadFolderModal .modal-head,
#editFileModal .modal-head,
#deleteDocumentModal .modal-head,
#permissionsModal .modal-head,
#moveModal .modal-head {
    flex: 0 0 auto !important;

    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;

    gap: 10px !important;

    padding: 17px 20px !important;

    border-bottom: 1px solid #e5e7eb !important;

    background: #fff !important;
}

#addNewModal .modal-body,
#createFolderModal .modal-body,
#editFolderModal .modal-body,
#deleteFolderModal .modal-body,
#uploadModal .modal-body,
#uploadFolderModal .modal-body,
#editFileModal .modal-body,
#deleteDocumentModal .modal-body,
#permissionsModal .modal-body,
#moveModal .modal-body {
    flex: 1 1 auto !important;

    min-height: 0 !important;

    padding: 20px !important;

    overflow-y: auto !important;

    background: #fff !important;
}

#addNewModal .modal-foot,
#createFolderModal .modal-foot,
#editFolderModal .modal-foot,
#deleteFolderModal .modal-foot,
#uploadModal .modal-foot,
#uploadFolderModal .modal-foot,
#editFileModal .modal-foot,
#deleteDocumentModal .modal-foot,
#permissionsModal .modal-foot,
#moveModal .modal-foot {
    flex: 0 0 auto !important;

    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;

    gap: 8px !important;

    padding: 14px 20px !important;

    border-top: 1px solid #e5e7eb !important;

    background: #f8fafc !important;
}

#addNewModal .modal-head h3,
#createFolderModal .modal-head h3,
#editFolderModal .modal-head h3,
#deleteFolderModal .modal-head h3,
#uploadModal .modal-head h3,
#uploadFolderModal .modal-head h3,
#editFileModal .modal-head h3,
#deleteDocumentModal .modal-head h3,
#permissionsModal .modal-head h3,
#moveModal .modal-head h3 {
    margin: 0 !important;

    display: flex !important;
    align-items: center !important;

    gap: 8px !important;

    color: #1f2937 !important;

    font-size: 17px !important;
    font-weight: 800 !important;
}

#addNewModal .modal-close,
#createFolderModal .modal-close,
#editFolderModal .modal-close,
#deleteFolderModal .modal-close,
#uploadModal .modal-close,
#uploadFolderModal .modal-close,
#editFileModal .modal-close,
#deleteDocumentModal .modal-close,
#permissionsModal .modal-close,
#moveModal .modal-close {
    width: 38px !important;
    height: 38px !important;

    display: grid !important;
    place-items: center !important;

    flex-shrink: 0 !important;

    padding: 0 !important;

    border: 0 !important;
    border-radius: 9px !important;

    background: #f3f4f6 !important;
    color: #64748b !important;

    font-size: 20px !important;
    line-height: 1 !important;

    cursor: pointer !important;
}

#addNewModal .modal-close:hover,
#createFolderModal .modal-close:hover,
#editFolderModal .modal-close:hover,
#deleteFolderModal .modal-close:hover,
#uploadModal .modal-close:hover,
#uploadFolderModal .modal-close:hover,
#editFileModal .modal-close:hover,
#deleteDocumentModal .modal-close:hover,
#permissionsModal .modal-close:hover,
#moveModal .modal-close:hover {
    background: #e5e7eb !important;
    color: #334155 !important;
}

#addNewModal .doc-add-new-grid {
    align-items: stretch !important;
}

#addNewModal .doc-add-new-option {
    min-height: 105px !important;
}

@media (max-width: 700px) {
    #addNewModal,
    #createFolderModal,
    #uploadModal,
    #uploadFolderModal,
    #editFileModal,
    #deleteDocumentModal,
    #permissionsModal,
    #moveModal {
        padding: 12px !important;
    }

    #addNewModal.open,
    #createFolderModal.open,
    #uploadModal.open,
    #uploadFolderModal.open,
    #editFileModal.open,
    #deleteDocumentModal.open,
    #permissionsModal.open,
    #moveModal.open {
        align-items: flex-start !important;
    }

    #addNewModal .modal-card,
    #createFolderModal .modal-card,
    #uploadModal .modal-card,
    #uploadFolderModal .modal-card,
    #editFileModal .modal-card,
    #deleteDocumentModal .modal-card,
    #permissionsModal .modal-card,
    #moveModal .modal-card {
        max-height: calc(100vh - 24px) !important;

        border-radius: 12px !important;
    }

    #addNewModal .modal-head,
    #createFolderModal .modal-head,
    #uploadModal .modal-head,
    #uploadFolderModal .modal-head,
    #editFileModal .modal-head,
    #deleteDocumentModal .modal-head,
    #permissionsModal .modal-head,
    #moveModal .modal-head {
        padding: 14px 15px !important;
    }

    #addNewModal .modal-body,
    #createFolderModal .modal-body,
    #uploadModal .modal-body,
    #uploadFolderModal .modal-body,
    #editFileModal .modal-body,
    #deleteDocumentModal .modal-body,
    #permissionsModal .modal-body,
    #moveModal .modal-body {
        padding: 15px !important;
    }

    #addNewModal .modal-foot,
    #createFolderModal .modal-foot,
    #uploadModal .modal-foot,
    #uploadFolderModal .modal-foot,
    #editFileModal .modal-foot,
    #deleteDocumentModal .modal-foot,
    #permissionsModal .modal-foot,
    #moveModal .modal-foot {
        padding: 12px 15px !important;

        flex-wrap: wrap !important;
    }
}


/* Upload modal form containment */
#uploadModal .modal-card > form {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
}

#uploadModal .modal-card > form > .modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
}

#uploadModal .modal-card > form > .modal-foot {
    flex: 0 0 auto !important;
    width: 100% !important;
    box-sizing: border-box !important;
    position: static !important;
    left: auto !important;
    right: auto !important;
    bottom: auto !important;
}

</style>

<div class="opp-hero" style="margin-bottom:18px;">
    <div class="opp-hero-left">
        <div class="opp-eyebrow"><span class="opp-dot"></span> Repository</div>
        <h1>Documents</h1>
        <p>Organise documents in folders, share access and manage file permissions.</p>
    </div>

    <div class="opp-hero-actions" style="position:relative;">
        <?php if ($canCreateHere): ?>
            <button
                type="button"
                class="btn btn-white"
                onclick="openModal('addNewModal')"
            >
                <i class="fas fa-plus"></i>
                New
            </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="opp-stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px;">
    <div class="opp-stat-card">
        <div class="opp-stat-icon brand"><i class="fas fa-folder"></i></div>
        <div class="opp-stat-info">
            <span class="opp-stat-label">Folders</span>
            <span class="opp-stat-value"><?= (int)$stats['folders'] ?></span>
        </div>
    </div>

    <div class="opp-stat-card">
        <div class="opp-stat-icon" style="background:var(--blue-bg);color:var(--blue-fg);"><i class="fas fa-file"></i></div>
        <div class="opp-stat-info">
            <span class="opp-stat-label">Files</span>
            <span class="opp-stat-value"><?= (int)$stats['files'] ?></span>
        </div>
    </div>

    <div class="opp-stat-card">
        <div class="opp-stat-icon green"><i class="fas fa-share-nodes"></i></div>
        <div class="opp-stat-info">
            <span class="opp-stat-label">Shared With Me</span>
            <span class="opp-stat-value"><?= (int)$stats['shared'] ?></span>
        </div>
    </div>

    <div class="opp-stat-card">
        <div class="opp-stat-icon amber"><i class="fas fa-hard-drive"></i></div>
        <div class="opp-stat-info">
            <span class="opp-stat-label">Storage</span>
            <span class="opp-stat-value"><?= h(doc_format_bytes((int)$stats['bytes'])) ?></span>
        </div>
    </div>
</div>

<div class="doc-breadcrumbs">
    <a href="documents"><i class="fas fa-house"></i> Documents</a>

    <?php foreach ($folderPath as $crumb): ?>
        <i class="fas fa-chevron-right"></i>
        <a href="documents?folder=<?= (int)$crumb['folder_id'] ?>">
            <?= h($crumb['folder_name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($currentFolder): ?>
    <div class="doc-current-folder-banner">
        <div class="doc-current-folder-main">
            <i class="fas fa-folder-open"></i>

            <div style="min-width:0;">
                <div class="doc-current-folder-name">
                    <?= h($currentFolder['folder_name']) ?>
                </div>

                <div class="doc-current-folder-note">
                    Add subfolders and files directly inside this folder.
                </div>
            </div>
        </div>

        <?php if ($canCreateHere): ?>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                onclick="openModal('addNewModal')"
            >
                <i class="fas fa-plus"></i>
                Add New
            </button>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="GET" action="documents" class="doc-filter-row filters-bar" role="search" aria-label="Filter documents">
    <?php if ($currentFolderId > 0): ?>
        <input type="hidden" name="folder" value="<?= $currentFolderId ?>">
    <?php endif; ?>

    <div class="doc-filter-search filters-grow">
        <i class="fas fa-search"></i>
        <input
            type="text"
            name="search"
            value="<?= h($search) ?>"
            class="form-control"
            placeholder="Search this location..."
        >
    </div>

    <select name="project" class="form-control">
        <option value="">All Projects</option>
        <?php foreach ($projects as $project): ?>
            <option
                value="<?= (int)$project['project_id'] ?>"
                <?= $filterProject === (int)$project['project_id'] ? 'selected' : '' ?>
            >
                <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="program" class="form-control">
        <option value="">All Programs</option>
        <?php foreach ($programs as $program): ?>
            <option
                value="<?= (int)$program['id'] ?>"
                <?= $filterProgram === (int)$program['id'] ? 'selected' : '' ?>
            >
                <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div class="filters-actions">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fas fa-filter"></i> Filter
        </button>

        <a
            href="<?= $currentFolderId > 0 ? 'documents?folder=' . $currentFolderId : 'documents' ?>"
            class="btn btn-secondary btn-sm"
        >
            Clear
        </a>
    </div>
</form>



<div class="doc-drive-toolbar">
    <div style="font-size:12px;font-weight:800;color:var(--ink-600);">
        <i class="fas fa-folder-open" style="color:#ea580c;margin-right:6px;"></i>
        <?= h($currentFolder['folder_name'] ?? 'Documents') ?>
    </div>

    <div class="doc-drive-actions">
        <?php if ($canCreateHere): ?>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                onclick="openModal('addNewModal')"
            >
                <i class="fas fa-plus"></i>
                New
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="doc-drive-grid">
    <?php if (!$folders && !$documents): ?>
        <div class="doc-empty">
            <i class="fas fa-folder-open"></i>
            <div>This folder is empty.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($folders as $folder): ?>
        <?php
        $folderId = (int)$folder['folder_id'];
        $canEditFolder = doc_can(
            $conn,
            'folder',
            $folderId,
            $userId,
            $userRole,
            'edit'
        );
        ?>

        <article
            class="doc-drive-item folder"
            data-resource-type="folder"
            data-resource-id="<?= $folderId ?>"
            data-resource-name="<?= h($folder['folder_name']) ?>"
            data-can-edit="<?= ($canEditFolder || $isAdmin) ? '1' : '0' ?>"
            data-parent-folder-id="<?= (int)($folder['parent_folder_id'] ?? 0) ?>"
            draggable="<?= ($canEditFolder || $isAdmin) ? 'true' : 'false' ?>"
            data-folder-edit='<?= h(json_encode([
                "folder_id" => $folderId,
                "folder_name" => (string)($folder["folder_name"] ?? ""),
                "document_type" => (string)($folder["document_type"] ?? ""),
                "description" => (string)($folder["description"] ?? ""),
                "project_id" => (int)($folder["project_id"] ?? 0),
                "program_id" => (int)($folder["program_id"] ?? 0),
                "parent_folder_id" => (int)($folder["parent_folder_id"] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
            ondblclick="window.location.href='documents?folder=<?= $folderId ?>'"
            oncontextmenu="docShowContextMenu(event, this)"
        >
            <div class="doc-drive-icon">
                <i class="fas fa-folder"></i>
            </div>

            <div class="doc-drive-main">
                <div class="doc-drive-name">
                    <?= h($folder['folder_name']) ?>
                </div>

                <div class="doc-drive-meta">
                    Folder . <?= (int)$folder['file_count'] ?> file<?= (int)$folder['file_count'] === 1 ? '' : 's' ?>
                </div>
            </div>

            <button
                type="button"
                class="doc-drive-more"
                onclick="docShowContextMenu(event, this.closest('.doc-drive-item'))"
                title="More actions"
            >
                <i class="fas fa-ellipsis"></i>
            </button>
        </article>
    <?php endforeach; ?>

    <?php foreach ($documents as $doc): ?>
        <?php
        $docId = (int)$doc['document_id'];
        $icon = docIconStyle((string)($doc['file_path'] ?? ''));
        [$faIcon, $iconColor, $iconBg] = $icon;

        $canView = doc_can($conn, 'document', $docId, $userId, $userRole, 'view');
        $canEdit = doc_can($conn, 'document', $docId, $userId, $userRole, 'edit');
        $canDownload = doc_can($conn, 'document', $docId, $userId, $userRole, 'download');
        ?>

        <article
            class="doc-drive-item"
            data-resource-type="document"
            data-resource-id="<?= $docId ?>"
            data-resource-name="<?= h($doc['document_name']) ?>"
            data-can-view="<?= $canView ? '1' : '0' ?>"
            data-can-edit="<?= ($canEdit || $isAdmin) ? '1' : '0' ?>"
            data-can-download="<?= $canDownload ? '1' : '0' ?>"
            data-folder-id="<?= (int)($doc['folder_id'] ?? 0) ?>"
            draggable="<?= ($canEdit || $isAdmin) ? 'true' : 'false' ?>"
            data-edit='<?= h(json_encode([
                "document_id" => $docId,
                "document_name" => (string)($doc["document_name"] ?? ""),
                "document_type" => (string)($doc["document_type"] ?? ""),
                "description" => (string)($doc["description"] ?? ""),
                "tags" => (string)($doc["tags"] ?? ""),
                "project_id" => (int)($doc["project_id"] ?? 0),
                "program_id" => (int)($doc["program_id"] ?? 0),
                "folder_id" => (int)($doc["folder_id"] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
            ondblclick="if(this.dataset.canView==='1') window.open('includes/documents-handler.php?action=view&id=<?= $docId ?>','_blank')"
            oncontextmenu="docShowContextMenu(event, this)"
        >
            <div
                class="doc-drive-icon"
                style="background:<?= h($iconBg) ?>;color:<?= h($iconColor) ?>;"
            >
                <i class="fas <?= h($faIcon) ?>"></i>
            </div>

            <div class="doc-drive-main">
                <div class="doc-drive-name">
                    <?= h($doc['document_name']) ?>
                </div>

                <div class="doc-drive-meta">
                    <?= h(doc_format_bytes((int)($doc['file_size'] ?? 0))) ?>
                    <?php if (!empty($doc['is_compressed'])): ?>
                        . <i class="fas fa-box-archive"></i> Compressed
                    <?php endif; ?>

                    <?php if (!empty($doc['uploaded_by_name'])): ?>
                        . <?= h($doc['uploaded_by_name']) ?>
                    <?php endif; ?>
                </div>
            </div>

            <button
                type="button"
                class="doc-drive-more"
                onclick="docShowContextMenu(event, this.closest('.doc-drive-item'))"
                title="More actions"
            >
                <i class="fas fa-ellipsis"></i>
            </button>
        </article>
    <?php endforeach; ?>
</div>

<div id="docDropOverlay" class="doc-drop-overlay">
    <div class="doc-drop-overlay-card">
        <i class="fas fa-cloud-arrow-up"></i>
        <span>
            Drop files or folders to upload
            <?php if ($currentFolder): ?>
                to <?= h($currentFolder['folder_name']) ?>
            <?php endif; ?>
        </span>
    </div>
</div>

<div id="docUploadToast" class="doc-upload-progress-toast">
    <i class="fas fa-spinner fa-spin"></i>
    <span id="docUploadToastText">Uploading...</span>
</div>

<div id="docContextMenu" class="doc-context-menu">
    <button type="button" class="doc-context-item" data-action="open">
        <i class="fas fa-folder-open"></i>
        Open
    </button>

    <button type="button" class="doc-context-item" data-action="view">
        <i class="fas fa-eye"></i>
        View
    </button>

    <button type="button" class="doc-context-item" data-action="download">
        <i class="fas fa-download"></i>
        Download
    </button>

    <div class="doc-context-sep"></div>

    <button type="button" class="doc-context-item" data-action="edit">
        <i class="fas fa-pen"></i>
        Edit
    </button>

    <button type="button" class="doc-context-item" data-action="move">
        <i class="fas fa-arrows-up-down-left-right"></i>
        Move
    </button>

    <button type="button" class="doc-context-item" data-action="permissions">
        <i class="fas fa-user-shield"></i>
        Manage Access
    </button>

    <div class="doc-context-sep"></div>

    <button type="button" class="doc-context-item danger" data-action="delete">
        <i class="fas fa-trash"></i>
        Delete
    </button>
</div>


<!-- ADD NEW -->
<div id="addNewModal" class="modal">
    <div class="modal-card">

        <div class="modal-head">
            <h3>
                <i class="fas fa-plus-circle" style="color:var(--brand-500);"></i>
                Add New
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('addNewModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <div class="modal-body">

            <div class="doc-add-location">
                <i class="fas fa-folder-open"></i>

                <div>
                    <?php if ($currentFolder): ?>
                        New items will be added inside
                        <strong><?= h($currentFolder['folder_name']) ?></strong>.
                    <?php else: ?>
                        New items will be added to the main Documents library.
                    <?php endif; ?>
                </div>
            </div>

            <div class="doc-add-new-grid">

                <button
                    type="button"
                    class="doc-add-new-option"
                    onclick="docChooseNewFolder()"
                >
                    <span class="doc-add-new-option-icon">
                        <i class="fas fa-folder-plus"></i>
                    </span>

                    <span>
                        <span class="doc-add-new-option-title">
                            <?= $currentFolder ? 'New Subfolder' : 'New Folder' ?>
                        </span>

                        <span class="doc-add-new-option-text">
                            Create a folder in the current location.
                        </span>
                    </span>
                </button>

                <button
                    type="button"
                    class="doc-add-new-option file"
                    onclick="docChooseUploadFile()"
                >
                    <span class="doc-add-new-option-icon">
                        <i class="fas fa-file-arrow-up"></i>
                    </span>

                    <span>
                        <span class="doc-add-new-option-title">
                            Upload Files
                        </span>

                        <span class="doc-add-new-option-text">
                            Select one or many files.
                        </span>
                    </span>
                </button>

                <?php if ($currentFolder): ?>
                    <button
                        type="button"
                        class="doc-add-new-option"
                        onclick="docChooseUploadFolder()"
                    >
                        <span class="doc-add-new-option-icon">
                            <i class="fas fa-folder-tree"></i>
                        </span>

                        <span>
                            <span class="doc-add-new-option-title">
                                Upload Folder
                            </span>

                            <span class="doc-add-new-option-text">
                                Upload a folder with all its files and subfolders.
                            </span>
                        </span>
                    </button>
                <?php endif; ?>

            </div>

        </div>

        <div class="modal-foot">
            <button
                type="button"
                class="btn btn-secondary"
                onclick="closeModal('addNewModal')"
            >
                Cancel
            </button>
        </div>

    </div>
</div>


<!-- CREATE FOLDER -->
<div id="createFolderModal" class="modal">
    <div class="modal-card" style="max-width:620px;">
        <div class="modal-head">
            <h3><i class="fas fa-folder-plus" style="color:var(--brand-500);"></i>
            <?= $currentFolder ? 'Create Subfolder' : 'Create Folder' ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('createFolderModal')">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php">
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="parent_folder_id" value="<?= $currentFolderId ?>">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Document Type</label>
                        <select
                            id="folder_document_type"
                            name="document_type"
                            class="form-control"
                            required
                            onchange="docToggleOtherFolderType()"
                        >
                            <option value="">Select document type...</option>
                            <?php foreach ($folderTypes as $type): ?>
                                <option value="<?= h($type) ?>"><?= h($type) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div id="folderOtherTypeWrap" class="doc-type-other">
                            <label class="form-label" style="margin-top:8px;">Other Folder Name</label>
                            <input
                                type="text"
                                id="folder_other_name"
                                name="other_type_name"
                                class="form-control"
                                placeholder="e.g. Contracts, Training Materials, MoUs"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Folder Name</label>
                        <input
                            type="text"
                            name="folder_name"
                            class="form-control"
                            placeholder="Leave blank to use document type"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Project</label>
                        <select name="project_id" class="form-control">
                            <option value="">General / None</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Program</label>
                        <select name="program_id" class="form-control">
                            <option value="">General / None</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createFolderModal')">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-folder-plus"></i> Create Folder
                </button>
            </div>
        </form>
    </div>
</div>


<!-- UPLOAD FILE -->
<div id="uploadModal" class="modal">
    <div class="modal-card">

        <div class="modal-head">
            <h3>
                <i class="fas fa-upload" style="color:var(--brand-500);"></i>
                Upload Files
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('uploadModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/documents-handler.php"
            enctype="multipart/form-data"
            style="
                display:flex;
                flex-direction:column;
                min-height:0;
                flex:1 1 auto;
            "
        >
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="folder_id" value="<?= $currentFolderId ?>">

            <div class="modal-body">

                <?php if ($autoOpenUpload): ?>
                    <div
                        style="
                            display:flex;
                            align-items:flex-start;
                            gap:9px;
                            margin-bottom:14px;
                            padding:11px 12px;
                            border:1px solid #bbf7d0;
                            border-left:4px solid #16a34a;
                            border-radius:8px;
                            background:#f0fdf4;
                            color:#166534;
                            font-size:11.5px;
                            line-height:1.5;
                        "
                    >
                        <i class="fas fa-circle-check" style="margin-top:1px;"></i>

                        <div>
                            <strong>Folder created successfully.</strong>
                            Add one or more files to this folder now.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($currentFolder): ?>

                    <div class="form-group full">
                        <label class="form-label required">
                            Select Files
                        </label>

                        <input
                            type="file"
                            name="documents[]"
                            id="documentsUploadInput"
                            class="form-control"
                            multiple
                            required
                            onchange="docUpdateSelectedFiles(this)"
                        >

                        <div
                            id="selectedFilesSummary"
                            style="
                                margin-top:7px;
                                color:var(--ink-300);
                                font-size:11px;
                                line-height:1.5;
                            "
                        >
                            Select one or more files. Folder metadata is inherited automatically.
                        </div>
                    </div>

                    <input type="hidden" name="document_name" value="">
                    <input type="hidden" name="document_type" value="Inherited">
                    <input
                        type="hidden"
                        name="project_id"
                        value="<?= (int)($currentFolder['project_id'] ?? 0) ?>"
                    >
                    <input
                        type="hidden"
                        name="program_id"
                        value="<?= (int)($currentFolder['program_id'] ?? 0) ?>"
                    >
                    <input type="hidden" name="description" value="">
                    <input type="hidden" name="tags" value="">

                    <div class="doc-share-note">
                        <i class="fas fa-circle-info"></i>
                        Files inherit the current folder metadata and permissions automatically.
                    </div>

                <?php else: ?>

                    <div class="form-grid">

                        <div class="form-group full">
                            <label class="form-label">
                                Document Name
                            </label>

                            <input
                                type="text"
                                name="document_name"
                                class="form-control"
                                placeholder="Optional for multiple files"
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label required">
                                Document Type
                            </label>

                            <select
                                id="upload_document_type"
                                name="document_type"
                                class="form-control"
                                required
                                onchange="docToggleUploadOtherType()"
                            >
                                <option value="">Select type...</option>

                                <?php foreach ($folderTypes as $type): ?>
                                    <option value="<?= h($type) ?>">
                                        <?= h($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div
                                id="uploadOtherTypeWrap"
                                class="doc-type-other"
                            >
                                <label
                                    class="form-label"
                                    style="margin-top:8px;"
                                >
                                    Other Type Name
                                </label>

                                <input
                                    type="text"
                                    name="other_type_name"
                                    id="upload_other_name"
                                    class="form-control"
                                    placeholder="e.g. Contract"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">
                                Select Files
                            </label>

                            <input
                                type="file"
                                name="documents[]"
                                id="documentsUploadInput"
                                class="form-control"
                                multiple
                                required
                                onchange="docUpdateSelectedFiles(this)"
                            >

                            <div
                                id="selectedFilesSummary"
                                style="
                                    margin-top:7px;
                                    color:var(--ink-300);
                                    font-size:11px;
                                    line-height:1.5;
                                "
                            >
                                You can select multiple files at once.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Project
                            </label>

                            <select
                                name="project_id"
                                class="form-control"
                            >
                                <option value="">None</option>

                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int)$project['project_id'] ?>">
                                        <?= h(
                                            ($project['project_code'] ?? '')
                                            . ' - '
                                            . ($project['project_name'] ?? '')
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Program
                            </label>

                            <select
                                name="program_id"
                                class="form-control"
                            >
                                <option value="">None</option>

                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int)$program['id'] ?>">
                                        <?= h(
                                            ($program['program_code'] ?? '')
                                            . ' - '
                                            . ($program['program_name'] ?? '')
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">
                                Description
                            </label>

                            <textarea
                                name="description"
                                class="form-control"
                                rows="3"
                            ></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">
                                Tags
                            </label>

                            <input
                                type="text"
                                name="tags"
                                class="form-control"
                                placeholder="comma-separated tags"
                            >
                        </div>

                    </div>

                <?php endif; ?>

            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('uploadModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-success"
                >
                    <i class="fas fa-upload"></i>
                    Upload Files
                </button>
            </div>

        </form>
    </div>
</div>



<!-- EDIT FOLDER -->
<div id="editFolderModal" class="modal">
    <div class="modal-card" style="max-width:680px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-folder-open" style="color:#ea580c;"></i>
                Edit Folder
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editFolderModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/documents-handler.php"
            style="
                display:flex;
                flex-direction:column;
                min-height:0;
                flex:1 1 auto;
            "
        >
            <input type="hidden" name="action" value="edit_folder">
            <input type="hidden" name="folder_id" id="editFolderId">
            <input type="hidden" name="parent_folder_id" id="editFolderParentId">

            <div class="modal-body">
                <div class="doc-edit-summary">
                    <i class="fas fa-folder-open"></i>

                    <div>
                        <strong id="editFolderSummaryName">Folder</strong>
                        <small>Rename or update this folder. Existing files remain inside it.</small>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label required">Folder Name</label>

                        <input
                            type="text"
                            name="folder_name"
                            id="editFolderName"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Folder Type</label>

                        <select
                            name="document_type"
                            id="editFolderType"
                            class="form-control"
                            required
                            onchange="docToggleEditFolderOtherType()"
                        >
                            <option value="">Select type...</option>

                            <?php foreach ($folderTypes as $type): ?>
                                <option value="<?= h($type) ?>">
                                    <?= h($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div
                            id="editFolderOtherTypeWrap"
                            class="doc-type-other"
                        >
                            <label class="form-label" style="margin-top:8px;">
                                Other Type Name
                            </label>

                            <input
                                type="text"
                                name="other_type_name"
                                id="editFolderOtherTypeName"
                                class="form-control"
                                placeholder="e.g. Contracts"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Project</label>

                        <select
                            name="project_id"
                            id="editFolderProject"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Program</label>

                        <select
                            name="program_id"
                            id="editFolderProgram"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Description</label>

                        <textarea
                            name="description"
                            id="editFolderDescription"
                            class="form-control"
                            rows="3"
                        ></textarea>
                    </div>
                </div>

                <div class="doc-share-note">
                    <i class="fas fa-user-shield"></i>
                    Folder owners and users granted Edit permission can modify this folder.
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('editFolderModal')"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


<!-- DELETE FOLDER -->
<div id="deleteFolderModal" class="modal doc-confirm-modal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>
                <i class="fas fa-folder-minus" style="color:var(--red-fg);"></i>
                Delete Folder
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('deleteFolderModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <div class="modal-body">
            <div class="doc-confirm-icon">
                <i class="fas fa-folder-minus"></i>
            </div>

            <div class="doc-confirm-title">
                Delete this folder?
            </div>

            <div class="doc-confirm-message">
                You are about to permanently delete
                <strong id="deleteFolderName"></strong>
                and all files and subfolders inside it.
                This action cannot be undone.
            </div>
        </div>

        <div class="modal-foot">
            <button
                type="button"
                class="btn btn-secondary"
                onclick="closeModal('deleteFolderModal')"
            >
                Cancel
            </button>

            <form
                method="POST"
                action="includes/documents-handler.php"
                style="margin:0;"
            >
                <input type="hidden" name="action" value="delete_folder">
                <input type="hidden" name="folder_id" id="deleteFolderId">
                <input type="hidden" name="parent_folder_id" id="deleteFolderParentId">

                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i>
                    Delete Folder
                </button>
            </form>
        </div>
    </div>
</div>


<!-- EDIT FILE -->
<div id="editFileModal" class="modal">
    <div class="modal-card" style="max-width:680px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-pen-to-square" style="color:var(--brand-500);"></i>
                Edit Document
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editFileModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <form method="POST" action="includes/documents-handler.php">
            <input type="hidden" name="action" value="edit_document">
            <input type="hidden" name="document_id" id="editDocumentId">
            <input type="hidden" name="folder_id" id="editDocumentFolderId">

            <div class="modal-body">
                <div class="doc-edit-summary">
                    <i class="fas fa-file-pen"></i>

                    <div>
                        <strong id="editDocumentSummaryName">Document</strong>
                        <small>Edit document details without replacing the uploaded file.</small>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label required">Document Name</label>

                        <input
                            type="text"
                            name="document_name"
                            id="editDocumentName"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Document Type</label>

                        <select
                            name="document_type"
                            id="editDocumentType"
                            class="form-control"
                            required
                            onchange="docToggleEditOtherType()"
                        >
                            <option value="">Select type...</option>

                            <?php foreach ($folderTypes as $type): ?>
                                <option value="<?= h($type) ?>">
                                    <?= h($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div id="editOtherTypeWrap" class="doc-type-other">
                            <label class="form-label" style="margin-top:8px;">
                                Other Type Name
                            </label>

                            <input
                                type="text"
                                name="other_type_name"
                                id="editOtherTypeName"
                                class="form-control"
                                placeholder="e.g. Contract"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Project</label>

                        <select
                            name="project_id"
                            id="editDocumentProject"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Program</label>

                        <select
                            name="program_id"
                            id="editDocumentProgram"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Description</label>

                        <textarea
                            name="description"
                            id="editDocumentDescription"
                            class="form-control"
                            rows="3"
                        ></textarea>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Tags</label>

                        <input
                            type="text"
                            name="tags"
                            id="editDocumentTags"
                            class="form-control"
                            placeholder="comma-separated tags"
                        >
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('editFileModal')"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


<!-- CUSTOM DELETE CONFIRMATION -->
<div id="deleteDocumentModal" class="modal doc-confirm-modal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>
                <i class="fas fa-trash-can" style="color:var(--red-fg);"></i>
                Delete Document
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('deleteDocumentModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <div class="modal-body">
            <div class="doc-confirm-icon">
                <i class="fas fa-trash-can"></i>
            </div>

            <div class="doc-confirm-title">
                Delete this document?
            </div>

            <div class="doc-confirm-message">
                You are about to permanently delete
                <strong id="deleteDocumentName"></strong>.
                This action cannot be undone.
            </div>
        </div>

        <div class="modal-foot">
            <button
                type="button"
                class="btn btn-secondary"
                onclick="closeModal('deleteDocumentModal')"
            >
                Cancel
            </button>

            <form
                method="POST"
                action="includes/documents-handler.php"
                style="margin:0;"
            >
                <input type="hidden" name="action" value="delete_document">
                <input type="hidden" name="document_id" id="deleteDocumentId">
                <input type="hidden" name="folder_id" id="deleteDocumentFolderId">

                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i>
                    Delete Permanently
                </button>
            </form>
        </div>
    </div>
</div>



<!-- UPLOAD FOLDER -->
<div id="uploadFolderModal" class="modal">
    <div class="modal-card" style="max-width:620px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-folder-tree" style="color:#ea580c;"></i>
                Upload Folder
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('uploadFolderModal')"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/documents-handler.php"
            enctype="multipart/form-data"
        >
            <input type="hidden" name="action" value="upload_folder">
            <input type="hidden" name="folder_id" value="<?= $currentFolderId ?>">

            <div class="modal-body">
                <div class="doc-add-location">
                    <i class="fas fa-folder-open"></i>
                    <div>
                        The uploaded folder will be created inside
                        <strong><?= h($currentFolder['folder_name'] ?? 'Documents') ?></strong>.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required">Choose Folder</label>

                    <input
                        type="file"
                        name="folder_documents[]"
                        id="folderUploadInput"
                        class="form-control"
                        webkitdirectory
                        directory
                        multiple
                        required
                        onchange="docUpdateFolderUpload(this)"
                    >

                    <div
                        id="folderUploadSummary"
                        class="doc-folder-upload-summary"
                    >
                        Select a folder. Its subfolders and files will be preserved.
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('uploadFolderModal')"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-cloud-arrow-up"></i>
                    Upload Folder
                </button>
            </div>
        </form>
    </div>
</div>


<!-- PERMISSIONS -->
<div id="permissionsModal" class="modal">
    <div class="modal-card" style="max-width:780px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-user-shield" style="color:var(--brand-500);"></i>
                Permissions
            </h3>

            <button type="button" class="modal-close" onclick="closeModal('permissionsModal')">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="resource_type" id="permResourceType">
            <input type="hidden" name="resource_id" id="permResourceId">

            <div class="modal-body">
                <div style="margin-bottom:12px;font-size:12px;color:var(--ink-400);">
                    Assign rights for <strong id="permResourceName"></strong>.
                </div>

                <div class="doc-rights-grid">
                    <div class="doc-rights-head">User</div>
                    <div class="doc-rights-head" style="text-align:center;">View</div>
                    <div class="doc-rights-head" style="text-align:center;">Edit</div>
                    <div class="doc-rights-head" style="text-align:center;">Download</div>

                    <?php foreach ($users as $user): ?>
                        <div class="doc-rights-row">
                            <div class="doc-rights-name">
                                <?= h($user['full_name']) ?>
                                <small style="display:block;color:var(--ink-300);font-weight:500;">
                                    <?= h($user['role']) ?>
                                </small>
                            </div>

                            <div class="doc-check">
                                <input
                                    type="checkbox"
                                    class="perm-box"
                                    data-user="<?= (int)$user['user_id'] ?>"
                                    data-right="view"
                                    name="permissions[<?= (int)$user['user_id'] ?>][view]"
                                    value="1"
                                >
                            </div>

                            <div class="doc-check">
                                <input
                                    type="checkbox"
                                    class="perm-box"
                                    data-user="<?= (int)$user['user_id'] ?>"
                                    data-right="edit"
                                    name="permissions[<?= (int)$user['user_id'] ?>][edit]"
                                    value="1"
                                >
                            </div>

                            <div class="doc-check">
                                <input
                                    type="checkbox"
                                    class="perm-box"
                                    data-user="<?= (int)$user['user_id'] ?>"
                                    data-right="download"
                                    name="permissions[<?= (int)$user['user_id'] ?>][download]"
                                    value="1"
                                >
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="doc-share-note">
                    Administrators and the owner always retain full access.
                    Folder permissions are inherited by files unless file-specific permissions are added.
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('permissionsModal')">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Permissions
                </button>
            </div>
        </form>
    </div>
</div>


<!-- MOVE -->
<div id="moveModal" class="modal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>
                <i class="fas fa-arrows-up-down-left-right" style="color:var(--brand-500);"></i>
                Move
            </h3>

            <button type="button" class="modal-close" onclick="closeModal('moveModal')">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php">
            <input type="hidden" name="action" id="moveAction" value="">
            <input type="hidden" name="resource_id" id="moveResourceId">
            <input type="hidden" name="current_folder_id" id="moveCurrentFolderId">

            <div class="modal-body">
                <div class="doc-edit-summary">
                    <i class="fas fa-arrows-up-down-left-right" id="moveSummaryIcon"></i>

                    <div>
                        <strong id="moveSummaryName">Item</strong>
                        <small>Choose a destination folder to move this into.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required">Destination Folder</label>

                    <select
                        name="target_folder_id"
                        id="moveTargetFolder"
                        class="form-control"
                        required
                    >
                        <option value="">Loading folders...</option>
                    </select>

                    <div
                        id="moveEmptyNote"
                        style="display:none;margin-top:7px;color:var(--ink-300);font-size:11px;"
                    >
                        No other folders are available to move into.
                    </div>
                </div>

                <div class="doc-share-note">
                    <i class="fas fa-circle-info"></i>
                    Moving keeps existing permissions and metadata intact - only the location changes.
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('moveModal')"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary" id="moveSubmitBtn">
                    <i class="fas fa-arrows-up-down-left-right"></i>
                    Move Here
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    'use strict';

    window.docChooseNewFolder = function(){
        closeModal('addNewModal');
        openModal('createFolderModal');
    };


    window.docChooseUploadFile = function(){
        closeModal('addNewModal');
        openModal('uploadModal');
    };


    window.docToggleOtherFolderType = function(){
        const type = document.getElementById('folder_document_type')?.value || '';
        const wrap = document.getElementById('folderOtherTypeWrap');
        const input = document.getElementById('folder_other_name');

        if (!wrap || !input) return;

        const show = type === 'Other';

        wrap.style.display = show ? 'block' : 'none';
        input.required = show;

        if (!show) input.value = '';
    };

    window.docToggleUploadOtherType = function(){
        const type = document.getElementById('upload_document_type')?.value || '';
        const wrap = document.getElementById('uploadOtherTypeWrap');
        const input = document.getElementById('upload_other_name');

        if (!wrap || !input) return;

        const show = type === 'Other';

        wrap.style.display = show ? 'block' : 'none';
        input.required = show;

        if (!show) input.value = '';
    };

    window.docOpenPermissions = function(type, id, name){
        document.getElementById('permResourceType').value = type;
        document.getElementById('permResourceId').value = id;
        document.getElementById('permResourceName').textContent = name;

        document.querySelectorAll('.perm-box').forEach(function(box){
            box.checked = false;
        });

        fetch(
            'includes/documents-handler.php?action=get_permissions&resource_type='
            + encodeURIComponent(type)
            + '&resource_id='
            + encodeURIComponent(id),
            {
                credentials: 'same-origin'
            }
        )
        .then(function(response){ return response.json(); })
        .then(function(data){
            if (data.success && Array.isArray(data.permissions)) {
                data.permissions.forEach(function(row){
                    ['view','edit','download'].forEach(function(right){
                        const box = document.querySelector(
                            '.perm-box[data-user="' + row.user_id + '"][data-right="' + right + '"]'
                        );

                        if (box) {
                            box.checked = Number(row['can_' + right] || 0) === 1;
                        }
                    });
                });
            }

            openModal('permissionsModal');
        })
        .catch(function(){
            openModal('permissionsModal');
        });
    };


    window.docOpenMoveModal = function(type, id, name, currentFolderId){
        const isFolder = type === 'folder';

        document.getElementById('moveAction').value =
            isFolder ? 'move_folder' : 'move_document';

        document.getElementById('moveResourceId').value = id;
        document.getElementById('moveCurrentFolderId').value = currentFolderId || 0;
        document.getElementById('moveSummaryName').textContent = name;

        const icon = document.getElementById('moveSummaryIcon');

        if (icon) {
            icon.className = isFolder ? 'fas fa-folder' : 'fas fa-file';
        }

        const select = document.getElementById('moveTargetFolder');
        const emptyNote = document.getElementById('moveEmptyNote');
        const submitBtn = document.getElementById('moveSubmitBtn');

        select.innerHTML = '<option value="">Loading folders...</option>';
        select.disabled = true;
        submitBtn.disabled = true;
        emptyNote.style.display = 'none';

        openModal('moveModal');

        fetch(
            'includes/documents-handler.php?action=get_folder_options&type='
            + encodeURIComponent(type)
            + '&id='
            + encodeURIComponent(id),
            {
                credentials: 'same-origin'
            }
        )
        .then(function(response){ return response.json(); })
        .then(function(data){
            select.innerHTML = '';

            const options = (data.success && Array.isArray(data.options))
                ? data.options
                : [];

            if (!options.length) {
                emptyNote.style.display = 'block';
                select.innerHTML = '<option value="">No destinations available</option>';
                select.disabled = true;
                submitBtn.disabled = true;
                return;
            }

            options.forEach(function(opt){
                const option = document.createElement('option');
                option.value = opt.folder_id;
                option.className = 'doc-move-option';

                const indent = '\u00A0\u00A0\u00A0\u00A0'.repeat(opt.depth || 0);
                const prefix = (opt.depth || 0) > 0 ? '\u2514\u2500 ' : '';

                option.textContent = indent + prefix + opt.folder_name;

                if (
                    String(opt.folder_id) === String(currentFolderId || 0)
                ) {
                    option.textContent += ' (current location)';
                }

                select.appendChild(option);
            });

            select.disabled = false;
            submitBtn.disabled = false;
        })
        .catch(function(){
            select.innerHTML = '<option value="">Could not load folders</option>';
            select.disabled = true;
            submitBtn.disabled = true;
        });
    };


    window.docToggleEditFolderOtherType = function(){
        const type =
            document.getElementById('editFolderType')?.value
            || '';

        const wrap =
            document.getElementById('editFolderOtherTypeWrap');

        const input =
            document.getElementById('editFolderOtherTypeName');

        if (!wrap || !input) {
            return;
        }

        const show = type === 'Other';

        wrap.style.display = show ? 'block' : 'none';
        input.required = show;

        if (!show) {
            input.value = '';
        }
    };


    window.docOpenEditFolder = function(folderData){
        if (!folderData) {
            return;
        }

        document.getElementById('editFolderId').value =
            folderData.folder_id || '';

        document.getElementById('editFolderParentId').value =
            folderData.parent_folder_id || 0;

        document.getElementById('editFolderName').value =
            folderData.folder_name || '';

        document.getElementById('editFolderSummaryName').textContent =
            folderData.folder_name || 'Folder';

        const typeSelect =
            document.getElementById('editFolderType');

        const otherInput =
            document.getElementById('editFolderOtherTypeName');

        const knownTypes = <?= json_encode($folderTypes) ?>;
        const currentType = String(folderData.document_type || '');

        if (knownTypes.includes(currentType)) {
            typeSelect.value = currentType;
            otherInput.value = '';
        } else {
            typeSelect.value = 'Other';
            otherInput.value = currentType;
        }

        document.getElementById('editFolderDescription').value =
            folderData.description || '';

        document.getElementById('editFolderProject').value =
            Number(folderData.project_id || 0) > 0
                ? String(folderData.project_id)
                : '';

        document.getElementById('editFolderProgram').value =
            Number(folderData.program_id || 0) > 0
                ? String(folderData.program_id)
                : '';

        docToggleEditFolderOtherType();

        openModal('editFolderModal');
    };


    window.docOpenDeleteFolderModal = function(id, name, parentFolderId){
        document.getElementById('deleteFolderId').value = id;
        document.getElementById('deleteFolderParentId').value = parentFolderId || 0;
        document.getElementById('deleteFolderName').textContent = name;

        openModal('deleteFolderModal');
    };


    window.docToggleEditOtherType = function(){
        const type =
            document.getElementById('editDocumentType')?.value
            || '';

        const wrap =
            document.getElementById('editOtherTypeWrap');

        const input =
            document.getElementById('editOtherTypeName');

        if (!wrap || !input) {
            return;
        }

        const knownTypes = <?= json_encode($folderTypes) ?>;
        const show = type === 'Other';

        wrap.style.display = show ? 'block' : 'none';
        input.required = show;

        if (!show) {
            input.value = '';
        }
    };


    window.docOpenEditFile = function(documentData){
        if (!documentData) {
            return;
        }

        document.getElementById('editDocumentId').value =
            documentData.document_id || '';

        document.getElementById('editDocumentFolderId').value =
            documentData.folder_id || '';

        document.getElementById('editDocumentName').value =
            documentData.document_name || '';

        document.getElementById('editDocumentSummaryName').textContent =
            documentData.document_name || 'Document';

        const typeSelect =
            document.getElementById('editDocumentType');

        const otherInput =
            document.getElementById('editOtherTypeName');

        const knownTypes = <?= json_encode($folderTypes) ?>;
        const currentType = String(documentData.document_type || '');

        if (knownTypes.includes(currentType)) {
            typeSelect.value = currentType;
            otherInput.value = '';
        } else {
            typeSelect.value = 'Other';
            otherInput.value = currentType;
        }

        document.getElementById('editDocumentDescription').value =
            documentData.description || '';

        document.getElementById('editDocumentTags').value =
            documentData.tags || '';

        document.getElementById('editDocumentProject').value =
            Number(documentData.project_id || 0) > 0
                ? String(documentData.project_id)
                : '';

        document.getElementById('editDocumentProgram').value =
            Number(documentData.program_id || 0) > 0
                ? String(documentData.program_id)
                : '';

        docToggleEditOtherType();

        openModal('editFileModal');
    };


    window.docOpenDeleteModal = function(id, name, folderId){
        document.getElementById('deleteDocumentId').value = id;
        document.getElementById('deleteDocumentFolderId').value = folderId || 0;
        document.getElementById('deleteDocumentName').textContent = name;

        openModal('deleteDocumentModal');
    };


    window.docUpdateSelectedFiles = function(input){
        const summary =
            document.getElementById('selectedFilesSummary');

        if (!summary) {
            return;
        }

        const files =
            Array.from(input?.files || []);

        if (!files.length) {
            summary.textContent =
                'You can select multiple files at once.';

            return;
        }

        const totalBytes =
            files.reduce(
                function(sum, file){
                    return sum + Number(file.size || 0);
                },
                0
            );

        function formatBytes(bytes){
            if (bytes < 1024) {
                return bytes + ' B';
            }

            if (bytes < 1024 * 1024) {
                return (
                    bytes / 1024
                ).toFixed(1) + ' KB';
            }

            return (
                bytes / 1024 / 1024
            ).toFixed(1) + ' MB';
        }

        summary.innerHTML =
            '<strong>'
            + files.length
            + ' file'
            + (files.length === 1 ? '' : 's')
            + ' selected</strong>'
            + ' . '
            + formatBytes(totalBytes);
    };


    window.docChooseUploadFolder = function(){
        closeModal('addNewModal');
        openModal('uploadFolderModal');
    };


    window.docUpdateFolderUpload = function(input){
        const summary =
            document.getElementById('folderUploadSummary');

        if (!summary) {
            return;
        }

        const files = Array.from(input?.files || []);

        if (!files.length) {
            summary.textContent =
                'Select a folder. Its subfolders and files will be preserved.';
            return;
        }

        const firstPath =
            files[0].webkitRelativePath
            || files[0].name
            || '';

        const rootFolder =
            firstPath.includes('/')
                ? firstPath.split('/')[0]
                : 'Selected folder';

        summary.textContent =
            rootFolder
            + ' . '
            + files.length
            + ' file'
            + (files.length === 1 ? '' : 's');
    };


    let docContextTarget = null;

    window.docShowContextMenu = function(event, target){
        event.preventDefault();
        event.stopPropagation();

        docContextTarget = target;

        document.querySelectorAll('.doc-drive-item').forEach(function(item){
            item.classList.toggle('selected', item === target);
        });

        const menu = document.getElementById('docContextMenu');

        if (!menu) {
            return;
        }

        const type = target.dataset.resourceType;
        const canView = target.dataset.canView === '1';
        const canEdit = target.dataset.canEdit === '1';
        const canDownload = target.dataset.canDownload === '1';

        const actionOpen = menu.querySelector('[data-action="open"]');
        const actionView = menu.querySelector('[data-action="view"]');
        const actionDownload = menu.querySelector('[data-action="download"]');
        const actionEdit = menu.querySelector('[data-action="edit"]');
        const actionMove = menu.querySelector('[data-action="move"]');
        const actionPermissions = menu.querySelector('[data-action="permissions"]');
        const actionDelete = menu.querySelector('[data-action="delete"]');

        actionOpen.style.display = type === 'folder' ? 'flex' : 'none';
        actionView.style.display = type === 'document' && canView ? 'flex' : 'none';
        actionDownload.style.display = type === 'document' && canDownload ? 'flex' : 'none';
        actionEdit.style.display = canEdit ? 'flex' : 'none';
        actionMove.style.display = canEdit ? 'flex' : 'none';
        actionPermissions.style.display = canEdit ? 'flex' : 'none';
        actionDelete.style.display = canEdit ? 'flex' : 'none';

        menu.classList.add('open');

        const rect = menu.getBoundingClientRect();
        let x = event.clientX;
        let y = event.clientY;

        if (x + 220 > window.innerWidth) {
            x = window.innerWidth - 220;
        }

        if (y + rect.height > window.innerHeight) {
            y = Math.max(8, window.innerHeight - rect.height - 8);
        }

        menu.style.left = Math.max(8, x) + 'px';
        menu.style.top = Math.max(8, y) + 'px';
    };


    function docHideContextMenu(){
        document.getElementById('docContextMenu')?.classList.remove('open');

        document.querySelectorAll('.doc-drive-item').forEach(function(item){
            item.classList.remove('selected');
        });
    }


    document.addEventListener('click', function(event){
        if (!event.target.closest('#docContextMenu')) {
            docHideContextMenu();
        }
    });


    document.addEventListener('keydown', function(event){
        if (event.key === 'Escape') {
            docHideContextMenu();
        }
    });


    document.querySelectorAll('#docContextMenu [data-action]').forEach(function(button){
        button.addEventListener('click', function(){
            if (!docContextTarget) {
                return;
            }

            const action = button.dataset.action;
            const type = docContextTarget.dataset.resourceType;
            const id = Number(docContextTarget.dataset.resourceId || 0);
            const name = docContextTarget.dataset.resourceName || '';

            docHideContextMenu();

            if (action === 'open' && type === 'folder') {
                window.location.href = 'documents?folder=' + id;
                return;
            }

            if (action === 'view' && type === 'document') {
                window.open(
                    'includes/documents-handler.php?action=view&id=' + id,
                    '_blank'
                );
                return;
            }

            if (action === 'download' && type === 'document') {
                window.location.href =
                    'includes/documents-handler.php?action=download&id=' + id;
                return;
            }

            if (action === 'edit') {
                if (type === 'document') {
                    try {
                        docOpenEditFile(
                            JSON.parse(
                                docContextTarget.dataset.edit || '{}'
                            )
                        );
                    } catch (e) {
                        console.error(e);
                    }
                } else if (type === 'folder') {
                    try {
                        docOpenEditFolder(
                            JSON.parse(
                                docContextTarget.dataset.folderEdit || '{}'
                            )
                        );
                    } catch (e) {
                        console.error(e);
                    }
                }

                return;
            }

            if (action === 'move') {
                const currentFolderId =
                    type === 'folder'
                        ? Number(docContextTarget.dataset.parentFolderId || 0)
                        : Number(docContextTarget.dataset.folderId || 0);

                docOpenMoveModal(type, id, name, currentFolderId);
                return;
            }

            if (action === 'permissions') {
                docOpenPermissions(type, id, name);
                return;
            }

            if (action === 'delete') {
                if (type === 'document') {
                    docOpenDeleteModal(
                        id,
                        name,
                        Number(docContextTarget.dataset.folderId || 0)
                    );
                } else if (type === 'folder') {
                    docOpenDeleteFolderModal(
                        id,
                        name,
                        Number(docContextTarget.dataset.parentFolderId || 0)
                    );
                }
            }
        });
    });


    /*
     * ======================================================================
     * DRAG AND DROP
     * ======================================================================
     */

    const DOC_CAN_UPLOAD_HERE = <?= $canCreateHere ? 'true' : 'false' ?>;
    const DOC_CURRENT_FOLDER_ID = <?= (int)$currentFolderId ?>;
    const DOC_MOVE_MIME = 'application/x-doc-item';

    const dropOverlay = document.getElementById('docDropOverlay');
    const uploadToast = document.getElementById('docUploadToast');
    const uploadToastText = document.getElementById('docUploadToastText');

    function docShowToast(text){
        if (!uploadToast) return;
        if (uploadToastText) uploadToastText.textContent = text;
        uploadToast.classList.add('active');
    }

    function docHideToast(){
        uploadToast?.classList.remove('active');
    }

    function docTypesInclude(event, type){
        return Array.from(event.dataTransfer?.types || []).includes(type);
    }

    function docIsOsFileDrag(event){
        return docTypesInclude(event, 'Files');
    }

    function docIsInternalItemDrag(event){
        return docTypesInclude(event, DOC_MOVE_MIME);
    }

    /*
     * Submits a FormData payload to the document handler and then
     * navigates the browser to wherever the server redirected - the same
     * end result as a normal <form> submission, so session success/error
     * flash messages still show up.
     */
    async function docSubmitFormData(formData, busyText){
        docShowToast(busyText || 'Working...');

        try {
            const response = await fetch('includes/documents-handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            window.location.href = response.url || window.location.href;
        } catch (err) {
            console.error(err);
            docHideToast();
            alert('Something went wrong. Please try again.');
        }
    }

    /*
     * ---- OS file / folder drop-to-upload ----
     */

    let docDragCounter = 0;

    window.addEventListener('dragover', function(event){
        if (docIsOsFileDrag(event)) {
            event.preventDefault();
        }
    });

    window.addEventListener('dragenter', function(event){
        if (!docIsOsFileDrag(event)) return;

        event.preventDefault();
        docDragCounter++;

        if (DOC_CAN_UPLOAD_HERE) {
            dropOverlay?.classList.add('active');
        }
    });

    window.addEventListener('dragleave', function(){
        docDragCounter = Math.max(0, docDragCounter - 1);

        if (docDragCounter === 0) {
            dropOverlay?.classList.remove('active');
        }
    });

    window.addEventListener('drop', function(event){
        if (!docIsOsFileDrag(event)) return;

        event.preventDefault();
        docDragCounter = 0;
        dropOverlay?.classList.remove('active');

        if (!DOC_CAN_UPLOAD_HERE) {
            alert('You do not have permission to upload files here.');
            return;
        }

        docHandleExternalDrop(event.dataTransfer);
    });

    function docHandleExternalDrop(dataTransfer){
        const items = dataTransfer?.items;

        if (!items || !items.length) {
            return;
        }

        const entries = [];

        for (let i = 0; i < items.length; i++) {
            const item = items[i];

            if (item.kind !== 'file') {
                continue;
            }

            const entry = item.webkitGetAsEntry
                ? item.webkitGetAsEntry()
                : null;

            if (entry) {
                entries.push(entry);
                continue;
            }

            const file = item.getAsFile();

            if (file) {
                entries.push({
                    isFile: true,
                    isDirectory: false,
                    name: file.name,
                    __file: file
                });
            }
        }

        if (entries.length) {
            docProcessDroppedEntries(entries);
        }
    }

    async function docProcessDroppedEntries(entries){
        const collected = [];
        let hasDirectory = false;

        async function readEntry(entry, pathPrefix){
            if (entry.__file) {
                collected.push({
                    file: entry.__file,
                    relativePath: pathPrefix + entry.name
                });
                return;
            }

            if (entry.isFile) {
                const file = await new Promise(function(resolve, reject){
                    entry.file(resolve, reject);
                });

                collected.push({
                    file: file,
                    relativePath: pathPrefix + entry.name
                });

                return;
            }

            if (entry.isDirectory) {
                hasDirectory = true;

                const reader = entry.createReader();
                let batch;

                do {
                    batch = await new Promise(function(resolve, reject){
                        reader.readEntries(resolve, reject);
                    });

                    for (const child of batch) {
                        await readEntry(child, pathPrefix + entry.name + '/');
                    }
                } while (batch.length > 0);
            }
        }

        try {
            for (const entry of entries) {
                await readEntry(entry, '');
            }
        } catch (err) {
            console.error(err);
            alert('Could not read the dropped files/folders.');
            return;
        }

        if (!collected.length) {
            return;
        }

        if (hasDirectory) {
            const formData = new FormData();
            formData.append('action', 'upload_folder');
            formData.append('folder_id', String(DOC_CURRENT_FOLDER_ID));

            collected.forEach(function(entry){
                formData.append('folder_documents[]', entry.file, entry.relativePath);
            });

            await docSubmitFormData(
                formData,
                'Uploading '
                    + collected.length
                    + ' file'
                    + (collected.length === 1 ? '' : 's')
                    + '...'
            );

            return;
        }

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('folder_id', String(DOC_CURRENT_FOLDER_ID));
        formData.append('document_type', 'Inherited');
        formData.append('project_id', '');
        formData.append('program_id', '');
        formData.append('description', '');
        formData.append('tags', '');

        collected.forEach(function(entry){
            formData.append('documents[]', entry.file, entry.file.name);
        });

        await docSubmitFormData(
            formData,
            'Uploading '
                + collected.length
                + ' file'
                + (collected.length === 1 ? '' : 's')
                + '...'
        );
    }

    /*
     * ---- In-app drag-to-move (cards onto folders / breadcrumbs) ----
     */

    function docMoveItemViaDrag(type, id, targetFolderId){
        const formData = new FormData();
        formData.append('action', type === 'folder' ? 'move_folder' : 'move_document');
        formData.append('resource_id', String(id));
        formData.append('current_folder_id', String(DOC_CURRENT_FOLDER_ID));
        formData.append('target_folder_id', String(targetFolderId));

        docSubmitFormData(formData, 'Moving...');
    }

    function docEnableDropTarget(el, targetFolderId){
        el.addEventListener('dragover', function(event){
            if (!docIsInternalItemDrag(event)) return;

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            el.classList.add('doc-drop-target-active');
        });

        el.addEventListener('dragleave', function(){
            el.classList.remove('doc-drop-target-active');
        });

        el.addEventListener('drop', function(event){
            if (!docIsInternalItemDrag(event)) return;

            event.preventDefault();
            event.stopPropagation();
            el.classList.remove('doc-drop-target-active');

            const raw = event.dataTransfer.getData(DOC_MOVE_MIME);

            if (!raw) return;

            let data;

            try {
                data = JSON.parse(raw);
            } catch (e) {
                return;
            }

            if (!data || !data.id) return;

            if (
                data.type === 'folder'
                &&
                Number(data.id) === Number(targetFolderId)
            ) {
                return;
            }

            docMoveItemViaDrag(data.type, data.id, targetFolderId);
        });
    }

    document.querySelectorAll('.doc-drive-item').forEach(function(item){
        item.addEventListener('dragstart', function(event){
            if (item.getAttribute('draggable') !== 'true') {
                event.preventDefault();
                return;
            }

            const data = {
                type: item.dataset.resourceType,
                id: item.dataset.resourceId,
                name: item.dataset.resourceName
            };

            event.dataTransfer.setData(DOC_MOVE_MIME, JSON.stringify(data));
            event.dataTransfer.setData('text/plain', data.name || '');
            event.dataTransfer.effectAllowed = 'move';

            item.classList.add('dragging');
        });

        item.addEventListener('dragend', function(){
            item.classList.remove('dragging');
        });

        if (item.classList.contains('folder')) {
            docEnableDropTarget(item, Number(item.dataset.resourceId || 0));
        }
    });

    document.querySelectorAll('.doc-breadcrumbs a').forEach(function(link){
        let targetFolderId = 0;

        try {
            const url = new URL(link.href, window.location.origin);
            targetFolderId = Number(url.searchParams.get('folder') || 0);
        } catch (e) {
            targetFolderId = 0;
        }

        docEnableDropTarget(link, targetFolderId);
    });


    <?php if ($autoOpenUpload): ?>
    document.addEventListener('DOMContentLoaded', function(){
        openModal('uploadModal');
    });
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>