<?php
declare(strict_types=1);

/*
 * Documents: a SharePoint-style document library.
 *
 * Left navigation (library, recent, my files, shared with me, favourites,
 * recycle bin), a selection-aware command bar, list / compact / tiles
 * views, client-side sorting and file-type filtering, and a details pane
 * with preview, access, properties, activity and version history.
 *
 * Data: includes/documents-process.php (+ documents-library.php).
 * Actions: includes/documents-handler.php (+ documents-library-actions.php).
 * Behaviour: public/js/documents.js. Styles: public/css/documents.css.
 */

$page_title = 'Documents';
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
$isAdmin = doc_lib_is_admin();
$libReady = doc_lib_ready($conn);

$views = [
    'library' => ['Documents', 'fa-folder-open'],
    'recent' => ['Recent', 'fa-clock-rotate-left'],
    'mine' => ['My files', 'fa-user'],
    'shared' => ['Shared with me', 'fa-user-group'],
    'favorites' => ['Favorites', 'fa-star'],
    'bin' => ['Recycle bin', 'fa-trash-can'],
];

$view = (string)($_GET['view'] ?? 'library');

if (!isset($views[$view]) || (!$libReady && in_array($view, ['favorites', 'bin'], true))) {
    $view = 'library';
}

$currentFolderId = $view === 'library' ? max(0, (int)($_GET['folder'] ?? 0)) : 0;
$search = trim((string)($_GET['search'] ?? ''));
$scope = (string)($_GET['scope'] ?? '');

if (!in_array($scope, ['folder', 'all'], true)) {
    $scope = $currentFolderId > 0 ? 'folder' : 'all';
}
$filterProject = max(0, (int)($_GET['project'] ?? 0));
$filterProgram = max(0, (int)($_GET['program'] ?? 0));

$currentFolder = $currentFolderId > 0
    ? doc_get_folder($conn, $currentFolderId, $userId, $userRole)
    : null;

if ($currentFolderId > 0 && !$currentFolder) {
    $_SESSION['error'] = 'Folder not found, in the recycle bin, or you do not have permission to view it.';
    header('Location: documents');
    exit;
}

$projects = getProjects($conn);
$programs = getPrograms($conn);
$users = doc_get_active_users($conn);
$favorites = doc_lib_favorites($conn, $userId);
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

$folderPath = doc_get_breadcrumbs($conn, $currentFolderId, $userId, $userRole);

$canCreateHere = $view === 'library' && (
    $currentFolder
        ? doc_can($conn, 'folder', $currentFolderId, $userId, $userRole, 'edit')
        : true
);

$autoOpenUpload = $currentFolderId > 0 && (string)($_GET['upload'] ?? '') === '1' && $canCreateHere;

/*
 * Load the rows for the active view.
 */
$hasFilters = $view === 'library' && ($search !== '' || $filterProject > 0 || $filterProgram > 0);
$searchEverywhere = $view === 'library' && $search !== '' && ($currentFolderId === 0 || $scope === 'all');
$showLocation = $view !== 'library' || $searchEverywhere;
$folders = [];
$documents = [];

switch ($view) {
    case 'recent':
        $documents = doc_lib_recent($conn, $userId, $userRole);
        break;

    case 'mine':
        $folders = doc_lib_folders($conn, $userId, $userRole, ['created_by' => $userId]);
        $documents = doc_lib_documents($conn, $userId, $userRole, ['uploaded_by' => $userId]);
        break;

    case 'shared':
        $shared = doc_lib_shared_with_me($conn, $userId, $userRole);
        $folders = $shared['folders'];
        $documents = $shared['documents'];
        break;

    case 'favorites':
        $folders = $favorites['folder'] ? doc_lib_folders($conn, $userId, $userRole, ['ids' => array_keys($favorites['folder'])]) : [];
        $documents = $favorites['document'] ? doc_lib_documents($conn, $userId, $userRole, ['ids' => array_keys($favorites['document'])]) : [];
        break;

    case 'bin':
        $folders = doc_lib_folders($conn, $userId, $userRole, ['deleted' => true]);
        $documents = doc_lib_documents($conn, $userId, $userRole, ['deleted' => true]);
        break;

    default:
        if ($searchEverywhere) {
            $folders = array_values(array_filter(
                doc_lib_folders($conn, $userId, $userRole, ['search' => $search]),
                static fn(array $f): bool => ($filterProject < 1 || (int)$f['project_id'] === $filterProject)
                    && ($filterProgram < 1 || (int)$f['program_id'] === $filterProgram)
            ));
            $documents = doc_lib_documents($conn, $userId, $userRole, [
                'folder_id' => null,
                'search' => $search,
                'project_id' => $filterProject,
                'program_id' => $filterProgram,
            ]);
        } else {
            $folders = doc_get_folders($conn, $currentFolderId, $userId, $userRole, $search, $filterProject, $filterProgram);
            $documents = doc_get_documents($conn, $currentFolderId, $userId, $userRole, $search, $filterProject, $filterProgram);

            if ($libReady && $documents) {
                // Pick up modified / check-out / version columns for this folder's files.
                $rich = [];

                foreach (doc_lib_documents($conn, $userId, $userRole, ['ids' => array_column($documents, 'document_id')]) as $row) {
                    $rich[(int)$row['document_id']] = $row;
                }

                $documents = array_values(array_filter(array_map(
                    static fn(array $d): ?array => $rich[(int)$d['document_id']] ?? null,
                    $documents
                )));
            }
        }
}

/*
 * Normalise folders and files into one row model for the list.
 */
$rowFor = static function (array $row, string $type) use ($conn, $userId, $userRole, $isAdmin, $favorites, $view, $showLocation): array {
    $bin = $view === 'bin';

    if ($type === 'folder') {
        $id = (int)$row['folder_id'];
        $canEdit = $bin || $isAdmin || doc_can($conn, 'folder', $id, $userId, $userRole, 'edit');
        $count = (int)($row['file_count'] ?? 0);
        $parent = (int)($row['parent_folder_id'] ?? 0);

        return [
            'type' => 'folder',
            'id' => $id,
            'name' => (string)$row['folder_name'],
            'kind' => 'folder',
            'icon' => 'fa-folder',
            'ext' => 'Folder',
            'modified' => (string)($bin ? ($row['deleted_at'] ?? '') : ($row['updated_at'] ?? $row['created_at'] ?? '')),
            'by' => (string)($bin ? ($row['deleted_by_name'] ?? '') : ($row['owner_name'] ?? '')),
            'owner' => (string)($row['owner_name'] ?? ''),
            'size' => $count,
            'sizeLabel' => $count . ' item' . ($count === 1 ? '' : 's'),
            'parent' => $parent,
            'location' => $showLocation ? doc_lib_folder_path($conn, $parent) : '',
            'context' => trim((string)($row['document_type'] ?? '')),
            'canView' => true,
            'canEdit' => $canEdit,
            'canDownload' => false,
            'favorite' => isset($favorites['folder'][$id]),
            'checkedOut' => '',
            'checkedOutMe' => false,
            'version' => '',
            'url' => $bin ? '' : 'documents?folder=' . $id,
            'edit' => [
                'folder_id' => $id,
                'folder_name' => (string)($row['folder_name'] ?? ''),
                'document_type' => (string)($row['document_type'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'project_id' => (int)($row['project_id'] ?? 0),
                'program_id' => (int)($row['program_id'] ?? 0),
                'parent_folder_id' => $parent,
            ],
        ];
    }

    $id = (int)$row['document_id'];
    $kind = doc_lib_file_kind($row);
    $size = (int)($row['original_file_size'] ?? 0) ?: (int)($row['file_size'] ?? 0);
    $folderId = (int)($row['folder_id'] ?? 0);
    $checkedOutBy = (int)($row['checked_out_by'] ?? 0);
    $project = trim((string)($row['project_code'] ?? '') . ' ' . (string)($row['project_name'] ?? ''));
    $program = trim((string)($row['program_code'] ?? '') . ' ' . (string)($row['program_name'] ?? ''));

    return [
        'type' => 'document',
        'id' => $id,
        'name' => (string)$row['document_name'],
        'kind' => $kind['kind'],
        'icon' => $kind['icon'],
        'ext' => $kind['label'],
        'modified' => (string)($bin
            ? ($row['deleted_at'] ?? '')
            : ($view === 'recent' && !empty($row['last_accessed']) ? $row['last_accessed'] : ($row['modified_at'] ?? $row['upload_date'] ?? ''))),
        'by' => (string)($bin
            ? ($row['deleted_by_name'] ?? '')
            : ($row['modified_by_name'] ?? $row['uploaded_by_name'] ?? '')),
        'owner' => (string)($row['uploaded_by_name'] ?? ''),
        'size' => $size,
        'sizeLabel' => doc_format_bytes($size),
        'parent' => $folderId,
        'location' => $showLocation ? doc_lib_folder_path($conn, $folderId) : '',
        'context' => $project !== '' ? $project : $program,
        'canView' => !$bin && doc_can($conn, 'document', $id, $userId, $userRole, 'view'),
        'canEdit' => $bin || $isAdmin || doc_can($conn, 'document', $id, $userId, $userRole, 'edit'),
        'canDownload' => !$bin && doc_can($conn, 'document', $id, $userId, $userRole, 'download'),
        'favorite' => isset($favorites['document'][$id]),
        'checkedOut' => $checkedOutBy > 0 ? (string)($row['checked_out_name'] ?? 'someone') : '',
        'checkedOutMe' => $checkedOutBy > 0 && $checkedOutBy === $userId,
        'version' => max(1, (int)($row['version_number'] ?? 1)) . '.0',
        'url' => $bin ? '' : 'includes/documents-handler.php?action=view&id=' . $id,
        'edit' => [
            'document_id' => $id,
            'document_name' => (string)($row['document_name'] ?? ''),
            'document_type' => (string)($row['document_type'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'tags' => (string)($row['tags'] ?? ''),
            'project_id' => (int)($row['project_id'] ?? 0),
            'program_id' => (int)($row['program_id'] ?? 0),
            'folder_id' => $folderId,
        ],
    ];
};

$rows = array_merge(
    array_map(static fn(array $f): array => $rowFor($f, 'folder'), $folders),
    array_map(static fn(array $d): array => $rowFor($d, 'document'), $documents)
);

/* Top-level folders for the navigation's quick links. */
$navFolders = array_slice(doc_get_folders($conn, 0, $userId, $userRole), 0, 12);

/* Query string the handler sends the user back to after an action. */
$returnQuery = http_build_query(array_filter([
    'view' => $view !== 'library' ? $view : null,
    'folder' => $currentFolderId ?: null,
    'search' => $search !== '' ? $search : null,
    'scope' => $search !== '' && $currentFolderId > 0 ? $scope : null,
    'project' => $filterProject ?: null,
    'program' => $filterProgram ?: null,
]));

$viewTitle = $view === 'library'
    ? ($currentFolder['folder_name'] ?? 'Documents')
    : $views[$view][0];

$columnHeads = $view === 'bin'
    ? ['modified' => 'Date deleted', 'by' => 'Deleted by', 'size' => 'Size', 'extra' => 'Original location']
    : [
        'modified' => $view === 'recent' ? 'Last opened' : 'Modified',
        'by' => 'Modified by',
        'size' => 'File size',
        'extra' => $showLocation ? 'Location' : 'Project / Program',
    ];

$libConfig = [
    'view' => $view,
    'folderId' => $currentFolderId,
    'folderName' => $currentFolder['folder_name'] ?? '',
    'canCreate' => $canCreateHere,
    'canUploadFolder' => $canCreateHere && $currentFolderId > 0,
    'libReady' => $libReady,
    'returnQuery' => $returnQuery,
    'folderTypes' => $folderTypes,
    'autoOpenUpload' => $autoOpenUpload,
    'isAdmin' => $isAdmin,
];

$navUrl = static fn(string $v): string => $v === 'library' ? 'documents' : 'documents?view=' . $v;
$cssVersion = @filemtime(dirname(__DIR__) . '/public/css/documents.css') ?: 1;
$jsVersion = @filemtime(dirname(__DIR__) . '/public/js/documents.js') ?: 1;
?>

<link rel="stylesheet" href="css/opportunities.css">
<link rel="stylesheet" href="css/documents.css?v=<?= (int)$cssVersion ?>">

<div class="page-stack sp-page">

<div class="page-hero sp-site-header">
    <div class="sp-site-id">
        <span class="sp-site-logo" aria-hidden="true"><i class="fas fa-folder-tree"></i></span>
        <div>
            <h1>Documents</h1>
            <p>Store, share and work on files together. <?= number_format((int)$stats['files']) ?> files &middot; <?= h(doc_format_bytes((int)$stats['bytes'])) ?> used</p>
        </div>
    </div>
</div>

<div class="sp-app<?= $view === 'bin' ? ' is-bin' : '' ?>" id="spApp" data-view="<?= h($view) ?>">

    <!-- ============================== NAVIGATION ============================== -->
    <nav class="sp-nav" id="spNav" aria-label="Document library">
        <button type="button" class="sp-nav-close" data-sp-nav-toggle aria-label="Close navigation">
            <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>

        <ul class="sp-nav-list">
            <?php foreach ($views as $key => [$label, $icon]): ?>
                <?php if (!$libReady && in_array($key, ['favorites', 'bin'], true)) continue; ?>
                <li>
                    <a href="<?= h($navUrl($key)) ?>" class="sp-nav-link<?= $view === $key ? ' is-active' : '' ?>"<?= $view === $key ? ' aria-current="page"' : '' ?><?= $key === 'library' ? ' data-drop-folder="0"' : '' ?>>
                        <i class="fas <?= h($icon) ?>" aria-hidden="true"></i>
                        <span><?= h($key === 'library' ? 'Documents' : $label) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($navFolders): ?>
            <div class="sp-nav-heading">Folders</div>
            <ul class="sp-nav-list sp-nav-folders">
                <?php foreach ($navFolders as $navFolder): ?>
                    <?php $navId = (int)$navFolder['folder_id']; ?>
                    <li>
                        <a
                            href="documents?folder=<?= $navId ?>"
                            class="sp-nav-link<?= ($folderPath && (int)(array_values($folderPath)[0]['folder_id'] ?? 0) === $navId) ? ' is-active-branch' : '' ?>"
                            data-drop-folder="<?= $navId ?>"
                            title="<?= h($navFolder['folder_name']) ?>"
                        >
                            <i class="fas fa-folder" aria-hidden="true"></i>
                            <span><?= h($navFolder['folder_name']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="sp-nav-foot">
            <div class="sp-storage">
                <i class="fas fa-hard-drive" aria-hidden="true"></i>
                <div>
                    <strong><?= h(doc_format_bytes((int)$stats['bytes'])) ?></strong> used
                    <small><?= number_format((int)$stats['folders']) ?> folders &middot; <?= number_format((int)$stats['files']) ?> files</small>
                </div>
            </div>
        </div>
    </nav>
    <div class="sp-nav-scrim" data-sp-nav-toggle></div>

    <!-- ============================== MAIN ============================== -->
    <section class="sp-main" aria-labelledby="spTitle">

        <!-- Command bar -->
        <div class="sp-commandbar" role="toolbar" aria-label="Library commands" id="spCommandBar">
            <div class="sp-cmd-group" id="spCmdPrimary">
                <button type="button" class="sp-cmd sp-cmd-icon-only sp-nav-toggle" data-sp-nav-toggle aria-label="Show navigation" title="Navigation">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>

                <?php if ($canCreateHere): ?>
                    <div class="sp-cmd-wrap" data-show="none">
                        <button type="button" class="sp-cmd sp-cmd-primary" data-menu="spNewMenu" aria-haspopup="menu" aria-expanded="false">
                            <i class="fas fa-plus" aria-hidden="true"></i><span>New</span><i class="fas fa-chevron-down sp-caret" aria-hidden="true"></i>
                        </button>
                        <div class="sp-menu" id="spNewMenu" role="menu">
                            <button type="button" role="menuitem" data-cmd="new-folder"><i class="fas fa-folder-plus sp-ic-folder" aria-hidden="true"></i>Folder</button>
                            <div class="sp-menu-sep" role="separator"></div>
                            <button type="button" role="menuitem" data-cmd="upload-files"><i class="fas fa-file-arrow-up" aria-hidden="true"></i>Upload files</button>
                            <?php if ($currentFolderId > 0): ?>
                                <button type="button" role="menuitem" data-cmd="upload-folder"><i class="fas fa-folder-tree" aria-hidden="true"></i>Upload folder</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sp-cmd-wrap" data-show="none">
                        <button type="button" class="sp-cmd" data-menu="spUploadMenu" aria-haspopup="menu" aria-expanded="false">
                            <i class="fas fa-arrow-up-from-bracket" aria-hidden="true"></i><span>Upload</span><i class="fas fa-chevron-down sp-caret" aria-hidden="true"></i>
                        </button>
                        <div class="sp-menu" id="spUploadMenu" role="menu">
                            <button type="button" role="menuitem" data-cmd="upload-files"><i class="fas fa-file" aria-hidden="true"></i>Files</button>
                            <?php if ($currentFolderId > 0): ?>
                                <button type="button" role="menuitem" data-cmd="upload-folder"><i class="fas fa-folder" aria-hidden="true"></i>Folder</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($view === 'bin'): ?>
                    <button type="button" class="sp-cmd" data-cmd="restore" data-show="any"><i class="fas fa-rotate-left" aria-hidden="true"></i><span>Restore</span></button>
                    <button type="button" class="sp-cmd" data-cmd="purge" data-show="any"><i class="fas fa-trash-can" aria-hidden="true"></i><span>Delete permanently</span></button>
                    <button type="button" class="sp-cmd" data-cmd="empty-bin" data-show="none"<?= $rows ? '' : ' disabled' ?>><i class="fas fa-dumpster" aria-hidden="true"></i><span>Empty recycle bin</span></button>
                <?php else: ?>
                    <button type="button" class="sp-cmd" data-cmd="open" data-show="one"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span>Open</span></button>
                    <button type="button" class="sp-cmd" data-cmd="share" data-show="one" data-need="edit"><i class="fas fa-share-nodes" aria-hidden="true"></i><span>Share</span></button>
                    <button type="button" class="sp-cmd" data-cmd="copy-link" data-show="one"><i class="fas fa-link" aria-hidden="true"></i><span>Copy link</span></button>
                    <button type="button" class="sp-cmd" data-cmd="download" data-show="any" data-need="download"><i class="fas fa-download" aria-hidden="true"></i><span>Download</span></button>
                    <button type="button" class="sp-cmd" data-cmd="favorite" data-show="any" data-requires-lib><i class="far fa-star" aria-hidden="true"></i><span>Favorite</span></button>
                    <button type="button" class="sp-cmd" data-cmd="delete" data-show="any" data-need="edit"><i class="fas fa-trash-can" aria-hidden="true"></i><span>Delete</span></button>
                    <button type="button" class="sp-cmd" data-cmd="rename" data-show="one" data-need="edit"><i class="fas fa-pen" aria-hidden="true"></i><span>Edit details</span></button>
                    <button type="button" class="sp-cmd" data-cmd="move" data-show="any" data-need="edit"><i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i><span>Move to</span></button>

                    <div class="sp-cmd-wrap" data-show="one">
                        <button type="button" class="sp-cmd sp-cmd-icon-only" data-menu="spMoreMenu" aria-haspopup="menu" aria-expanded="false" aria-label="More commands" title="More">
                            <i class="fas fa-ellipsis" aria-hidden="true"></i>
                        </button>
                        <div class="sp-menu" id="spMoreMenu" role="menu">
                            <button type="button" role="menuitem" data-cmd="preview"><i class="fas fa-eye" aria-hidden="true"></i>Preview</button>
                            <button type="button" role="menuitem" data-cmd="versions" data-requires-lib><i class="fas fa-clock-rotate-left" aria-hidden="true"></i>Version history</button>
                            <button type="button" role="menuitem" data-cmd="new-version" data-requires-lib><i class="fas fa-file-arrow-up" aria-hidden="true"></i>Upload new version</button>
                            <button type="button" role="menuitem" data-cmd="checkout" data-requires-lib><i class="fas fa-lock" aria-hidden="true"></i>Check out</button>
                            <button type="button" role="menuitem" data-cmd="checkin" data-requires-lib><i class="fas fa-lock-open" aria-hidden="true"></i>Check in</button>
                            <div class="sp-menu-sep" role="separator"></div>
                            <button type="button" role="menuitem" data-cmd="details"><i class="fas fa-circle-info" aria-hidden="true"></i>Details</button>
                        </div>
                    </div>

                    <button type="button" class="sp-cmd" data-cmd="export" data-show="none"<?= $rows ? '' : ' disabled' ?>><i class="fas fa-file-csv" aria-hidden="true"></i><span>Export to CSV</span></button>
                <?php endif; ?>
            </div>

            <div class="sp-cmd-group sp-cmd-right">
                <span class="sp-selection-pill" id="spSelectionPill" hidden>
                    <button type="button" class="sp-cmd sp-cmd-icon-only" data-cmd="clear-selection" aria-label="Clear selection" title="Clear selection">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                    <span id="spSelectionCount">0 selected</span>
                </span>

                <div class="sp-cmd-wrap">
                    <button type="button" class="sp-cmd" data-menu="spViewMenu" aria-haspopup="menu" aria-expanded="false" title="Switch view">
                        <i class="fas fa-list" aria-hidden="true" id="spViewIcon"></i><span id="spViewLabel">List</span><i class="fas fa-chevron-down sp-caret" aria-hidden="true"></i>
                    </button>
                    <div class="sp-menu sp-menu-right" id="spViewMenu" role="menu">
                        <button type="button" role="menuitemradio" data-layout="list"><i class="fas fa-list" aria-hidden="true"></i>List</button>
                        <button type="button" role="menuitemradio" data-layout="compact"><i class="fas fa-bars" aria-hidden="true"></i>Compact list</button>
                        <button type="button" role="menuitemradio" data-layout="tiles"><i class="fas fa-table-cells-large" aria-hidden="true"></i>Tiles</button>
                    </div>
                </div>

                <button type="button" class="sp-cmd sp-cmd-icon-only" id="spFilterToggle" aria-pressed="false" aria-controls="spFilters" title="Filter">
                    <i class="fas fa-filter" aria-hidden="true"></i><span class="sr-only">Filters</span>
                </button>

                <button type="button" class="sp-cmd sp-cmd-icon-only" data-cmd="details" id="spInfoToggle" aria-pressed="false" aria-controls="spDetails" title="Open the details pane">
                    <i class="fas fa-circle-info" aria-hidden="true"></i><span class="sr-only">Details pane</span>
                </button>
            </div>
        </div>

        <div class="sp-body">
            <div class="sp-content">

                <!-- Title + breadcrumb + search -->
                <header class="sp-header">
                    <div class="sp-header-text">
                        <?php if ($view === 'library' && $folderPath): ?>
                            <nav class="sp-breadcrumb" aria-label="Folder path">
                                <ol>
                                    <li><a href="documents" data-drop-folder="0">Documents</a></li>
                                    <?php foreach (array_slice(array_values($folderPath), 0, -1) as $crumb): ?>
                                        <li>
                                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                                            <a href="documents?folder=<?= (int)$crumb['folder_id'] ?>" data-drop-folder="<?= (int)$crumb['folder_id'] ?>"><?= h($crumb['folder_name']) ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                    <li aria-hidden="true"><i class="fas fa-chevron-right"></i></li>
                                </ol>
                            </nav>
                        <?php endif; ?>

                        <h2 class="sp-title" id="spTitle">
                            <?= h($viewTitle) ?>
                            <?php if ($view === 'library' && $currentFolder && $libReady): ?>
                                <button
                                    type="button"
                                    class="sp-follow<?= isset($favorites['folder'][$currentFolderId]) ? ' is-on' : '' ?>"
                                    id="spFollowFolder"
                                    data-item="folder:<?= $currentFolderId ?>"
                                    aria-pressed="<?= isset($favorites['folder'][$currentFolderId]) ? 'true' : 'false' ?>"
                                    title="Add this folder to Favorites"
                                >
                                    <i class="<?= isset($favorites['folder'][$currentFolderId]) ? 'fas' : 'far' ?> fa-star" aria-hidden="true"></i>
                                    <span class="sr-only">Favorite this folder</span>
                                </button>
                            <?php endif; ?>
                        </h2>

                        <p class="sp-subtitle">
                            <?php if ($view === 'bin'): ?>
                                Items stay here until you restore them or delete them permanently.
                            <?php elseif ($view === 'shared'): ?>
                                Files and folders others have given you access to.
                            <?php elseif ($view === 'recent'): ?>
                                Files you opened or changed recently.
                            <?php elseif ($view === 'favorites'): ?>
                                Files and folders you starred.
                            <?php elseif ($searchEverywhere): ?>
                                Results for &ldquo;<?= h($search) ?>&rdquo; across the library.
                            <?php else: ?>
                                <?= count($folders) ?> folder<?= count($folders) === 1 ? '' : 's' ?>,
                                <?= count($documents) ?> file<?= count($documents) === 1 ? '' : 's' ?>
                                <?= $hasFilters ? '&middot; filtered' : '' ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <form method="GET" action="documents" class="sp-search" role="search" id="spSearchForm">
                        <?php if ($view !== 'library'): ?>
                            <input type="hidden" name="view" value="<?= h($view) ?>">
                        <?php endif; ?>
                        <?php if ($currentFolderId > 0): ?>
                            <input type="hidden" name="folder" value="<?= $currentFolderId ?>">
                        <?php endif; ?>
                        <label for="spSearchInput" class="sr-only">Search</label>
                        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="spSearchInput"
                            name="search"
                            value="<?= h($search) ?>"
                            placeholder="<?= $view === 'library' ? ($currentFolderId > 0 ? 'Search this folder' : 'Search this library') : 'Filter by keyword' ?>"
                            autocomplete="off"
                        >
                        <?php if ($view === 'library' && $currentFolderId > 0): ?>
                            <label for="spSearchScope" class="sr-only">Search scope</label>
                            <select name="scope" id="spSearchScope" class="sp-search-scope">
                                <option value="folder"<?= $scope === 'folder' ? ' selected' : '' ?>>This folder</option>
                                <option value="all"<?= $scope === 'all' ? ' selected' : '' ?>>Entire library</option>
                            </select>
                        <?php endif; ?>
                    </form>
                </header>

                <!-- Filters pane -->
                <div class="sp-filters" id="spFilters" hidden>
                    <div class="sp-filter-group">
                        <span class="sp-filter-label">File type</span>
                        <div class="sp-chips" role="group" aria-label="File type">
                            <?php foreach ([
                                'all' => 'All',
                                'folder' => 'Folders',
                                'word' => 'Word',
                                'excel' => 'Excel',
                                'ppt' => 'PowerPoint',
                                'pdf' => 'PDF',
                                'image' => 'Images',
                                'video' => 'Video',
                                'other' => 'Other',
                            ] as $chip => $chipLabel): ?>
                                <button type="button" class="sp-chip<?= $chip === 'all' ? ' is-on' : '' ?>" data-kind-filter="<?= h($chip) ?>" aria-pressed="<?= $chip === 'all' ? 'true' : 'false' ?>"><?= h($chipLabel) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($view === 'library'): ?>
                        <form method="GET" action="documents" class="sp-filter-group sp-filter-form">
                            <?php if ($currentFolderId > 0): ?>
                                <input type="hidden" name="folder" value="<?= $currentFolderId ?>">
                            <?php endif; ?>
                            <?php if ($search !== ''): ?>
                                <input type="hidden" name="search" value="<?= h($search) ?>">
                                <input type="hidden" name="scope" value="<?= h($scope) ?>">
                            <?php endif; ?>

                            <label class="sp-filter-label" for="spFilterProject">Project</label>
                            <select name="project" id="spFilterProject" class="form-control">
                                <option value="">All projects</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int)$project['project_id'] ?>"<?= $filterProject === (int)$project['project_id'] ? ' selected' : '' ?>>
                                        <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="sp-filter-label" for="spFilterProgram">Program</label>
                            <select name="program" id="spFilterProgram" class="form-control">
                                <option value="">All programs</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int)$program['id'] ?>"<?= $filterProgram === (int)$program['id'] ? ' selected' : '' ?>>
                                        <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                            <?php if ($hasFilters): ?>
                                <a href="<?= $currentFolderId > 0 ? 'documents?folder=' . $currentFolderId : 'documents' ?>" class="btn btn-secondary btn-sm">Clear all</a>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Item list -->
                <?php if (!$rows): ?>
                    <div class="sp-empty">
                        <div class="sp-empty-art" aria-hidden="true">
                            <i class="fas <?= h($hasFilters ? 'fa-magnifying-glass' : $views[$view][1]) ?>"></i>
                        </div>
                        <?php if ($hasFilters): ?>
                            <h3>No results</h3>
                            <p>Nothing matches your search or filters.</p>
                            <a href="<?= $currentFolderId > 0 ? 'documents?folder=' . $currentFolderId : 'documents' ?>" class="btn btn-secondary btn-sm">Clear filters</a>
                        <?php elseif ($view === 'bin'): ?>
                            <h3>The recycle bin is empty</h3>
                            <p>Deleted files and folders appear here so you can restore them.</p>
                        <?php elseif ($view === 'favorites'): ?>
                            <h3>No favorites yet</h3>
                            <p>Select a file or folder and choose <strong>Favorite</strong> to find it here quickly.</p>
                        <?php elseif ($view === 'shared'): ?>
                            <h3>Nothing shared with you yet</h3>
                            <p>When someone gives you access to a file or folder, it shows up here.</p>
                        <?php elseif ($view === 'recent'): ?>
                            <h3>No recent files</h3>
                            <p>Files you open or change will appear here.</p>
                        <?php elseif ($view === 'mine'): ?>
                            <h3>You haven't added any files yet</h3>
                            <p>Upload a file to the library and it will show here.</p>
                        <?php else: ?>
                            <h3>Drag files here</h3>
                            <p><?= $canCreateHere ? 'Or use New to create a folder or upload files.' : 'Nothing has been shared with you here yet.' ?></p>
                            <?php if ($canCreateHere): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-cmd="upload-files">
                                    <i class="fas fa-arrow-up-from-bracket" aria-hidden="true"></i> Upload files
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="sp-list-wrap" id="spListWrap">
                        <table class="sp-list" id="spList" aria-labelledby="spTitle" aria-multiselectable="true">
                            <thead>
                                <tr>
                                    <th class="sp-col-check" scope="col">
                                        <label class="sp-check">
                                            <input type="checkbox" id="spSelectAll" aria-label="Select all items">
                                            <span aria-hidden="true"></span>
                                        </label>
                                    </th>
                                    <th class="sp-col-icon" scope="col"><span class="sr-only">Type</span><i class="far fa-file" aria-hidden="true"></i></th>
                                    <th class="sp-col-name" scope="col" aria-sort="none"><button type="button" class="sp-sort" data-sort="name">Name <i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                    <th class="sp-col-modified" scope="col" aria-sort="none"><button type="button" class="sp-sort" data-sort="modified"><?= h($columnHeads['modified']) ?> <i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                    <th class="sp-col-by" scope="col" aria-sort="none"><button type="button" class="sp-sort" data-sort="by"><?= h($columnHeads['by']) ?> <i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                    <th class="sp-col-size" scope="col" aria-sort="none"><button type="button" class="sp-sort" data-sort="size"><?= h($columnHeads['size']) ?> <i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                    <th class="sp-col-extra" scope="col" aria-sort="none"><button type="button" class="sp-sort" data-sort="extra"><?= h($columnHeads['extra']) ?> <i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                </tr>
                            </thead>
                            <tbody id="spRows">
                                <?php foreach ($rows as $item): ?>
                                    <?php
                                    $extra = $view === 'bin' || $showLocation ? $item['location'] : $item['context'];
                                    $ts = $item['modified'] !== '' && !str_starts_with($item['modified'], '0000') ? strtotime($item['modified']) : false;
                                    $isImage = $item['kind'] === 'image' && $item['canView'] && $item['ext'] !== 'SVG';
                                    ?>
                                    <tr
                                        class="sp-row"
                                        tabindex="-1"
                                        aria-selected="false"
                                        data-type="<?= h($item['type']) ?>"
                                        data-id="<?= (int)$item['id'] ?>"
                                        data-name="<?= h($item['name']) ?>"
                                        data-kind="<?= h($item['kind']) ?>"
                                        data-parent="<?= (int)$item['parent'] ?>"
                                        data-url="<?= h($item['url']) ?>"
                                        data-can-view="<?= $item['canView'] ? '1' : '0' ?>"
                                        data-can-edit="<?= $item['canEdit'] ? '1' : '0' ?>"
                                        data-can-download="<?= $item['canDownload'] ? '1' : '0' ?>"
                                        data-favorite="<?= $item['favorite'] ? '1' : '0' ?>"
                                        data-checked-out="<?= h($item['checkedOut']) ?>"
                                        data-checked-out-me="<?= $item['checkedOutMe'] ? '1' : '0' ?>"
                                        data-sort-name="<?= h(mb_strtolower($item['name'])) ?>"
                                        data-sort-modified="<?= $ts ? (int)$ts : 0 ?>"
                                        data-sort-by="<?= h(mb_strtolower($item['by'])) ?>"
                                        data-sort-size="<?= (int)$item['size'] ?>"
                                        data-sort-extra="<?= h(mb_strtolower($extra)) ?>"
                                        data-edit="<?= h(json_encode($item['edit'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                        draggable="<?= $view !== 'bin' && $item['canEdit'] ? 'true' : 'false' ?>"
                                        <?= $item['type'] === 'folder' && $view === 'library' ? 'data-drop-folder="' . (int)$item['id'] . '"' : '' ?>
                                    >
                                        <td class="sp-col-check">
                                            <label class="sp-check">
                                                <input type="checkbox" class="sp-row-check" tabindex="-1" aria-label="Select <?= h($item['name']) ?>">
                                                <span aria-hidden="true"></span>
                                            </label>
                                        </td>
                                        <td class="sp-col-icon">
                                            <span class="sp-ft sp-ft-<?= h($item['kind']) ?>" aria-hidden="true">
                                                <?php if ($isImage): ?>
                                                    <img class="sp-thumb" data-src="includes/documents-handler.php?action=thumb&amp;id=<?= (int)$item['id'] ?>" alt="" loading="lazy">
                                                <?php endif; ?>
                                                <i class="fas <?= h($item['icon']) ?>"></i>
                                                <?php if ($item['type'] === 'document'): ?>
                                                    <span class="sp-ft-ext"><?= h($item['ext']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($item['checkedOut'] !== ''): ?>
                                                    <span class="sp-ft-badge" title="Checked out to <?= h($item['checkedOut']) ?>"><i class="fas fa-lock"></i></span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="sp-col-name">
                                            <div class="sp-name-cell">
                                                <?php if ($item['url'] !== '' && $item['canView']): ?>
                                                    <a
                                                        href="<?= h($item['url']) ?>"
                                                        class="sp-name"
                                                        draggable="false"
                                                        <?= $item['type'] === 'document' ? 'target="_blank" rel="noopener"' : '' ?>
                                                    ><?= h($item['name']) ?></a>
                                                <?php else: ?>
                                                    <span class="sp-name"><?= h($item['name']) ?></span>
                                                <?php endif; ?>

                                                <?php if ($item['favorite']): ?>
                                                    <i class="fas fa-star sp-fav-mark" title="Favorite" aria-hidden="true"></i><span class="sr-only">(favorite)</span>
                                                <?php endif; ?>
                                                <?php if ($item['checkedOut'] !== ''): ?>
                                                    <span class="sr-only">(checked out to <?= h($item['checkedOut']) ?>)</span>
                                                <?php endif; ?>

                                                <button type="button" class="sp-row-more" aria-label="More actions for <?= h($item['name']) ?>" aria-haspopup="menu" title="More actions">
                                                    <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                            <div class="sp-tile-meta">
                                                <?= $item['type'] === 'folder' ? h($item['sizeLabel']) : h($item['ext'] . ' · ' . $item['sizeLabel']) ?>
                                            </div>
                                        </td>
                                        <td class="sp-col-modified">
                                            <?php if ($ts): ?>
                                                <time class="sp-when" datetime="<?= h(date('c', $ts)) ?>" title="<?= h(date('j F Y, H:i', $ts)) ?>"><?= h(date('j M Y', $ts)) ?></time>
                                            <?php else: ?>
                                                <span class="sp-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sp-col-by">
                                            <?php if ($item['by'] !== ''): ?>
                                                <span class="sp-person">
                                                    <span class="sp-avatar" aria-hidden="true"><?= h(doc_lib_initials($item['by'])) ?></span>
                                                    <span><?= h($item['by']) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="sp-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sp-col-size"><?= h($item['sizeLabel']) ?></td>
                                        <td class="sp-col-extra">
                                            <?php if ($extra !== ''): ?>
                                                <?php if ($showLocation && $view !== 'bin'): ?>
                                                    <a href="documents<?= $item['parent'] > 0 ? '?folder=' . (int)$item['parent'] : '' ?>" class="sp-location" title="<?= h($extra) ?>"><?= h($extra) ?></a>
                                                <?php else: ?>
                                                    <span class="sp-location" title="<?= h($extra) ?>"><?= h($extra) ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="sp-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="sp-no-match" id="spNoMatch" hidden>No items match this filter.</p>
                    </div>

                    <?php if ($canCreateHere): ?>
                        <p class="sp-drop-hint">
                            <i class="fas fa-cloud-arrow-up" aria-hidden="true"></i>
                            Drag files or folders here to upload. Drag items onto a folder to move them.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Details pane -->
            <aside class="sp-details" id="spDetails" aria-label="Details" hidden>
                <div class="sp-details-head">
                    <span class="sp-details-icon" id="spDetailsIcon" aria-hidden="true"><i class="fas fa-folder-open"></i></span>
                    <h3 id="spDetailsTitle"><?= h($viewTitle) ?></h3>
                    <button type="button" class="sp-cmd sp-cmd-icon-only" data-cmd="details" aria-label="Close the details pane" title="Close">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="sp-details-body" id="spDetailsBody" aria-live="polite">
                    <!-- Filled by documents.js; this is the no-selection summary. -->
                    <div class="sp-details-summary" id="spDetailsSummary">
                        <div class="sp-details-preview"><i class="fas <?= h($views[$view][1]) ?>" aria-hidden="true"></i></div>
                        <dl class="sp-props">
                            <div><dt>Folders</dt><dd><?= count($folders) ?></dd></div>
                            <div><dt>Files</dt><dd><?= count($documents) ?></dd></div>
                            <?php if ($currentFolder): ?>
                                <div><dt>Owner</dt><dd><?= h($currentFolder['owner_name'] ?? '') ?: '&mdash;' ?></dd></div>
                                <?php if (!empty($currentFolder['description'])): ?>
                                    <div><dt>Description</dt><dd><?= h($currentFolder['description']) ?></dd></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div><dt>Library storage</dt><dd><?= h(doc_format_bytes((int)$stats['bytes'])) ?></dd></div>
                            <div><dt>Files from colleagues</dt><dd><?= (int)$stats['shared'] ?></dd></div>
                        </dl>
                        <p class="sp-muted sp-details-hint">Select a file or folder to see its properties, who has access and recent activity.</p>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</div>

</div>

<!-- Context menu (filled from the command bar by documents.js) -->
<div class="sp-menu sp-context" id="spContextMenu" role="menu" aria-label="Item actions"></div>

<!-- Upload overlay + toast -->
<div id="docDropOverlay" class="sp-drop-overlay" aria-hidden="true">
    <div class="sp-drop-card">
        <i class="fas fa-cloud-arrow-up" aria-hidden="true"></i>
        <span>Drop to upload to <strong><?= h($currentFolder['folder_name'] ?? 'Documents') ?></strong></span>
    </div>
</div>

<div id="spToast" class="sp-toast" role="status" aria-live="polite">
    <i class="fas fa-circle-check" aria-hidden="true" id="spToastIcon"></i>
    <span id="spToastText"></span>
</div>

<?php require __DIR__ . '/includes/documents-modals.php'; ?>
<?php require __DIR__ . '/includes/documents-library-modals.php'; ?>

<script>window.DOC_LIB = <?= json_encode($libConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="js/documents.js?v=<?= (int)$jsVersion ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
