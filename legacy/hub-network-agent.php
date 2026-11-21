<?php
declare(strict_types=1);

/*
 * pfSense -> IMS check-in for hub internet control (pull mode).
 *
 * The agent on the firewall (tools/pfsense/ims-cp-agent.php, run every
 * minute by pfSense's Cron package) calls this endpoint, so nothing on the
 * firewall has to be reachable from the internet. Works from shared hosting.
 *
 *   action=feed    -> desired state of every registered device
 *   action=report  -> what pfSense applied (per MAC), stored on each device,
 *                     plus the devices it sees online (agent 2.1+: clients/sessions)
 * The feed also carries the internet switch ("portal": on/off; empty = leave
 * the captive portal as configured in pfSense).
 *
 * POST only, JSON body signed with HMAC-SHA256 (header X-IMS-Signature) using
 * the shared secret, timestamp within 5 minutes, single-use nonce. Replies are
 * signed the same way so the agent can verify them. No session or CSRF token
 * (exempted in ims_csrf_auto_enforce()).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/hub-network.php';
require_once __DIR__ . '/includes/hub-internet.php';

/** Signed JSON reply. */
function hna_respond(int $status, array $body, string $secret = ''): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $json = (string)json_encode($body, JSON_UNESCAPED_SLASHES);
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    if ($secret !== '') {
        header('X-IMS-Signature: ' . hash_hmac('sha256', $json, $secret));
    }

    echo $json;
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    hna_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!isset($conn) || !($conn instanceof mysqli) || !hubnet_ensure_schema($conn)) {
    hna_respond(503, ['ok' => false, 'error' => 'temporary_failure']);
}

$settings = hubnet_settings($conn);

if (!$settings['has_secret']) {
    hna_respond(404, ['ok' => false, 'error' => 'not_found']);
}

$raw = (string)file_get_contents('php://input', false, null, 0, 2097152);
[$request, $error] = hubnet_agent_verify($conn, $settings, $raw, (string)($_SERVER['HTTP_X_IMS_SIGNATURE'] ?? ''));

if ($request === null) {
    error_log('[hub-network-agent] rejected check-in from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ': ' . $error);
    hna_respond($error === 'server_error' ? 503 : 401, ['ok' => false, 'error' => $error]);
}

$info = is_array($request['info'] ?? null) ? $request['info'] : [];
$info['ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
hubnet_record_checkin($conn, $info);

$active = $settings['enabled'] && $settings['mode'] === 'pull';

switch ((string)($request['action'] ?? '')) {
    case 'feed':
        hna_respond(200, [
            'ok' => true,
            // false: the agent changes nothing (control off, or push mode in use)
            'enabled' => $active,
            'zone' => $settings['cp_zone'],
            'descr_prefix' => HUBNET_DESCR_PREFIX,
            // Agent 2.4+: replace manual pfSense MAC entries of devices the IMS now manages.
            'adopt' => true,
            // Agent 2.6+: manual pfSense MAC entries deleted in the IMS (Online now > Delete).
            'delete_manual' => $active ? hubinet_pf_delete_queue($conn) : [],
            'items' => $active ? hubnet_feed($conn) : [],
            // Internet switch for agent 2.1+: on = enforce the captive portal, off = open internet.
            'portal' => $active && $settings['portal_control'] !== 'unmanaged' ? $settings['portal_control'] : '',
            'server_time' => time(),
        ], $settings['secret']);

    case 'report':
        $results = is_array($request['results'] ?? null) ? $request['results'] : [];
        $changed = $active ? hubnet_record_report($conn, $results) : 0;
        // Devices the agent applied nothing for get a reason instead of "Not synced".
        if ($active && $results !== []) {
            hubnet_flag_unreported($conn, $results);
        }
        // Agent 2.4+: MAC entries added by hand in pfSense, for import (Online now).
        if (is_array($request['manual_macs'] ?? null)) {
            hubinet_record_pf_macs($conn, $request['manual_macs']);
            // Queued deletions that pfSense no longer lists are done.
            hubinet_pf_delete_done($conn, array_column($request['manual_macs'], 'mac'));
        }
        // Who is online is tracked even while access control is off.
        $online = 0;
        if (is_array($request['clients'] ?? null) || is_array($request['sessions'] ?? null)) {
            $online = hubinet_record_online($conn,
                is_array($request['clients'] ?? null) ? $request['clients'] : [],
                is_array($request['sessions'] ?? null) ? $request['sessions'] : []);
        }
        // Agent 2.7+: data used per device since the last check-in.
        if (is_array($request['usage'] ?? null)) {
            hubinet_record_usage($conn, $request['usage']);
        }
        hna_respond(200, ['ok' => true, 'changed' => $changed, 'online' => $online], $settings['secret']);

    default:
        hna_respond(400, ['ok' => false, 'error' => 'unknown_action'], $settings['secret']);
}
