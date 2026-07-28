<?php
/*
 * ovp_parser.php — OpenVPN Client Importer: JSON Parser + Conflict Check Endpoint
 *
 * Accepts a POST request containing the raw text of a .ovpn file and returns
 * a JSON object with:
 *   - A concise summary of the parsed directives
 *   - A conflict report indicating which items (CA, cert, OpenVPN client)
 *     already exist in pfSense
 *
 * This endpoint is used by the upload page's optional JavaScript live-preview
 * feature to show the user what was found and what conflicts exist before
 * they submit the full import form.
 *
 * Request:  POST with field 'ovpn_content' containing the raw .ovpn text.
 * Response: JSON object:
 * {
 *   "success":   true|false,
 *   "errors":    [...],
 *   "summary": {
 *     "server":        "host:port",
 *     "protocol":      "UDP|TCP|...",
 *     "dev_mode":      "tun|tap",
 *     "cipher":        "...",
 *     "auth":          "...",
 *     "compression":   "...",
 *     "tls_type":      "tls-auth|tls-crypt|none",
 *     "tls_direction": "0|1|",
 *     "has_ca":        true|false,
 *     "has_cert":      true|false,
 *     "has_key":       true|false
 *   },
 *   "conflicts": {
 *     "ca":     { "duplicate": bool, "descr": "...", "refid": "..." },
 *     "cert":   { "duplicate": bool, "descr": "...", "refid": "..." },
 *     "client": { "duplicate": bool, "descr": "...", "vpnid": int|null }
 *   }
 * }
 *
 * Compatible with pfSense 2.8 / PHP 8.x
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/ovp_import.inc");

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	header('Content-Type: application/json');
	echo json_encode(['success' => false, 'errors' => ['Method Not Allowed']]);
	exit;
}

// Require an authenticated pfSense session
if (!isset($_SESSION['Username']) || empty($_SESSION['Username'])) {
	http_response_code(403);
	header('Content-Type: application/json');
	echo json_encode(['success' => false, 'errors' => ['Not authenticated']]);
	exit;
}

$raw_content = isset($_POST['ovpn_content']) ? $_POST['ovpn_content'] : '';

if (trim($raw_content) === '') {
	header('Content-Type: application/json');
	echo json_encode(['success' => false, 'errors' => ['No content provided.']]);
	exit;
}

// Parse the .ovpn content
$parsed = ovp_parse_ovpn_file($raw_content);
$d      = $parsed['directives'];
$b      = $parsed['blocks'];

// Build concise summary
$server = '';
if (!empty($d['remote'])) {
	$remotes = is_array($d['remote']) ? $d['remote'] : [$d['remote']];
	$server  = $remotes[0];
}

$tls_type = 'none';
if (!empty($b['tls-auth'])) {
	$tls_type = 'tls-auth';
} elseif (!empty($b['tls-crypt'])) {
	$tls_type = 'tls-crypt';
}

$summary = [
	'server'        => $server,
	'protocol'      => !empty($d['proto'])  ? ovp_map_protocol($d['proto'])  : 'UDP',
	'dev_mode'      => !empty($d['dev'])    ? ovp_map_dev_mode($d['dev'])    : 'tun',
	'cipher'        => !empty($d['cipher']) ? ovp_map_cipher($d['cipher'])   : '',
	'auth'          => !empty($d['auth'])   ? ovp_map_digest($d['auth'])     : '',
	'compression'   => ovp_map_compression($d),
	'tls_type'      => $tls_type,
	'tls_direction' => ovp_map_tls_direction($d),
	'has_ca'        => !empty($b['ca']),
	'has_cert'      => !empty($b['cert']),
	'has_key'       => !empty($b['key']),
];

// Run duplicate checks
$raw_conflicts = ovp_check_duplicates($parsed);

// Sanitise the conflict report for JSON output (remove full config arrays)
$conflicts = [
	'ca' => [
		'duplicate' => $raw_conflicts['ca']['duplicate'],
		'descr'     => $raw_conflicts['ca']['descr'],
		'refid'     => $raw_conflicts['ca']['refid'],
	],
	'cert' => [
		'duplicate' => $raw_conflicts['cert']['duplicate'],
		'descr'     => $raw_conflicts['cert']['descr'],
		'refid'     => $raw_conflicts['cert']['refid'],
	],
	'client' => [
		'duplicate' => $raw_conflicts['client']['duplicate'],
		'descr'     => $raw_conflicts['client']['descr'],
		'vpnid'     => $raw_conflicts['client']['vpnid'],
	],
];

header('Content-Type: application/json');
echo json_encode([
	'success'   => empty($parsed['errors']),
	'errors'    => $parsed['errors'],
	'summary'   => $summary,
	'conflicts' => $conflicts,
]);
exit;
