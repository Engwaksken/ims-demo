<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jobs.php';

/* -------------------------------------------------------
   AUTH CHECK (IMPORTANT)
------------------------------------------------------- */
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}

// Only HR / admin staff may manage job postings (previously any logged-in
// user, including Applicants and Members, could create/delete jobs).
if (!ims_allowed('jobs.manage', ['Administrator', 'HR', 'Operations/Admin', 'Executive Director', 'Programs Lead'], 'Create, edit and delete job postings')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Access denied']);
    exit;
}

// Every action except the read-only 'get' changes state: require the CSRF
// token (sent automatically as X-CSRF-Token by same-origin fetch calls).
if (($_GET['action'] ?? '') !== 'get') {
    csrf_protect(true);
}

/* -------------------------------------------------------
   RESPONSE HELPER
------------------------------------------------------- */
header('Content-Type: application/json');
function respond(array $r): void {
    echo json_encode($r);
    exit;
}

/* -------------------------------------------------------
   INPUT
------------------------------------------------------- */
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* -------------------------------------------------------
   ROUTER
------------------------------------------------------- */
switch ($action) {

    case 'get':
        $job = jobs_get_by_id($id);
        respond($job
            ? ['ok' => true, 'job' => $job]
            : ['ok' => false, 'msg' => 'Job not found']
        );
        break;

    case 'save':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        respond(jobs_save($data));
        break;

    case 'delete':
        respond(jobs_delete($id));
        break;

    case 'toggle':
        $field = $_GET['field'] ?? '';
        respond(jobs_toggle($id, $field));
        break;

    case 'status':
        $val = $_GET['val'] ?? '';
        respond(jobs_set_status($id, $val));
        break;

    default:
        respond(['ok' => false, 'msg' => 'Invalid action']);
}