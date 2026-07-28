<?php
/*
 * ovp_import.inc — Core logic for pfSense-pkg-ovp
 *
 * This file contains all functions used by the OpenVPN Client Importer package:
 *   - .ovpn file parsing
 *   - Duplicate detection (CA, client certificate, OpenVPN client entry)
 *   - Certificate and key extraction / import
 *   - Selective pfSense config.xml writing (import only what is new)
 *   - Package lifecycle hooks (install / deinstall)
 *
 * Compatible with pfSense 2.8 / FreeBSD 15 / PHP 8.x
 */

require_once("config.inc");
require_once("certs.inc");
require_once("openvpn.inc");
require_once("util.inc");
require_once("pfsense-utils.inc");

/* ============================================================
   PACKAGE LIFECYCLE HOOKS
   ============================================================ */

/**
 * Called by pfSense after the package is installed.
 */
function ovp_install() {
	log_error("[OVP] pfSense OpenVPN Client Importer installed successfully.");
}

/**
 * Called by pfSense before the package is removed.
 */
function ovp_deinstall() {
	log_error("[OVP] pfSense OpenVPN Client Importer has been removed.");
}

/* ============================================================
   .OVPN FILE PARSER
   ============================================================ */

/**
 * Parse a .ovpn configuration file and return a structured array.
 *
 * Returns:
 *   'directives' — associative array of key => value for simple directives
 *   'blocks'     — associative array of block_name => raw_content for
 *                  inline blocks (<ca>, <cert>, <key>, <tls-auth>, <tls-crypt>)
 *   'errors'     — array of non-fatal warning strings
 *
 * @param  string $content  Raw text content of the .ovpn file
 * @return array
 */
function ovp_parse_ovpn_file($content) {
	$result = [
		'directives' => [],
		'blocks'     => [],
		'errors'     => [],
	];

	$content = str_replace(["\r\n", "\r"], "\n", $content);
	$lines   = explode("\n", $content);

	$in_block    = false;
	$block_name  = '';
	$block_lines = [];

	foreach ($lines as $line) {
		$trimmed = trim($line);

		if (!$in_block && ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';')) {
			continue;
		}

		// Opening tag: <ca>, <cert>, <key>, <tls-auth>, <tls-crypt>, <connection>
		if (preg_match('/^<([a-zA-Z0-9_-]+)>$/', $trimmed, $m)) {
			$in_block    = true;
			$block_name  = strtolower($m[1]);
			$block_lines = [];
			continue;
		}

		// Closing tag
		if ($in_block && preg_match('/^<\/([a-zA-Z0-9_-]+)>$/', $trimmed, $m)) {
			if (strtolower($m[1]) === $block_name) {
				$result['blocks'][$block_name] = implode("\n", $block_lines);
				$in_block    = false;
				$block_name  = '';
				$block_lines = [];
			}
			continue;
		}

		if ($in_block) {
			$block_lines[] = $line;
			continue;
		}

		if ($trimmed !== '') {
			$parts     = preg_split('/\s+/', $trimmed, 2);
			$directive = strtolower($parts[0]);
			$value     = isset($parts[1]) ? trim($parts[1]) : true;

			if ($directive === 'remote') {
				if (!isset($result['directives']['remote'])) {
					$result['directives']['remote'] = [];
				}
				$result['directives']['remote'][] = $value;
			} else {
				$result['directives'][$directive] = $value;
			}
		}
	}

	if ($in_block) {
		$result['errors'][] = "Unclosed block <{$block_name}> detected in .ovpn file.";
	}

	return $result;
}

/* ============================================================
   CERTIFICATE HELPERS
   ============================================================ */

/**
 * Strip any human-readable header before the first "-----BEGIN" marker.
 *
 * @param  string $pem
 * @return string
 */
function ovp_clean_pem($pem) {
	$pos = strpos($pem, '-----BEGIN');
	if ($pos !== false) {
		return trim(substr($pem, $pos));
	}
	return trim($pem);
}

/**
 * Compute a canonical fingerprint for a PEM-encoded certificate or key.
 * The fingerprint is the SHA-256 hash of the normalised PEM content
 * (whitespace-stripped), making it suitable for duplicate detection
 * regardless of how the content was formatted in the .ovpn file.
 *
 * @param  string $pem  Raw PEM text
 * @return string       64-character lowercase hex SHA-256 hash, or '' on failure
 */
function ovp_pem_fingerprint($pem) {
	$clean = ovp_clean_pem($pem);
	if (empty($clean)) {
		return '';
	}
	// Normalise: strip all whitespace so line-ending differences don't matter
	$normalised = preg_replace('/\s+/', '', $clean);
	return hash('sha256', $normalised);
}

/**
 * Generate a unique random reference ID for pfSense certificate entries.
 *
 * @return string  16-character lowercase hex string
 */
function ovp_generate_refid() {
	return substr(md5(uniqid(mt_rand(), true)), 0, 16);
}

/* ============================================================
   DUPLICATE DETECTION
   ============================================================ */

/**
 * Check whether a CA certificate already exists in pfSense's config.
 *
 * Comparison is done by PEM fingerprint so it is immune to description
 * changes or whitespace differences.
 *
 * @param  string $ca_pem  PEM-encoded CA certificate to check
 * @return array|false     The existing $config['ca'] entry if a match is found,
 *                         or false if it is new.
 */
function ovp_find_duplicate_ca($ca_pem) {
	$incoming_fp = ovp_pem_fingerprint($ca_pem);
	if ($incoming_fp === '') {
		return false;
	}

	$cas = config_get_path('ca', []);
	if (!is_array($cas) || empty($cas)) {
		return false;
	}

	foreach ($cas as $existing_ca) {
		if (empty($existing_ca['crt'])) {
			continue;
		}
		$existing_pem = base64_decode($existing_ca['crt']);
		$existing_fp  = ovp_pem_fingerprint($existing_pem);
		if ($existing_fp !== '' && $existing_fp === $incoming_fp) {
			return $existing_ca;
		}
	}

	return false;
}

/**
 * Check whether a client certificate already exists in pfSense's config.
 *
 * Comparison is done by PEM fingerprint of the certificate (not the key,
 * since the key is private and may differ even for the same cert).
 *
 * @param  string $cert_pem  PEM-encoded client certificate to check
 * @return array|false       The existing $config['cert'] entry if found,
 *                           or false if it is new.
 */
function ovp_find_duplicate_cert($cert_pem) {
	$incoming_fp = ovp_pem_fingerprint($cert_pem);
	if ($incoming_fp === '') {
		return false;
	}

	$certs = config_get_path('cert', []);
	if (!is_array($certs) || empty($certs)) {
		return false;
	}

	foreach ($certs as $existing_cert) {
		if (empty($existing_cert['crt'])) {
			continue;
		}
		$existing_pem = base64_decode($existing_cert['crt']);
		$existing_fp  = ovp_pem_fingerprint($existing_pem);
		if ($existing_fp !== '' && $existing_fp === $incoming_fp) {
			return $existing_cert;
		}
	}

	return false;
}

/**
 * Check whether an OpenVPN client entry already exists in pfSense's config.
 *
 * A match is determined by server address + server port + protocol.
 * This catches re-imports of the same VPN provider even if the description
 * was changed.
 *
 * @param  string $server_addr  Server hostname or IP
 * @param  string $server_port  Server port number
 * @param  string $protocol     pfSense protocol string (e.g. 'UDP', 'TCP')
 * @return array|false          The existing openvpn-client entry if found,
 *                              or false if it is new.
 */
function ovp_find_duplicate_client($server_addr, $server_port, $protocol) {
	if (empty($server_addr)) {
		return false;
	}

	$clients = config_get_path('openvpn/openvpn-client', []);
	if (!is_array($clients) || empty($clients)) {
		return false;
	}

	$addr_norm  = strtolower(trim($server_addr));
	$port_norm  = trim($server_port);
	$proto_norm = strtoupper(trim($protocol));

	foreach ($clients as $existing) {
		$e_addr  = strtolower(trim($existing['server_addr'] ?? ''));
		$e_port  = trim($existing['server_port'] ?? '');
		$e_proto = strtoupper(trim($existing['protocol'] ?? ''));

		if ($e_addr === $addr_norm && $e_port === $port_norm && $e_proto === $proto_norm) {
			return $existing;
		}
	}

	return false;
}

/**
 * Run all duplicate checks against a parsed .ovpn result and return a
 * structured conflict report.
 *
 * The returned array has the shape:
 * [
 *   'ca'     => [
 *     'duplicate' => bool,
 *     'existing'  => array|null,   // existing $config['ca'] entry
 *     'refid'     => string|null,  // refid of existing entry
 *     'descr'     => string,       // description of existing entry
 *   ],
 *   'cert'   => [
 *     'duplicate' => bool,
 *     'existing'  => array|null,
 *     'refid'     => string|null,
 *     'descr'     => string,
 *   ],
 *   'client' => [
 *     'duplicate' => bool,
 *     'existing'  => array|null,
 *     'vpnid'     => int|null,     // vpnid of existing client
 *     'descr'     => string,       // description of existing client
 *   ],
 * ]
 *
 * @param  array  $parsed   Output of ovp_parse_ovpn_file()
 * @param  array  $options  Same options array passed to ovp_import_config()
 * @return array
 */
function ovp_check_duplicates($parsed, $options = []) {
	$d = $parsed['directives'];
	$b = $parsed['blocks'];

	// ---- CA ----
	$ca_result = [
		'duplicate' => false,
		'existing'  => null,
		'refid'     => null,
		'descr'     => '',
	];
	if (!empty($b['ca'])) {
		$dup = ovp_find_duplicate_ca($b['ca']);
		if ($dup !== false) {
			$ca_result['duplicate'] = true;
			$ca_result['existing']  = $dup;
			$ca_result['refid']     = $dup['refid'] ?? null;
			$ca_result['descr']     = $dup['descr'] ?? '(no description)';
		}
	}

	// ---- Client Certificate ----
	$cert_result = [
		'duplicate' => false,
		'existing'  => null,
		'refid'     => null,
		'descr'     => '',
	];
	if (!empty($b['cert'])) {
		$dup = ovp_find_duplicate_cert($b['cert']);
		if ($dup !== false) {
			$cert_result['duplicate'] = true;
			$cert_result['existing']  = $dup;
			$cert_result['refid']     = $dup['refid'] ?? null;
			$cert_result['descr']     = $dup['descr'] ?? '(no description)';
		}
	}

	// ---- OpenVPN Client Entry ----
	$client_result = [
		'duplicate' => false,
		'existing'  => null,
		'vpnid'     => null,
		'descr'     => '',
	];

	// Resolve server address and protocol for comparison
	$server_addr = '';
	$server_port = '1194';
	if (!empty($d['remote'])) {
		$remotes = is_array($d['remote']) ? $d['remote'] : [$d['remote']];
		$parts   = preg_split('/\s+/', $remotes[0]);
		$server_addr = $parts[0] ?? '';
		$server_port = $parts[1] ?? '1194';
	}
	$protocol = !empty($d['proto']) ? ovp_map_protocol($d['proto']) : 'UDP';

	if ($server_addr !== '') {
		$dup = ovp_find_duplicate_client($server_addr, $server_port, $protocol);
		if ($dup !== false) {
			$client_result['duplicate'] = true;
			$client_result['existing']  = $dup;
			$client_result['vpnid']     = isset($dup['vpnid']) ? (int)$dup['vpnid'] : null;
			$client_result['descr']     = $dup['description'] ?? '(no description)';
		}
	}

	return [
		'ca'     => $ca_result,
		'cert'   => $cert_result,
		'client' => $client_result,
	];
}

/* ============================================================
   OPENVPN DIRECTIVE MAPPERS
   ============================================================ */

/**
 * Map proto value to pfSense protocol string.
 *
 * @param  string $proto
 * @return string
 */
function ovp_map_protocol($proto) {
	$proto = strtolower(trim($proto));
	$map   = [
		'udp'        => 'UDP',
		'udp4'       => 'UDP4',
		'udp6'       => 'UDP6',
		'tcp'        => 'TCP',
		'tcp4'       => 'TCP4',
		'tcp6'       => 'TCP6',
		'tcp-client' => 'TCP',
	];
	return isset($map[$proto]) ? $map[$proto] : 'UDP';
}

/**
 * Map dev value to pfSense dev_mode string.
 *
 * @param  string $dev
 * @return string  'tun' or 'tap'
 */
function ovp_map_dev_mode($dev) {
	return (strpos(strtolower(trim($dev)), 'tap') === 0) ? 'tap' : 'tun';
}

/**
 * Normalise a cipher name for pfSense.
 *
 * @param  string $cipher
 * @return string
 */
function ovp_map_cipher($cipher) {
	return strtoupper(trim($cipher));
}

/**
 * Normalise an auth digest name for pfSense.
 *
 * @param  string $auth
 * @return string
 */
function ovp_map_digest($auth) {
	return strtoupper(trim($auth));
}

/**
 * Map compression directives to pfSense's compression field value.
 *
 * @param  array $directives
 * @return string  '', 'lzo', 'lz4', 'stub', 'stub-v2', 'noadapt'
 */
function ovp_map_compression($directives) {
	if (isset($directives['comp-lzo'])) {
		$val = $directives['comp-lzo'];
		if ($val === 'no' || $val === 'false') {
			return '';  // comp-lzo no = disabled, pfSense field empty
		}
		if ($val === 'adaptive' || $val === true) {
			return 'adaptive';
		}
		return 'lzo';  // comp-lzo yes
	}
	if (isset($directives['compress'])) {
		$val = strtolower(trim($directives['compress']));
		$map = ['lz4' => 'lz4', 'lz4-v2' => 'lz4', 'lzo' => 'lzo', 'stub' => 'stub', 'stub-v2' => 'stub-v2'];
		return isset($map[$val]) ? $map[$val] : 'stub';
	}
	return '';
}

/**
 * Determine TLS auth direction from parsed directives.
 *
 * @param  array $directives
 * @return string  '0', '1', or ''
 */
function ovp_map_tls_direction($directives) {
	if (isset($directives['key-direction'])) {
		$dir = trim($directives['key-direction']);
		if ($dir === '0' || $dir === '1') {
			return $dir;
		}
	}
	if (isset($directives['tls-auth']) && $directives['tls-auth'] !== true) {
		$parts = preg_split('/\s+/', $directives['tls-auth']);
		$last  = end($parts);
		if ($last === '0' || $last === '1') {
			return $last;
		}
	}
	return '';
}

/* ============================================================
   CERTIFICATE IMPORT HELPERS
   ============================================================ */

/**
 * Import a CA certificate into pfSense's $config['ca'] array.
 *
 * @param  string $ca_pem
 * @param  string $descr
 * @return string|false  refid on success, false on failure
 */
function ovp_import_ca($ca_pem, $descr = 'OVP Imported CA') {
	$ca_pem = ovp_clean_pem($ca_pem);
	if (empty($ca_pem)) {
		return false;
	}

	$refid = ovp_generate_refid();

	config_set_path('ca/', [
		'refid' => $refid,
		'descr' => $descr,
		'crt'   => base64_encode($ca_pem),
	]);

	return $refid;
}

/**
 * Import a client certificate + key into pfSense's $config['cert'] array.
 *
 * @param  string $cert_pem
 * @param  string $key_pem
 * @param  string $descr
 * @return string|false  refid on success, false on failure
 */
function ovp_import_cert($cert_pem, $key_pem, $descr = 'OVP Imported Cert') {
	$cert_pem = ovp_clean_pem($cert_pem);
	$key_pem  = ovp_clean_pem($key_pem);

	if (empty($cert_pem) || empty($key_pem)) {
		return false;
	}

	$refid = ovp_generate_refid();

	config_set_path('cert/', [
		'refid' => $refid,
		'descr' => $descr,
		'crt'   => base64_encode($cert_pem),
		'prv'   => base64_encode($key_pem),
	]);

	return $refid;
}

/* ============================================================
   MAIN IMPORT FUNCTION
   ============================================================ */

/**
 * Import a parsed .ovpn configuration into pfSense with selective control
 * over which items to import when duplicates are detected.
 *
 * @param  array  $parsed      Output of ovp_parse_ovpn_file()
 * @param  array  $options     Import options:
 *   'description'   string   — friendly name for the VPN
 *   'interface'     string   — WAN interface (default: 'wan')
 *   'username'      string   — optional VPN username
 *   'password'      string   — optional VPN password
 *   'import_ca'     bool     — whether to import the CA (default: true)
 *   'import_cert'   bool     — whether to import the client cert (default: true)
 *   'import_client' bool     — whether to create the OpenVPN client (default: true)
 *   'existing_caref'   string|null — refid of existing CA to reuse (when skipping CA import)
 *   'existing_certref' string|null — refid of existing cert to reuse (when skipping cert import)
 *
 * @return array  [
 *   'success'   => bool,
 *   'errors'    => string[],
 *   'warnings'  => string[],
 *   'actions'   => string[],   — human-readable log of what was done
 *   'vpnid'     => int|null,
 * ]
 */
function ovp_import_config($parsed, $options = []) {
	global $config;

	$errors   = [];
	$warnings = [];
	$actions  = [];

	$directives = $parsed['directives'];
	$blocks     = $parsed['blocks'];

	if (!empty($parsed['errors'])) {
		$errors = array_merge($errors, $parsed['errors']);
	}

	// ---- Resolve options with defaults ----
	$description   = (isset($options['description']) && $options['description'] !== '')
		? $options['description'] : 'OVP Import';
	$interface     = (isset($options['interface']) && $options['interface'] !== '')
		? $options['interface'] : 'wan';
	$username      = $options['username'] ?? '';
	$password      = $options['password'] ?? '';
	$import_ca     = isset($options['import_ca'])     ? (bool)$options['import_ca']     : true;
	$import_cert   = isset($options['import_cert'])   ? (bool)$options['import_cert']   : true;
	$import_client = isset($options['import_client']) ? (bool)$options['import_client'] : true;

	// Pre-supplied refids to reuse when the user chose to skip re-import
	$existing_caref   = $options['existing_caref']   ?? null;
	$existing_certref = $options['existing_certref'] ?? null;

	// ----------------------------------------------------------------
	// Step 1: CA Certificate
	// ----------------------------------------------------------------
	$caref = null;

	if (!empty($blocks['ca'])) {
		if ($import_ca) {
			$caref = ovp_import_ca($blocks['ca'], $description . ' CA');
			if ($caref === false) {
				$errors[] = "Failed to import CA certificate — the content may be invalid.";
			} else {
				$actions[] = "CA certificate imported (refid: {$caref}).";
			}
		} else {
			// Reuse the existing CA
			$caref = $existing_caref;
			if ($caref) {
				$actions[] = "CA certificate skipped — reusing existing entry (refid: {$caref}).";
				$warnings[] = "CA certificate was already present and was not re-imported.";
			} else {
				$warnings[] = "CA certificate was skipped but no existing refid was provided. "
				            . "The OpenVPN client will have no CA reference.";
			}
		}
	} else {
		$warnings[] = "No <ca> block found in the .ovpn file. "
		            . "The OpenVPN client will be created without a CA reference.";
	}

	// ----------------------------------------------------------------
	// Step 2: Client Certificate + Key
	// ----------------------------------------------------------------
	$certref = null;

	if (!empty($blocks['cert']) && !empty($blocks['key'])) {
		if ($import_cert) {
			$certref = ovp_import_cert($blocks['cert'], $blocks['key'], $description . ' Cert');
			if ($certref === false) {
				$errors[] = "Failed to import client certificate or key — the content may be invalid.";
			} else {
				$actions[] = "Client certificate imported (refid: {$certref}).";
			}
		} else {
			$certref = $existing_certref;
			if ($certref) {
				$actions[] = "Client certificate skipped — reusing existing entry (refid: {$certref}).";
				$warnings[] = "Client certificate was already present and was not re-imported.";
			} else {
				$warnings[] = "Client certificate was skipped but no existing refid was provided.";
			}
		}
	} else {
		if (empty($blocks['cert'])) {
			$warnings[] = "No <cert> block found — normal for username/password-only VPNs.";
		}
		if (empty($blocks['key'])) {
			$warnings[] = "No <key> block found — normal for username/password-only VPNs.";
		}
	}

	// ----------------------------------------------------------------
	// Step 3: Resolve connection parameters
	// ----------------------------------------------------------------
	$server_addr = '';
	$server_port = '1194';

	if (!empty($directives['remote'])) {
		$remotes = is_array($directives['remote']) ? $directives['remote'] : [$directives['remote']];
		$parts   = preg_split('/\s+/', $remotes[0]);
		$server_addr = $parts[0] ?? '';
		$server_port = $parts[1] ?? '1194';
		if (count($remotes) > 1) {
			$warnings[] = "Multiple 'remote' entries found; only the first ("
			            . "{$server_addr}:{$server_port}) will be used.";
		}
	} else {
		$errors[] = "No 'remote' directive found — cannot determine the VPN server address.";
	}

	$protocol    = !empty($directives['proto'])  ? ovp_map_protocol($directives['proto'])  : 'UDP';
	$dev_mode    = !empty($directives['dev'])     ? ovp_map_dev_mode($directives['dev'])    : 'tun';
	$crypto      = !empty($directives['cipher'])  ? ovp_map_cipher($directives['cipher'])   : '';
	$digest      = !empty($directives['auth'])    ? ovp_map_digest($directives['auth'])     : 'SHA256';
	$compression = ovp_map_compression($directives);

	// ---- MTU / fragment / mssfix ----
	$tunnel_mtu      = isset($directives['tun-mtu'])   ? (string)(int)$directives['tun-mtu']   : '';
	$tunnel_fragment = isset($directives['fragment'])  ? (string)(int)$directives['fragment']  : '';
	$mssfix          = isset($directives['mssfix'])    ? (string)(int)$directives['mssfix']    : '';

	// ---- Auth mode (auth-user-pass) ----
	// When auth-user-pass is present pfSense needs authmode set to 'auth'
	$authmode = isset($directives['auth-user-pass']) ? 'auth' : '';

	// ---- Extra flags ----
	$fast_io       = isset($directives['fast-io'])       ? true : '';
	$pull          = isset($directives['pull'])          ? true : '';
	$remote_random = isset($directives['remote-random']) ? true : '';

	// ---- Collect unmapped directives into custom_options ----
	$custom_opts_map = [
		'sndbuf'        => 'sndbuf',
		'rcvbuf'        => 'rcvbuf',
		'route-method'  => 'route-method',
		'route-delay'   => 'route-delay',
		'ns-cert-type'  => 'ns-cert-type',
		'verify-x509-name' => 'verify-x509-name',
	];
	$custom_options_parts = [];
	foreach ($custom_opts_map as $key => $opt_name) {
		if (isset($directives[$key])) {
			$val = $directives[$key];
			if ($val === true) {
				$custom_options_parts[] = $opt_name;
			} else {
				$custom_options_parts[] = $opt_name . ' ' . $val;
			}
		}
	}
	$custom_options = implode(';', $custom_options_parts);

	// ---- TLS ----
	$tls_key       = '';
	$tls_type      = '';
	$tls_direction = '';
	if (!empty($blocks['tls-auth'])) {
		$tls_key       = $blocks['tls-auth'];
		$tls_type      = 'auth';
		$tls_direction = ovp_map_tls_direction($directives);
	} elseif (!empty($blocks['tls-crypt'])) {
		$tls_key  = $blocks['tls-crypt'];
		$tls_type = 'crypt';
	}

	// ---- Misc ----
	$verbosity = (isset($directives['verb']) && is_numeric($directives['verb']))
		? (string)(int)$directives['verb'] : '1';

	$keepalive_interval = '10';
	$keepalive_timeout  = '120';
	if (!empty($directives['keepalive'])) {
		$kp = preg_split('/\s+/', $directives['keepalive']);
		if (isset($kp[0]) && is_numeric($kp[0])) { $keepalive_interval = $kp[0]; }
		if (isset($kp[1]) && is_numeric($kp[1])) { $keepalive_timeout  = $kp[1]; }
	}

	$nobind      = isset($directives['nobind'])      ? true : '';
	$persist_key = isset($directives['persist-key']) ? true : '';
	$persist_tun = isset($directives['persist-tun']) ? true : '';

	$resolv_retry = '';
	if (isset($directives['resolv-retry'])) {
		$resolv_retry = ($directives['resolv-retry'] === true) ? 'infinite' : (string)$directives['resolv-retry'];
	}

	$remote_cert_tls = '';
	if (isset($directives['remote-cert-tls'])) {
		$remote_cert_tls = ($directives['remote-cert-tls'] === true)
			? 'server' : strtolower((string)$directives['remote-cert-tls']);
	}

	// ----------------------------------------------------------------
	// Abort on blocking errors before touching config
	// ----------------------------------------------------------------
	if (!empty($errors)) {
		return [
			'success'  => false,
			'errors'   => $errors,
			'warnings' => $warnings,
			'actions'  => $actions,
			'vpnid'    => null,
		];
	}

	// ----------------------------------------------------------------
	// Step 4: Create OpenVPN client entry (if requested)
	// ----------------------------------------------------------------
	$vpnid = null;

	if ($import_client) {
		// Determine next available vpnid
		$vpnid = 1;
		$existing_clients = config_get_path('openvpn/openvpn-client', []);
		if (is_array($existing_clients)) {
			foreach ($existing_clients as $existing) {
				if (isset($existing['vpnid']) && (int)$existing['vpnid'] >= $vpnid) {
					$vpnid = (int)$existing['vpnid'] + 1;
				}
			}
		}

		$new_client = [
			'vpnid'               => (string)$vpnid,
			'mode'                => 'p2p_tls',
			'protocol'            => $protocol,
			'dev_mode'            => $dev_mode,
			'interface'           => $interface,
			'ipaddr'              => '',
			'local_port'          => '',
			'server_addr'         => $server_addr,
			'server_port'         => $server_port,
			'proxy_addr'          => '',
			'proxy_port'          => '',
			'proxy_authtype'      => 'none',
			'description'         => $description,
			'caref'               => $caref ?? '',
			'certref'             => $certref ?? '',
			'tls'                 => !empty($tls_key) ? base64_encode($tls_key) : '',
			'tls_type'            => $tls_type,
			'tlsauth_keydir'      => $tls_direction,
			'crypto'              => $crypto,
			'digest'              => $digest,
			'engine'              => 'none',
			'tunnel_network'      => '',
			'tunnel_networkv6'    => '',
			'remote_network'      => '',
			'remote_networkv6'    => '',
			'gwredir'             => '',
			'gwredir6'            => '',
			'local_network'       => '',
			'local_networkv6'     => '',
			'maxclients'          => '',
			'compression'         => $compression,
			'passtos'             => '',
			'client2client'       => '',
			'dynamic_ip'          => '',
			'topology'            => '',
			'serverbridge_dhcp'   => '',
			'serverbridge_interface' => 'none',
			'serverbridge_dhcp_start' => '',
			'serverbridge_dhcp_end'   => '',
			'username'            => $username,
			'password'            => $password,
			'authmode'            => $authmode,
			'softfloat'           => '',
			'fast_io'             => $fast_io,
			'pull'                => $pull,
			'remote_random'       => $remote_random,
			'tunnel_mtu'          => $tunnel_mtu,
			'tunnel_fragment'     => $tunnel_fragment,
			'mssfix'              => $mssfix,
			'ping_method'         => 'keepalive',
			'keepalive_interval'  => $keepalive_interval,
			'keepalive_timeout'   => $keepalive_timeout,
			'ping_seconds'        => '10',
			'ping_push'           => '',
			'ping_action'         => 'ping_restart',
			'ping_action_seconds' => '60',
			'ping_action_push'    => '',
			'inactive_seconds'    => '',
			'verbosity_level'     => $verbosity,
			'push_blockoutsidedns' => '',
			'pull_dns'            => '',
			'dns_domain'          => '',
			'dns_domain_search'   => '',
			'dns_server1'         => '',
			'dns_server2'         => '',
			'dns_server3'         => '',
			'dns_server4'         => '',
			'wins_server1'        => '',
			'wins_server2'        => '',
			'client_mgmt_port'    => '',
			'nobind'              => $nobind,
			'persist_key'         => $persist_key,
			'persist_tun'         => $persist_tun,
			'remote_cert_tls'     => $remote_cert_tls,
			'resolv_retry'        => $resolv_retry,
			'custom_options'      => $custom_options,
		];

		config_set_path('openvpn/openvpn-client/', $new_client);
		$actions[] = "OpenVPN client #{$vpnid} ('{$description}') created.";
	} else {
		$actions[] = "OpenVPN client creation skipped — a matching client already exists.";
		$warnings[] = "The OpenVPN client entry was not created because a duplicate was detected "
		            . "and you chose to skip it.";
	}

	// ----------------------------------------------------------------
	// Step 5: Persist and resync
	// ----------------------------------------------------------------
	write_config("[OVP] Imported OpenVPN client '{$description}' from .ovpn file.");

	if ($import_client && $vpnid !== null) {
		$all_clients = config_get_path('openvpn/openvpn-client', []);
		$client_entry = end($all_clients);
		openvpn_resync('client', $client_entry);
		$actions[] = "OpenVPN client resynced and started.";
	}

	return [
		'success'  => true,
		'errors'   => $errors,
		'warnings' => $warnings,
		'actions'  => $actions,
		'vpnid'    => $vpnid,
	];
}

/* ============================================================
   UPLOAD VALIDATOR
   ============================================================ */

/**
 * Validate an uploaded .ovpn file and return any validation errors.
 *
 * @param  array  $file_info  Entry from $_FILES
 * @return array              List of error strings (empty = valid)
 */
function ovp_validate_upload($file_info) {
	$errors = [];

	if (!isset($file_info['tmp_name']) || empty($file_info['tmp_name'])) {
		$errors[] = "No file was uploaded.";
		return $errors;
	}

	if ($file_info['error'] !== UPLOAD_ERR_OK) {
		$upload_errors = [
			UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the server's maximum file size.",
			UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the form's maximum file size.",
			UPLOAD_ERR_PARTIAL    => "The file was only partially uploaded.",
			UPLOAD_ERR_NO_FILE    => "No file was uploaded.",
			UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder on the server.",
			UPLOAD_ERR_CANT_WRITE => "Failed to write the file to disk.",
			UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the file upload.",
		];
		$code     = $file_info['error'];
		$errors[] = $upload_errors[$code] ?? "Unknown upload error (code {$code}).";
		return $errors;
	}

	if ($file_info['size'] > 524288) {
		$errors[] = "The uploaded file is too large. Maximum allowed size is 512 KB.";
	}

	$ext = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
	if (!in_array($ext, ['ovpn', 'conf'], true)) {
		$errors[] = "Invalid file type. Only .ovpn and .conf files are accepted.";
	}

	if (!is_readable($file_info['tmp_name'])) {
		$errors[] = "The uploaded file could not be read from the server's temporary directory.";
	}

	return $errors;
}
