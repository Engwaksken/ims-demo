<?php
/*
 * IMS captive-portal agent for pfSense (CE 2.5+ / Plus 21.02+).
 *
 * Lets the IMS (/hub-team) connect, disconnect and block registered hub
 * devices by MAC address on a captive-portal zone:
 *   pass   - "Pass" MAC entry: online without the portal login
 *   block  - "Block" MAC entry + active sessions dropped
 *   remove - entry removed + active sessions dropped (back to the portal)
 *
 * INSTALL (Diagnostics > Command Prompt, or SSH as admin):
 *   1. Copy this file to /usr/local/www/ims-cp-agent.php
 *   2. Edit the three settings below (secret, allowed IMS server IPs, zone).
 *   3. In the IMS: Hub Team & Internet > pfSense settings, enter
 *        Agent URL:  https://<pfsense-lan-ip>/ims-cp-agent.php
 *        Secret:     the same IMS_AGENT_SECRET
 *      then "Test connection".
 *   The captive-portal zone must have "MAC filtering" enabled for pass/block
 *   MAC entries to take effect (Services > Captive Portal > zone).
 *   pfSense upgrades can remove files from /usr/local/www: re-copy after an
 *   upgrade (or install with the Filer package so it is restored).
 *
 * Every request must be a POST with a JSON body signed with HMAC-SHA256
 * (header X-IMS-Signature) using the shared secret, carry a timestamp
 * within 5 minutes and an unused nonce, and come from an allowed IP.
 */

// ---- Settings -------------------------------------------------------------
const IMS_AGENT_SECRET = 'CHANGE-ME-to-a-long-random-string';
const IMS_ALLOWED_IPS = ['192.168.1.10'];   // the IMS web server as pfSense sees it
const IMS_DEFAULT_ZONE = 'lan';             // captive portal zone name (lower case)
// ---------------------------------------------------------------------------

const IMS_AGENT_VERSION = '1.0';
const IMS_MAX_ITEMS = 2000;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ims_reply(bool $ok, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data, 'version' => IMS_AGENT_VERSION]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ims_reply(false, 'POST only.', [], 405);
}

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', IMS_ALLOWED_IPS, true)) {
    ims_reply(false, 'Address not allowed.', [], 403);
}

if (IMS_AGENT_SECRET === '' || strpos(IMS_AGENT_SECRET, 'CHANGE-ME') === 0) {
    ims_reply(false, 'Agent secret is not configured on pfSense.', [], 500);
}

$raw = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_IMS_SIGNATURE'] ?? '');

if ($raw === '' || !hash_equals(hash_hmac('sha256', $raw, IMS_AGENT_SECRET), $signature)) {
    ims_reply(false, 'Bad signature.', [], 401);
}

$request = json_decode($raw, true);

if (!is_array($request)) {
    ims_reply(false, 'Invalid JSON.', [], 400);
}

if (abs(time() - (int)($request['ts'] ?? 0)) > 300) {
    ims_reply(false, 'Request expired: check the clocks on pfSense and the IMS server.', [], 401);
}

// Replay protection: remember nonces for 10 minutes.
$nonce = preg_replace('/[^a-f0-9]/', '', (string)($request['nonce'] ?? ''));
$nonceFile = '/tmp/ims-cp-agent-nonces.json';
$nonces = is_file($nonceFile) ? (json_decode((string)file_get_contents($nonceFile), true) ?: []) : [];
$nonces = array_filter($nonces, static fn($seen) => $seen > time() - 600);

if ($nonce === '' || isset($nonces[$nonce])) {
    ims_reply(false, 'Replayed request.', [], 401);
}

$nonces[$nonce] = time();
@file_put_contents($nonceFile, json_encode($nonces), LOCK_EX);

require_once 'config.inc';
require_once 'util.inc';
require_once 'captiveportal.inc';

global $config, $g, $cpzone, $cpzoneid;

$cpzone = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)($request['zone'] ?? IMS_DEFAULT_ZONE)));

function ims_cfg_get(string $path, $default = null)
{
    global $config;

    if (function_exists('config_get_path')) {
        return config_get_path($path, $default);
    }

    $node = $config;
    foreach (explode('/', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }

    return $node;
}

function ims_cfg_set(string $path, $value): void
{
    global $config;

    if (function_exists('config_set_path')) {
        config_set_path($path, $value);
        return;
    }

    $node = &$config;
    foreach (explode('/', $path) as $key) {
        if (!isset($node[$key]) || !is_array($node[$key])) {
            $node[$key] = [];
        }
        $node = &$node[$key];
    }
    $node = $value;
}

$zoneConfig = ims_cfg_get("captiveportal/{$cpzone}");

if (!is_array($zoneConfig)) {
    ims_reply(false, "Captive portal zone '{$cpzone}' was not found.", [], 404);
}

$cpzoneid = $zoneConfig['zoneid'] ?? null;

function ims_normalize_mac(string $mac): string
{
    $hex = preg_replace('/[^a-f0-9]/', '', strtolower($mac));

    return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
}

/** Active portal sessions as [sessionid, mac, ip, username]. */
function ims_sessions(): array
{
    $sessions = [];

    foreach ((array)captiveportal_read_db() as $row) {
        // pfSense 2.5+: associative rows; older releases: numeric columns.
        $sessions[] = [
            'sessionid' => (string)($row['sessionid'] ?? $row[5] ?? ''),
            'mac' => ims_normalize_mac((string)($row['mac'] ?? $row[3] ?? '')),
            'ip' => (string)($row['ip'] ?? $row[2] ?? ''),
            'username' => (string)($row['username'] ?? $row[4] ?? ''),
        ];
    }

    return $sessions;
}

function ims_drop_sessions(string $mac, array $sessions): int
{
    $dropped = 0;

    foreach ($sessions as $session) {
        if ($session['mac'] === $mac && $session['sessionid'] !== '') {
            captiveportal_disconnect_client($session['sessionid'], 1, 'IMS_DISCONNECT');
            $dropped++;
        }
    }

    return $dropped;
}

/** Reloads the zone's MAC pass/block rules after the config changed. */
function ims_reload_mac_rules(): string
{
    global $cpzone;

    if (function_exists('captiveportal_passthrumac_configure')) {
        $rules = captiveportal_passthrumac_configure();
        if (is_string($rules) && $rules !== '') {
            $file = '/tmp/ims_cp_' . $cpzone . '_mac.rules';
            file_put_contents($file, $rules);
            mwexec('/sbin/pfctl -f ' . escapeshellarg($file));
            @unlink($file);
        }
        return 'passthrumac_configure';
    }

    // Fallback: full zone reconfigure (drops nothing on 2.5+, slower).
    captiveportal_configure_zone($GLOBALS['zoneConfig']);
    return 'configure_zone';
}

$action = (string)($request['action'] ?? '');

switch ($action) {
    case 'ping':
        ims_reply(true, "Connected to pfSense zone '{$cpzone}'.", [
            'zone' => $cpzone,
            'mac_filtering' => empty($zoneConfig['nomacfilter']),
            'sessions' => count(ims_sessions()),
            'mac_entries' => count((array)($zoneConfig['passthrumac'] ?? [])),
        ]);
        break;

    case 'list':
        ims_reply(true, 'OK', [
            'mac_entries' => array_values((array)($zoneConfig['passthrumac'] ?? [])),
            'sessions' => ims_sessions(),
        ]);
        break;

    case 'apply':
        $items = is_array($request['items'] ?? null) ? array_slice($request['items'], 0, IMS_MAX_ITEMS) : [];
        $entries = array_values((array)($zoneConfig['passthrumac'] ?? []));
        $sessions = ims_sessions();
        $results = [];
        $changed = false;

        foreach ($items as $item) {
            $mac = ims_normalize_mac((string)($item['mac'] ?? ''));
            $mode = (string)($item['mode'] ?? '');

            if ($mac === '' || !in_array($mode, ['pass', 'block', 'remove'], true)) {
                continue;
            }

            $descr = substr(preg_replace('/[^\w .@()\-]/u', '', (string)($item['descr'] ?? 'IMS')), 0, 120);
            $index = null;

            foreach ($entries as $i => $entry) {
                if (ims_normalize_mac((string)($entry['mac'] ?? '')) === $mac) {
                    $index = $i;
                    break;
                }
            }

            if ($mode === 'remove') {
                if ($index !== null) {
                    unset($entries[$index]);
                    $entries = array_values($entries);
                    $changed = true;
                }
            } else {
                $wanted = ['action' => $mode, 'mac' => $mac, 'descr' => $descr];

                if ($index === null) {
                    $entries[] = $wanted;
                    $changed = true;
                } elseif (($entries[$index]['action'] ?? 'pass') !== $mode || ($entries[$index]['descr'] ?? '') !== $descr) {
                    $entries[$index] = array_merge($entries[$index], $wanted);
                    $changed = true;
                }
            }

            $dropped = $mode === 'pass' ? 0 : ims_drop_sessions($mac, $sessions);
            $results[$mac] = ['ok' => true, 'mode' => $mode, 'sessions_dropped' => $dropped];
        }

        $method = 'unchanged';

        if ($changed) {
            ims_cfg_set("captiveportal/{$cpzone}/passthrumac", $entries);
            $zoneConfig['passthrumac'] = $entries;
            write_config('IMS hub: updated captive portal MAC entries');
            $method = ims_reload_mac_rules();
        }

        ims_reply(true, 'Applied ' . count($results) . ' device(s).', ['results' => $results, 'reload' => $method]);
        break;

    default:
        ims_reply(false, 'Unknown action.', [], 400);
}
