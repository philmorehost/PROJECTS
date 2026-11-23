<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");

    header('Cache-Control: no-store, private');
    require_once __DIR__ . '/../func/bc-app-update-validation.php';
    require_once __DIR__ . '/../func/bc-app-update-security.php';
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        bc_validate_csrf();
        if (isset($_POST['app_update_send_code'])) {
            header('Content-Type: application/json; charset=UTF-8');
            $context=(string)$_POST['app_update_send_code'];
            $error='Invalid update action.';
            if (in_array($context,array_values(bc_app_update_contexts()),true)) {
                $error=bc_stepup_send_email_otp((int)$get_logged_admin_details['id'],(string)$get_logged_admin_details['email'],(string)$get_logged_admin_details['email'],$context,'App update confirmation');
            }
            echo json_encode(['status'=>$error===''?'success':'error','message'=>$error===''?'OTP SENT':$error]);exit;
        }
        $actions=array_intersect(array_keys(bc_app_update_contexts()),array_keys($_POST));
        $error=count($actions)===1 ? bc_app_update_stepup($get_logged_admin_details,bc_app_update_contexts()[reset($actions)],$_POST) : 'Choose one update action.';
        if($error!==''){$_SESSION['product_purchase_response']='Error: '.$error;header('Location: AppUpdateBroadcast.php');exit;}
    }

    // ── Helper: generate a Google OAuth2 JWT for FCM v1 API ───────────────
    function _fcm_base64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    function generateFcmAccessToken($service_account_json) {
        $sa = json_decode($service_account_json, true);
        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            return null;
        }

        $now     = time();
        $header  = _fcm_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = _fcm_base64url(json_encode([
            'iss'   => $sa['client_email'],
            'sub'   => $sa['client_email'],
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ]));

        $signing_input = "$header.$payload";
        $private_key   = openssl_pkey_get_private($sa['private_key']);
        if (!$private_key) return null;

        if (!openssl_sign($signing_input, $signature, $private_key, 'SHA256')) return null;
        $jwt = $signing_input . '.' . _fcm_base64url($signature);

        // Exchange JWT for access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;

        $data = json_decode($resp, true);
        return $data['access_token'] ?? null;
    }

    function sendFcmTopicMessage($project_id, $access_token, $topic, $data_payload) {
        $body = json_encode([
            'message' => [
                'topic' => $topic,
                'data'  => $data_payload,
            ],
        ]);
        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer $access_token",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $http_code, 'body' => json_decode($resp, true)];
    }

    // ── Load saved FCM settings ────────────────────────────────────────────
    $vendor_id  = (int) $get_logged_admin_details["id"];
    $stmt = $connection_server->prepare("SELECT * FROM sas_fcm_settings WHERE vendor_id=? LIMIT 1");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $fcm_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ── Handle Save FCM Settings ───────────────────────────────────────────
    // ── Load app-update settings (update source: local /apk directory vs Google Play) ──
    // Local host (directory) is the DEFAULT, so an install that never saved this card behaves
    // exactly as before this setting existed.
    $app_update_settings = null;
    $q_app_update = mysqli_query($connection_server, "SELECT * FROM sas_app_update_settings WHERE vendor_id='$vendor_id' LIMIT 1");
    if ($q_app_update && mysqli_num_rows($q_app_update) > 0) {
        $app_update_settings = mysqli_fetch_assoc($q_app_update);
    }
    $update_source       = (($app_update_settings["update_source"] ?? "local") === "play") ? "play" : "local";
    $play_store_url      = trim($app_update_settings["play_store_url"] ?? "");
    $update_version_code = (int) ($app_update_settings["apk_version_code"] ?? 0);
    if ($update_version_code <= 0) { $update_version_code = 1; }
    $update_version_name = trim($app_update_settings["apk_version_name"] ?? "");
    if ($update_version_name === "") { $update_version_name = "1.0.0"; }
    $update_apk_filename = trim($app_update_settings["apk_filename"] ?? "");
    if ($update_apk_filename === "") {
        // Brand-aware default - see bc_app_brand_slug(). Must match the "<brand>-<version>.apk"
        // the app requests, so this can no longer be one hardcoded brand name.
        $__brand_slug = function_exists("bc_app_brand_slug") ? bc_app_brand_slug($connection_server, $vendor_id) : "";
        $update_apk_filename = ($__brand_slug !== "" ? $__brand_slug . "-" : "") . $update_version_name . ".apk";
    }
    $update_changelog    = trim($app_update_settings["changelog"] ?? "");
    $__update_host       = function_exists("bc_safe_host") ? bc_safe_host() : ($_SERVER["HTTP_HOST"] ?? "localhost");

    // ── Handle Save App Update Settings ───────────────────────────────────
    if (isset($_POST["save-update-settings"])) {
        try {
            $release=bc_app_update_release($_POST);
            $source_in=$release['source'];$play_url_in=$release['url'];$version_code_in=$release['code'];
            $version_name_in=$release['name'];$apk_file_in=$release['file'];$changelog_in=$release['changelog'];
            $stmt = $connection_server->prepare(
                "INSERT INTO sas_app_update_settings
                    (vendor_id, update_source, play_store_url, apk_version_code, apk_version_name, apk_filename, changelog, date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    update_source=VALUES(update_source), play_store_url=VALUES(play_store_url),
                    apk_version_code=VALUES(apk_version_code), apk_version_name=VALUES(apk_version_name),
                    apk_filename=VALUES(apk_filename), changelog=VALUES(changelog), date=NOW()"
            );
            if ($stmt) {
                $stmt->bind_param("ississs", $vendor_id, $source_in, $play_url_in, $version_code_in, $version_name_in, $apk_file_in, $changelog_in);
                $saved=$stmt->execute();
                $stmt->close();
                if (!$saved) throw new RuntimeException("save_failed");
                $_SESSION["product_purchase_response"] = ($source_in === "play")
                    ? "App update source saved: Google Play Store."
                    : "App update source saved: local host (directory).";
            } else {
                $_SESSION["product_purchase_response"] = "Error: could not save app update settings.";
            }
        }
        catch (InvalidArgumentException $e) { $_SESSION['product_purchase_response']='Error: '.$e->getMessage(); }
        catch (Throwable $e) { $_SESSION['product_purchase_response']='Error: Could not confirm settings saved. Refresh before retrying.'; }
        header("Location: AppUpdateBroadcast.php");
        exit;
    }

    if (isset($_POST["save-fcm-settings"])) {
        try {
            $project_id=trim((string)($_POST['project_id']??''));
            $raw=trim((string)($_POST['service_account_json']??''));
            if ($raw==='') {
                if (!$fcm_row || $project_id!==$fcm_row['project_id']) throw new InvalidArgumentException('Provide a service-account key when changing projects.');
                $_SESSION['product_purchase_response']='Saved Firebase key retained. No credentials were changed.';
            } else {
                $normalized=bc_app_update_fcm_account($project_id,$raw);
                if (empty($GLOBALS['mySqlPass']) || !function_exists('bc_stepup_seal_secret')) throw new RuntimeException('secure_storage_unavailable');
                $sealed=bc_stepup_seal_secret($normalized);
                if (strpos($sealed,'gcm:')!==0) throw new RuntimeException('secure_storage_unavailable');
                $stmt=$connection_server->prepare("INSERT INTO sas_fcm_settings (vendor_id,project_id,service_account_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE project_id=VALUES(project_id),service_account_json=VALUES(service_account_json)");
                if(!$stmt)throw new RuntimeException('save_failed');
                $stmt->bind_param('iss',$vendor_id,$project_id,$sealed);
                $saved=$stmt->execute();$stmt->close();
                if(!$saved)throw new RuntimeException('save_failed');
                $_SESSION['product_purchase_response']='FCM settings saved. The private key will not be redisplayed.';
            }
        } catch(InvalidArgumentException $e) { $_SESSION['product_purchase_response']='Error: '.$e->getMessage(); }
        catch(Throwable $e) { $_SESSION['product_purchase_response']='Error: Could not validate or securely save Firebase settings. Check OpenSSL and server secret configuration, then refresh before retrying.'; }
        header('Location: AppUpdateBroadcast.php');exit;
    }

    // ── Handle Broadcast Update Notification ──────────────────────────────
    if (isset($_POST["broadcast-update"])) {
        if (empty($fcm_row)) {
            $_SESSION["product_purchase_response"] = "Error: FCM settings not configured. Save your Firebase project ID and Service Account JSON first.";
        } else {
            try {
                bc_app_update_release(['update_source'=>$update_source,'play_store_url'=>$play_store_url,'apk_version_code'=>$update_version_code,'apk_version_name'=>$update_version_name,'apk_filename'=>$update_apk_filename,'changelog'=>$update_changelog]);
                $raw=bc_stepup_open_secret($fcm_row['service_account_json']);
                $account=bc_app_update_fcm_account($fcm_row['project_id'],$raw);
                // app_updates is a project-wide topic: refuse a project shared by other tenants.
                $project=mysqli_real_escape_string($connection_server,$fcm_row['project_id']);
                $shared=mysqli_query($connection_server,"SELECT vendor_id FROM sas_fcm_settings WHERE project_id='$project' AND vendor_id<>'$vendor_id' LIMIT 1");
                if(!$shared || mysqli_num_rows($shared)>0)throw new RuntimeException('shared_project');
                if ($update_source==='local' && !is_file(__DIR__.'/../apk/'.$update_apk_filename))throw new RuntimeException('apk_missing');
            } catch(Throwable $e) {
                $_SESSION['product_purchase_response']='Error: Verify the saved release, APK and Firebase key. Topic broadcasts require a dedicated Firebase project, not one shared by multiple vendors.';
                header('Location: AppUpdateBroadcast.php');exit;
            }
            $access_token = generateFcmAccessToken($account);
            if (!$access_token) {
                $_SESSION["product_purchase_response"] = "Error: Could not obtain FCM access token. Check your Service Account JSON.";
            } else {
                // Tell the app which link the update comes from, so a Play-distributed build can open
                // the store listing while a self-hosted build keeps using the local /apk directory.
                $local_apk_dir_url = "https://" . $__update_host . "/apk/";
                $result = sendFcmTopicMessage(
                    $fcm_row["project_id"],
                    $access_token,
                    "app_updates",
                    [
                        "type"          => "app_update",
                        "update_source" => $update_source,
                        "store_url"     => ($update_source === "play" ? $play_store_url : $local_apk_dir_url),
                        "version_code"  => (string) $update_version_code,
                        "version_name"  => (string) $update_version_name,
                    ]
                );
                if ($result["code"] === 200 && !empty($result['body']['name'])) {
                    $_SESSION["product_purchase_response"] = "Firebase accepted the update notification. Delivery to every device is not guaranteed." . ($update_source === "play" ? " (source: Google Play Store)" : " (source: local host directory)");
                } else {
                    $_SESSION["product_purchase_response"] = "Firebase delivery was not confirmed. Check Firebase delivery logs before retrying to avoid duplicate notifications.";
                }
            }
        }
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit;
    }

    // Reload saved row after any saves
    $stmt = $connection_server->prepare("SELECT * FROM sas_fcm_settings WHERE vendor_id=? LIMIT 1");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $fcm_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Registered device count for this vendor
    $stmt = $connection_server->prepare(
        "SELECT COUNT(*) AS cnt FROM sas_device_tokens WHERE vendor_id=?"
    );
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $device_count_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $device_count = (int) ($device_count_row["cnt"] ?? 0);
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("App Update Broadcast | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "App Update Broadcast", "crumb" => "Home / App Update Broadcast")); ?>

    <section class="section">
      <div class="row">

        <!-- Broadcast card -->
        <div class="col-12 col-md-6">
          <div class="card info-card px-4 py-4">
            <h5 class="card-title">📣 Broadcast Update Notification</h5>
            <p class="text-muted">
              Sends a push notification to all users who have your app installed.
              Compatible online apps check the saved release when Firebase delivers the message. Delivery and installation are not guaranteed.
            </p>
            <p>
              <strong>Registered devices:</strong>
              <span class="badge bg-primary fs-6"><?php echo $device_count; ?></span>
            </p>
            <div class="alert alert-info">
              <strong>Before broadcasting (source: <?php echo ($update_source === "play" ? "Google Play Store" : "Local host directory"); ?>):</strong><br/>
              <?php if ($update_source === "play"): ?>
                1. Publish the new build on Google Play and wait for it to go live<br/>
                2. Check the <em>Google Play Store URL</em> and version below match that release<br/>
                3. Click Broadcast below — users are sent to your Play Store listing
              <?php else: ?>
                1. Upload your new APK to cPanel at <code>/public_html/apk/<?php echo htmlspecialchars($update_apk_filename); ?></code><br/>
                2. Keep the version below higher than the installed build<br/>
                3. Click Broadcast below — Firebase delivery is best effort.
              <?php endif; ?>
            </div>
            <?php if (!$fcm_row): ?>
            <div class="alert alert-warning">
                ⚠️ FCM is not yet configured. Fill in your Firebase settings below first.
            </div>
            <?php endif; ?>
            <form method="post" action="">
                <?php bc_app_update_security_fields($get_logged_admin_details,'app_release_broadcast'); ?>
                <button name="broadcast-update" type="submit"
                    class="btn btn-success col-12"
                    <?php echo (!$fcm_row ? 'disabled' : ''); ?>
                    onclick="return confirm('Send update notification to all <?php echo $device_count; ?> registered devices?')">
                    🚀 Broadcast Update Notification
                </button>
            </form>
          </div>
        </div>

        <!-- FCM settings card -->
        <div class="col-12 col-md-6">
          <div class="card info-card px-4 py-4">
            <h5 class="card-title">⚙️ Firebase (FCM) Settings</h5>
            <p class="text-muted">
              Required for sending push notifications. Get these from your
              <a href="https://console.firebase.google.com/" target="_blank">Firebase Console</a>:
              <br/>• <strong>Project ID</strong>: Firebase project settings → General → Project ID
              <br/>• <strong>Service Account JSON</strong>: Project settings → Service accounts → Generate new private key
            </p>
            <form method="post" action="">
                <div class="mb-3">
                    <label class="form-label">Firebase Project ID</label>
                    <input type="text" name="project_id" class="form-control"
                        placeholder="e.g. my-app-12345"
                        value="<?php echo htmlspecialchars($fcm_row["project_id"] ?? ""); ?>" required/>
                </div>
                <div class="mb-3">
                    <label class="form-label">Service Account JSON</label>
                    <textarea name="service_account_json" class="form-control" rows="8"
                        placeholder='Paste the contents of the service account JSON key file here...'
                        autocomplete="off"></textarea>
                    <div class="form-text">Leave blank to retain the saved key. Paste a replacement only to rotate it. New keys are encrypted at rest; saved private keys are never shown here.</div>
                </div>
                <?php bc_app_update_security_fields($get_logged_admin_details,'app_fcm_save'); ?>
                <button name="save-fcm-settings" type="submit" class="btn btn-primary col-12">
                    💾 Save FCM Settings
                </button>
            </form>
          </div>
        </div>

        <!-- App update source card -->
        <div class="col-12">
          <div class="card info-card px-4 py-4">
            <h5 class="card-title">📦 App Update Source</h5>
            <p class="text-muted">
              Choose where users are sent when you broadcast an update.
              <strong>Local host (directory)</strong> is the default and serves the APK you upload to
              <code>/apk/</code>. <strong>Google Play</strong> sends users to your store listing instead, so no
              APK upload is needed — but the version below must still be higher than the installed build.
            </p>
            <form method="post" action="">
              <div class="mb-3">
                <label class="form-label d-block">Update source</label>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="update_source" id="src-local" value="local"
                    <?php echo ($update_source === "local" ? "checked" : ""); ?>>
                  <label class="form-check-label" for="src-local">Local host (directory) — default</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="update_source" id="src-play" value="play"
                    <?php echo ($update_source === "play" ? "checked" : ""); ?>>
                  <label class="form-check-label" for="src-play">Google Play Store</label>
                </div>
              </div>

              <div class="mb-3" id="play-url-wrap">
                <label class="form-label">Google Play Store URL</label>
                <input type="url" name="play_store_url" class="form-control"
                  placeholder="https://play.google.com/store/apps/details?id=com.example.app"
                  value="<?php echo htmlspecialchars($play_store_url); ?>"/>
                <div class="form-text">Required while the source is Google Play. Only official HTTPS Google Play app-details links are accepted.</div>
              </div>

              <hr/>
              <p class="text-muted mb-2"><strong>Release details</strong> (used by <code>web/api/app-update.php</code>)</p>
              <div class="row">
                <div class="col-md-3 mb-3">
                  <label class="form-label">Version code</label>
                  <input type="number" min="1" max="2147483647" name="apk_version_code" class="form-control"
                    value="<?php echo (int) $update_version_code; ?>"/>
                  <div class="form-text">Must be higher than the installed build.</div>
                </div>
                <div class="col-md-3 mb-3">
                  <label class="form-label">Version name</label>
                  <input type="text" name="apk_version_name" class="form-control"
                    value="<?php echo htmlspecialchars($update_version_name); ?>"/>
                </div>
                <div class="col-md-6 mb-3" id="apk-file-wrap">
                  <label class="form-label">APK filename (local host only)</label>
                  <input type="text" name="apk_filename" class="form-control"
                    placeholder="<?php echo htmlspecialchars($update_apk_filename); ?>"
                    value="<?php echo htmlspecialchars($update_apk_filename); ?>"/>
                  <div class="form-text">Must exist in <code>/apk/</code>; otherwise the app is told it is up to date.</div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Changelog (optional, shown in the update dialog)</label>
                <textarea name="changelog" class="form-control" rows="3"><?php echo htmlspecialchars($update_changelog); ?></textarea>
              </div>
                <?php bc_app_update_security_fields($get_logged_admin_details,'app_release_save'); ?>
              <button name="save-update-settings" type="submit" class="btn btn-primary col-12">
                💾 Save App Update Settings
              </button>
              <div class="form-text mt-3">
                Note: the mobile app must understand <code>update_source</code> / <code>play_url</code> to open the
                store from the push notification. Older builds simply see no update prompt in Google Play mode
                (they are never pointed at the wrong file), and keep working with Local host.
              </div>
            </form>
          </div>
        </div>

      </div><!-- /row -->
    </section>

    <script>
      // Keep the local-only fields visually tied to the chosen source.
      (function () {
        var local = document.getElementById('src-local');
        var play  = document.getElementById('src-play');
        var file  = document.getElementById('apk-file-wrap');
        var url   = document.getElementById('play-url-wrap');
        function sync() {
          var isPlay = play && play.checked;
          if (file) file.style.opacity = isPlay ? '0.5' : '1';
          if (url)  url.style.opacity  = isPlay ? '1' : '0.75';
        }
        if (local) local.addEventListener('change', sync);
        if (play)  play.addEventListener('change', sync);
        sync();
      })();
    </script>

    



<?php bc_adm_foot(array("area" => "admin")); ?>

<script src="../asset/bc-app-update-otp.js" defer></script>
