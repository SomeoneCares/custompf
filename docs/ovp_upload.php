<?php
/*
 * ovp_upload.php — OpenVPN Client Importer: Upload, Preview & Import Page
 *
 * Three-step flow:
 *   Step 1 — Upload form  (.ovpn file + optional overrides)
 *   Step 2 — Preview      (parsed config summary + duplicate conflict panel
 *                          with per-item checkboxes to skip or re-import)
 *   Step 3 — Result       (success/failure summary with action log)
 *
 * Accessible via: VPN > OpenVPN > Import Client (.ovpn)
 * Compatible with pfSense 2.8 / PHP 8.x / Bootstrap 3
 */

require_once("guiconfig.inc");
require_once("pfsense-utils.inc");
require_once("/usr/local/pkg/ovp_import.inc");

$pgtitle          = array("VPN", "OpenVPN", "Import Client (.ovpn)");
$shortcut_section = "openvpn";

/* ----------------------------------------------------------------
   State machine: 'upload' | 'preview' | 'done'
   ---------------------------------------------------------------- */
$step         = 'upload';
$parsed       = null;
$conflicts    = null;
$result       = null;
$input_errors = array();

/* ----------------------------------------------------------------
   POST handler
   ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

	/* ---- action: parse ---- */
	if ($_POST['action'] === 'parse') {

		$upload_errors = ovp_validate_upload(isset($_FILES['ovpn_file']) ? $_FILES['ovpn_file'] : array());
		if (!empty($upload_errors)) {
			$input_errors = $upload_errors;
		} else {
			$file_content = file_get_contents($_FILES['ovpn_file']['tmp_name']);

			if ($file_content === false || trim($file_content) === '') {
				$input_errors[] = "The uploaded file appears to be empty or could not be read.";
			} else {
				$parsed = ovp_parse_ovpn_file($file_content);

				if (!empty($parsed['errors'])) {
					$input_errors = array_merge($input_errors, $parsed['errors']);
				} else {
					$conflicts = ovp_check_duplicates($parsed);

					// Store state in hidden fields — no session dependency
					$ovp_state = array(
						'parsed'      => $parsed,
						'conflicts'   => $conflicts,
						'filename'    => $_FILES['ovpn_file']['name'],
						'description' => trim(isset($_POST['description']) ? $_POST['description'] : ''),
						'interface'   => trim(isset($_POST['interface'])   ? $_POST['interface']   : 'wan'),
						'username'    => trim(isset($_POST['username'])    ? $_POST['username']    : ''),
						'password'    => trim(isset($_POST['password'])    ? $_POST['password']    : ''),
					);

					$step = 'preview';
				}
			}
		}
	}

	/* ---- action: import ---- */
	elseif ($_POST['action'] === 'import') {

		// State is passed via hidden field — no session needed
		if (empty($_POST['ovp_state_data'])) {
			$input_errors[] = "Session expired. Please upload the file again.";
		} else {
			$ovp_state = @unserialize(base64_decode($_POST['ovp_state_data']));
			if (!is_array($ovp_state) || empty($ovp_state['parsed'])) {
				$input_errors[] = "Could not restore import data. Please upload the file again.";
			} else {
				$parsed    = $ovp_state['parsed'];
				$conflicts = isset($ovp_state['conflicts']) ? $ovp_state['conflicts'] : array();

				$import_ca     = isset($_POST['import_ca']);
				$import_cert   = isset($_POST['import_cert']);
				$import_client = isset($_POST['import_client']);

				$existing_caref   = null;
				$existing_certref = null;

				if (!$import_ca && !empty($conflicts['ca']['refid'])) {
					$existing_caref = $conflicts['ca']['refid'];
				}
				if (!$import_cert && !empty($conflicts['cert']['refid'])) {
					$existing_certref = $conflicts['cert']['refid'];
				}

				$options = array(
					'description'      => isset($ovp_state['description']) ? $ovp_state['description'] : '',
					'interface'        => isset($ovp_state['interface'])   ? $ovp_state['interface']   : 'wan',
					'username'         => isset($ovp_state['username'])    ? $ovp_state['username']    : '',
					'password'         => isset($ovp_state['password'])    ? $ovp_state['password']    : '',
					'import_ca'        => $import_ca,
					'import_cert'      => $import_cert,
					'import_client'    => $import_client,
					'existing_caref'   => $existing_caref,
					'existing_certref' => $existing_certref,
				);

				$result = ovp_import_config($parsed, $options);
				$step   = 'done';
			}
		}
	}

	/* ---- action: cancel ---- */
	elseif ($_POST['action'] === 'cancel') {
		$step = 'upload';
	}
}

/* ----------------------------------------------------------------
   Restore preview state from POST data (no session needed)
   ---------------------------------------------------------------- */
if ($step === 'preview' && $parsed === null) {
	$step = 'upload';
}

/* ----------------------------------------------------------------
   Interface list for dropdown
   ---------------------------------------------------------------- */
$iface_list = get_configured_interface_with_descr();

/* ----------------------------------------------------------------
   Helper: render a summary table row
   ---------------------------------------------------------------- */
function ovp_row($label, $value, $mono = false) {
	$cls     = $mono ? ' class="text-monospace"' : '';
	$display = ($value !== '' && $value !== null && $value !== false)
		? htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
		: '<em class="text-muted">not set</em>';
	echo "<tr><th style=\"width:35%\">" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</th><td{$cls}>{$display}</td></tr>\n";
}

/* ----------------------------------------------------------------
   Helper: render a conflict row with a checkbox
   ---------------------------------------------------------------- */
function ovp_conflict_row($item_key, $label, $conflict, $has_content) {
	if (!$has_content) {
		return;
	}

	$is_dup    = isset($conflict['duplicate']) ? $conflict['duplicate'] : false;
	$dup_descr = htmlspecialchars(isset($conflict['descr']) ? $conflict['descr'] : '', ENT_QUOTES, 'UTF-8');
	$safe_label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

	if ($is_dup) {
		echo '<tr class="warning">';
		echo '<td><span class="fa fa-exclamation-triangle text-warning"></span> <strong>' . $safe_label . '</strong></td>';
		echo '<td><span class="label label-warning">Already exists</span> <small class="text-muted">' . $dup_descr . '</small></td>';
		echo '<td class="text-center">';
		echo '<label>';
		echo '<input type="checkbox" name="import_' . htmlspecialchars($item_key, ENT_QUOTES, 'UTF-8') . '" value="1"> ';
		echo 'Re-import (create a new copy)';
		echo '</label>';
		echo '</td>';
		echo '</tr>';
	} else {
		echo '<tr class="success">';
		echo '<td><span class="fa fa-plus-circle text-success"></span> <strong>' . $safe_label . '</strong></td>';
		echo '<td><span class="label label-success">New</span></td>';
		echo '<td class="text-center">';
		echo '<label>';
		echo '<input type="checkbox" name="import_' . htmlspecialchars($item_key, ENT_QUOTES, 'UTF-8') . '" value="1" checked="checked"> ';
		echo 'Import';
		echo '</label>';
		echo '</td>';
		echo '</tr>';
	}
}

$pgtitle = array("VPN", "OpenVPN", "Import Client (.ovpn)");
include("head.inc");
?>

<body>
<?php include("navbar.inc"); ?>

<div id="wrap">
  <div id="main">
    <div class="container-fluid">

      <div class="page-header">
        <h1><?= gettext("OpenVPN — Import Client (.ovpn)") ?></h1>
        <p class="text-muted">
          <?= gettext("Upload a standard OpenVPN configuration file to automatically create a new VPN client instance, including all certificates and keys.") ?>
        </p>
      </div>

      <?php if (!empty($input_errors)): ?>
        <div class="alert alert-danger" role="alert">
          <strong><?= gettext("Errors:") ?></strong>
          <ul style="margin-bottom:0; margin-top:5px;">
            <?php foreach ($input_errors as $e): ?>
              <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php /* ====================================================
             STEP 1 — Upload Form
             ==================================================== */ ?>
      <?php if ($step === 'upload'): ?>

        <div class="panel panel-default">
          <div class="panel-heading">
            <h2 class="panel-title">
              <span class="fa fa-upload"></span>
              <?= gettext("Step 1 — Upload .ovpn File") ?>
            </h2>
          </div>
          <div class="panel-body">
            <form method="post" enctype="multipart/form-data" action="/packages/ovp/ovp_upload.php" class="form-horizontal">
              <input type="hidden" name="action" value="parse" />

              <div class="form-group">
                <label for="ovpn_file" class="col-sm-2 control-label">
                  <?= gettext("OpenVPN File") ?> <span class="text-danger">*</span>
                </label>
                <div class="col-sm-10">
                  <input type="file" id="ovpn_file" name="ovpn_file"
                         accept=".ovpn,.conf" class="form-control" required="required" />
                  <span class="help-block">
                    <?= gettext("Select a .ovpn or .conf file. Maximum size: 512 KB.") ?>
                  </span>
                </div>
              </div>

              <hr />

              <div class="form-group">
                <label for="description" class="col-sm-2 control-label">
                  <?= gettext("Description") ?>
                </label>
                <div class="col-sm-10">
                  <input type="text" id="description" name="description"
                         class="form-control" maxlength="64"
                         placeholder="<?= gettext("e.g. My Work VPN") ?>"
                         value="<?= htmlspecialchars(isset($_POST['description']) ? $_POST['description'] : '', ENT_QUOTES, 'UTF-8') ?>" />
                  <span class="help-block">
                    <?= gettext("A friendly name for this VPN connection.") ?>
                  </span>
                </div>
              </div>

              <div class="form-group">
                <label for="interface" class="col-sm-2 control-label">
                  <?= gettext("Interface") ?>
                </label>
                <div class="col-sm-10">
                  <select id="interface" name="interface" class="form-control">
                    <?php foreach ($iface_list as $k => $v): ?>
                      <option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"
                        <?= ((isset($_POST['interface']) ? $_POST['interface'] : 'wan') === $k) ? 'selected="selected"' : '' ?>>
                        <?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <span class="help-block">
                    <?= gettext("WAN interface this VPN client will use for outgoing connections.") ?>
                  </span>
                </div>
              </div>

              <div class="form-group">
                <label for="username" class="col-sm-2 control-label">
                  <?= gettext("Username") ?>
                </label>
                <div class="col-sm-10">
                  <input type="text" id="username" name="username"
                         class="form-control" autocomplete="off" maxlength="64"
                         placeholder="<?= gettext("Optional — only needed if the VPN requires a username") ?>"
                         value="<?= htmlspecialchars(isset($_POST['username']) ? $_POST['username'] : '', ENT_QUOTES, 'UTF-8') ?>" />
                </div>
              </div>

              <div class="form-group">
                <label for="password" class="col-sm-2 control-label">
                  <?= gettext("Password") ?>
                </label>
                <div class="col-sm-10">
                  <input type="password" id="password" name="password"
                         class="form-control" autocomplete="new-password" maxlength="64"
                         placeholder="<?= gettext("Optional — only needed if the VPN requires a password") ?>" />
                </div>
              </div>

              <hr />

              <div class="form-group">
                <div class="col-sm-offset-2 col-sm-10">
                  <button type="submit" class="btn btn-primary btn-lg">
                    <span class="fa fa-search"></span>
                    <?= gettext("Parse File") ?>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

      <?php /* ====================================================
             STEP 2 — Preview + Conflict Resolution
             ==================================================== */ ?>
      <?php elseif ($step === 'preview' && $parsed !== null): ?>

        <?php
        $d = $parsed['directives'];
        $b = $parsed['blocks'];

        $prev_server = '';
        if (!empty($d['remote'])) {
            $remotes     = is_array($d['remote']) ? $d['remote'] : array($d['remote']);
            $prev_server = $remotes[0];
        }
        $prev_proto       = !empty($d['proto'])  ? ovp_map_protocol($d['proto'])  : 'UDP';
        $prev_dev         = !empty($d['dev'])     ? ovp_map_dev_mode($d['dev'])    : 'tun';
        $prev_cipher      = !empty($d['cipher'])  ? ovp_map_cipher($d['cipher'])  : '(not specified)';
        $prev_auth        = !empty($d['auth'])     ? ovp_map_digest($d['auth'])    : 'SHA256 (default)';
        $prev_compression = ovp_map_compression($d);
        $prev_tls_dir     = ovp_map_tls_direction($d);
        $prev_has_tls     = !empty($b['tls-auth']) ? 'tls-auth' : (!empty($b['tls-crypt']) ? 'tls-crypt' : 'No');
        $prev_description = htmlspecialchars(isset($ovp_state['description']) ? $ovp_state['description'] : 'OVP Import', ENT_QUOTES, 'UTF-8');
        $prev_interface   = htmlspecialchars(isset($ovp_state['interface'])   ? $ovp_state['interface']   : 'wan',        ENT_QUOTES, 'UTF-8');
        $prev_filename    = htmlspecialchars(isset($ovp_state['filename'])    ? $ovp_state['filename']    : '',           ENT_QUOTES, 'UTF-8');

        $any_conflict = (!empty($conflicts['ca']['duplicate'])
                      || !empty($conflicts['cert']['duplicate'])
                      || !empty($conflicts['client']['duplicate']));
        ?>

        <?php if ($any_conflict): ?>
          <div class="alert alert-warning" role="alert">
            <strong><span class="fa fa-exclamation-triangle"></span>
            <?= gettext("Duplicate items detected!") ?></strong>
            <?= gettext("One or more items from this .ovpn file already exist in pfSense. Review the conflict table below and uncheck any items you do not want to re-import.") ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($parsed['errors'])): ?>
          <div class="alert alert-info" role="alert">
            <strong><?= gettext("Notes:") ?></strong>
            <ul style="margin-bottom:0; margin-top:5px;">
              <?php foreach ($parsed['errors'] as $w): ?>
                <li><?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" action="/packages/ovp/ovp_upload.php">
          <input type="hidden" name="action" value="import" />
          <input type="hidden" name="ovp_state_data"
                 value="<?= htmlspecialchars(base64_encode(serialize($ovp_state)), ENT_QUOTES, 'UTF-8') ?>" />

          <div class="panel panel-<?= $any_conflict ? 'warning' : 'success' ?>">
            <div class="panel-heading">
              <h2 class="panel-title">
                <span class="fa fa-<?= $any_conflict ? 'exclamation-triangle' : 'check-circle' ?>"></span>
                <?= gettext("Step 2a — Conflict Check: Choose What to Import") ?>
              </h2>
            </div>
            <div class="panel-body">
              <p class="text-muted">
                <?= gettext("Items marked") ?>
                <span class="label label-warning"><?= gettext("Already exists") ?></span>
                <?= gettext("are already present in pfSense. By default they will NOT be re-imported. Check the box to force a new copy.") ?>
                <?= gettext("Items marked") ?>
                <span class="label label-success"><?= gettext("New") ?></span>
                <?= gettext("are new and will be imported.") ?>
              </p>

              <table class="table table-bordered table-condensed">
                <thead>
                  <tr>
                    <th style="width:25%"><?= gettext("Item") ?></th>
                    <th><?= gettext("Status") ?></th>
                    <th style="width:28%" class="text-center"><?= gettext("Action") ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php ovp_conflict_row('ca',     'CA Certificate',     $conflicts['ca'],     !empty($b['ca'])); ?>
                  <?php ovp_conflict_row('cert',   'Client Certificate', $conflicts['cert'],   !empty($b['cert'])); ?>
                  <?php ovp_conflict_row('client', 'OpenVPN Client',     $conflicts['client'], true); ?>
                </tbody>
              </table>

              <?php if (!empty($conflicts['client']['duplicate'])): ?>
                <div class="alert alert-warning" style="margin-bottom:0;">
                  <span class="fa fa-info-circle"></span>
                  <?= gettext("An existing OpenVPN client already connects to") ?>
                  <strong><?= htmlspecialchars($prev_server, ENT_QUOTES, 'UTF-8') ?></strong>
                  <?= gettext("using") ?> <strong><?= htmlspecialchars($prev_proto, ENT_QUOTES, 'UTF-8') ?></strong>
                  (<?= gettext("VPN ID") ?> #<?= (int)(isset($conflicts['client']['vpnid']) ? $conflicts['client']['vpnid'] : 0) ?>,
                  &ldquo;<?= htmlspecialchars(isset($conflicts['client']['descr']) ? $conflicts['client']['descr'] : '', ENT_QUOTES, 'UTF-8') ?>&rdquo;).
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="panel panel-default">
            <div class="panel-heading">
              <h2 class="panel-title">
                <span class="fa fa-eye"></span>
                <?= gettext("Step 2b — Parsed Configuration Summary") ?>
              </h2>
            </div>
            <div class="panel-body">
              <p><?= gettext("Extracted from") ?> <strong><?= $prev_filename ?></strong>.</p>

              <h4><?= gettext("Connection") ?></h4>
              <table class="table table-striped table-condensed">
                <tbody>
                  <?php ovp_row("Description",     $prev_description); ?>
                  <?php ovp_row("Interface",        $prev_interface); ?>
                  <?php ovp_row("Server (remote)",  $prev_server, true); ?>
                  <?php ovp_row("Protocol",         $prev_proto); ?>
                  <?php ovp_row("Device Mode",      $prev_dev); ?>
                </tbody>
              </table>

              <h4><?= gettext("Cryptography") ?></h4>
              <table class="table table-striped table-condensed">
                <tbody>
                  <?php ovp_row("Cipher",        $prev_cipher); ?>
                  <?php ovp_row("Auth Digest",   $prev_auth); ?>
                  <?php ovp_row("Compression",   $prev_compression !== '' ? $prev_compression : 'None'); ?>
                  <?php ovp_row("TLS Key Type",  $prev_has_tls); ?>
                  <?php ovp_row("TLS Direction", $prev_tls_dir !== '' ? $prev_tls_dir : 'Not set'); ?>
                </tbody>
              </table>

              <h4><?= gettext("Embedded Certificates &amp; Keys") ?></h4>
              <table class="table table-striped table-condensed">
                <tbody>
                  <?php ovp_row("CA Certificate",     !empty($b['ca'])   ? 'Present' : 'Not found'); ?>
                  <?php ovp_row("Client Certificate", !empty($b['cert']) ? 'Present' : 'Not found'); ?>
                  <?php ovp_row("Client Key",         !empty($b['key'])  ? 'Present' : 'Not found'); ?>
                </tbody>
              </table>

              <h4><?= gettext("Other Directives") ?></h4>
              <table class="table table-striped table-condensed">
                <tbody>
                  <?php ovp_row("nobind",       isset($d['nobind'])       ? 'Yes' : 'No'); ?>
                  <?php ovp_row("persist-key",  isset($d['persist-key'])  ? 'Yes' : 'No'); ?>
                  <?php ovp_row("persist-tun",  isset($d['persist-tun'])  ? 'Yes' : 'No'); ?>
                  <?php ovp_row("resolv-retry", isset($d['resolv-retry']) ? (string)$d['resolv-retry'] : 'Not set'); ?>
                  <?php ovp_row("keepalive",    isset($d['keepalive'])    ? (string)$d['keepalive']    : 'Not set'); ?>
                  <?php ovp_row("verb",         isset($d['verb'])         ? (string)$d['verb']         : 'Not set'); ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="panel panel-default">
            <div class="panel-body">
              <button type="submit" class="btn btn-success btn-lg">
                <span class="fa fa-check"></span>
                <?= gettext("Apply Selected Items") ?>
              </button>
              &nbsp;
              <button type="submit" name="action" value="cancel"
                      class="btn btn-default btn-lg" formnovalidate="formnovalidate">
                <span class="fa fa-times"></span>
                <?= gettext("Cancel") ?>
              </button>
            </div>
          </div>

        </form>

      <?php /* ====================================================
             STEP 3 — Result
             ==================================================== */ ?>
      <?php elseif ($step === 'done' && $result !== null): ?>

        <?php if ($result['success']): ?>
          <div class="alert alert-success" role="alert">
            <strong><?= gettext("Import Complete!") ?></strong>
            <?php if ($result['vpnid'] !== null): ?>
              <?= sprintf(gettext("OpenVPN client instance #%d has been created and is now active."), (int)$result['vpnid']) ?>
              <a href="/vpn_openvpn_client.php" class="alert-link">
                <?= gettext("View OpenVPN Clients") ?> &rarr;
              </a>
            <?php else: ?>
              <?= gettext("Selected items were processed. No new OpenVPN client was created (skipped).") ?>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="alert alert-danger" role="alert">
            <strong><?= gettext("Import Failed.") ?></strong>
            <ul style="margin-bottom:0; margin-top:5px;">
              <?php foreach ($result['errors'] as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!empty($result['warnings'])): ?>
          <div class="alert alert-warning" role="alert">
            <strong><?= gettext("Warnings:") ?></strong>
            <ul style="margin-bottom:0; margin-top:5px;">
              <?php foreach ($result['warnings'] as $w): ?>
                <li><?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!empty($result['actions'])): ?>
          <div class="panel panel-default">
            <div class="panel-heading">
              <h2 class="panel-title">
                <span class="fa fa-list-ul"></span>
                <?= gettext("Action Log") ?>
              </h2>
            </div>
            <div class="panel-body">
              <table class="table table-condensed table-striped">
                <tbody>
                  <?php foreach ($result['actions'] as $i => $action): ?>
                    <tr>
                      <td style="width:40px;" class="text-muted"><?= $i + 1 ?></td>
                      <td><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <div class="panel panel-default">
          <div class="panel-body">
            <a href="/packages/ovp/ovp_upload.php" class="btn btn-primary">
              <span class="fa fa-upload"></span>
              <?= gettext("Import Another .ovpn File") ?>
            </a>
            &nbsp;
            <a href="/vpn_openvpn_client.php" class="btn btn-default">
              <span class="fa fa-list"></span>
              <?= gettext("Go to OpenVPN Clients") ?>
            </a>
          </div>
        </div>

      <?php endif; ?>

    </div><!-- /container-fluid -->
  </div><!-- /main -->
</div><!-- /wrap -->

<?php include("foot.inc"); ?>
