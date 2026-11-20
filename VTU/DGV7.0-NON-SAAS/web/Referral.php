<?php session_start();
include("../func/bc-config.php");
include_once(__DIR__ . "/../func/bc-fintech-services.php");

if (function_exists('isServiceEnabled') && !isServiceEnabled('referral')) {
    $_SESSION["product_purchase_response"] = "The referral program is currently unavailable.";
    header("Location: Dashboard.php");
    exit();
}

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}
$vid = (int)$get_logged_user_details['vendor_id'];
$svc_user = $get_logged_user_details;

bc_referral_ensure_schema();
include_once(__DIR__ . "/../func/bc-plate-app.php");
$ref_link = bc_app_root_url("Register.php") . "?referral=" . urlencode((string)$svc_user['username']);
$stats = bc_referral_progress_for_user($vid, (int)$svc_user['id']);
$ref_cfg = bc_referral_settings($vid);
$ref_reward_fmt = number_format((float)$ref_cfg['reward_usd'], 2);
$ref_qual_fmt = number_format((float)$ref_cfg['qualifying_usd'], 2);
$notice = ''; $notice_type = 'info';
if (isset($_POST['apply_creator'])) {
    $full_name = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['full_name'] ?? '')));
    $email = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['email'] ?? '')));
    $platform = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['platform'] ?? '')));
    $handle = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['handle'] ?? '')));
    $followers = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['followers'] ?? '')));
    $pitch = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['pitch'] ?? '')));
    $uid = (int)$svc_user['id'];
    if ($full_name === '' || !filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) || $handle === '') {
        $notice = 'Please complete your name, a valid email and your main social handle.';
        $notice_type = 'danger';
    } elseif (mysqli_query($connection_server, "INSERT INTO sas_creator_applications
        (vendor_id, user_id, full_name, email, platform, handle, followers, pitch, status, created_at)
        VALUES ('$vid', '$uid', '$full_name', '$email', '$platform', '$handle', '$followers', '$pitch', 'pending', NOW())")) {
        $notice = 'Application received. Our partnerships team will review your profile and reach out. T&Cs apply.';
        $notice_type = 'success';
    } else {
        $notice = 'We could not save your application. Please try again.';
        $notice_type = 'danger';
    }
}
bc_app_head("Refer & Earn | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "REFER & EARN", "crumb" => "Home / Refer & Earn"));
?>
<section class="section dashboard">
    <div class="card border-0 shadow-sm rounded-4 mb-4 text-white" style="background: linear-gradient(135deg, #0b1220, #312e81);">
        <div class="card-body p-4 p-md-5">
            <span class="badge bg-warning text-dark mb-2">REFERRAL PROGRAM</span>
            <h3 class="fw-bold">You get $<?php echo $ref_reward_fmt; ?>. Your friend gets $<?php echo $ref_reward_fmt; ?>.</h3>
            <p class="opacity-75 mb-0">When someone you refer completes a minimum of <strong>$<?php echo $ref_qual_fmt; ?> in Crypto &amp; International Transfers</strong>, you BOTH receive $<?php echo $ref_reward_fmt; ?>. Other products do not count toward the $<?php echo $ref_qual_fmt; ?>.</p>
        </div>
    </div>

    <div class="row g-3 mb-4 text-center">
        <div class="col-md-4"><div class="p-4 bg-white border rounded-4 h-100 shadow-sm"><i class="bi bi-share-fill fs-3 text-primary"></i><strong class="d-block mt-2">1 · Share your link</strong><span class="small text-muted">Send your personal referral link to friends.</span></div></div>
        <div class="col-md-4"><div class="p-4 bg-white border rounded-4 h-100 shadow-sm"><i class="bi bi-graph-up-arrow fs-3 text-primary"></i><strong class="d-block mt-2">2 · They transact $<?php echo $ref_qual_fmt; ?></strong><span class="small text-muted">In Crypto or International Transfers — other products don't count.</span></div></div>
        <div class="col-md-4"><div class="p-4 bg-white border rounded-4 h-100 shadow-sm"><i class="bi bi-cash-coin fs-3 text-primary"></i><strong class="d-block mt-2">3 · You both get $<?php echo $ref_reward_fmt; ?></strong><span class="small text-muted">$<?php echo $ref_reward_fmt; ?> lands in your wallet and $<?php echo $ref_reward_fmt; ?> in theirs, automatically.</span></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <h6 class="fw-bold mb-3">Your referral link</h6>
                <div class="input-group mb-4">
                    <input type="text" class="form-control" id="refLinkPage" value="<?php echo htmlspecialchars($ref_link); ?>" readonly>
                    <button class="btn btn-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('refLinkPage').value)">Copy link</button>
                </div>
                <div class="row text-center g-3 mb-4">
                    <div class="col-4"><div class="h4 fw-bold mb-0"><?php echo (int)$stats['referred']; ?></div><span class="small text-muted">People referred</span></div>
                    <div class="col-4"><div class="h4 fw-bold mb-0"><?php echo (int)$stats['qualified']; ?></div><span class="small text-muted">Qualified ($<?php echo $ref_qual_fmt; ?>+)</span></div>
                    <div class="col-4"><div class="h4 fw-bold mb-0">$<?php echo number_format($stats['earned_usd'], 2); ?></div><span class="small text-muted">Earned so far</span></div>
                </div>
                <?php if ($stats['rows']): ?>
                <h6 class="fw-bold mb-2">Referral progress</h6>
                <?php foreach ($stats['rows'] as $r): $pct = min(100, ((float)$r['qualifying_usd'] / max(1.0, (float)$ref_cfg['qualifying_usd'])) * 100); ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small"><span><strong>@<?php echo htmlspecialchars($r['referee_name'] ?? 'user'); ?></strong></span><span>$<?php echo number_format((float)$r['qualifying_usd'], 2); ?> / $<?php echo $ref_qual_fmt; ?> <?php echo (int)$r['rewarded'] === 1 ? '· <span class="text-success fw-bold">Rewarded</span>' : ''; ?></span></div>
                        <div class="progress rounded-pill" style="height:8px;"><div class="progress-bar" style="width: <?php echo $pct; ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="small text-muted mb-0">No referrals yet. Share your link to get started.</p>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <span class="badge bg-dark mb-2">CREATORS &amp; INFLUENCERS</span>
                <h6 class="fw-bold">Promote the referral program. Get paid.</h6>
                <p class="small text-muted">Models, content creators and influencers can apply to promote this program for paid partnerships. T&amp;Cs apply.</p>
                <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
                <form method="post">
                    <div class="mb-2"><input type="text" name="full_name" class="form-control" placeholder="Full name" required></div>
                    <div class="mb-2"><input type="email" name="email" class="form-control" placeholder="Email address" required></div>
                    <div class="mb-2"><select name="platform" class="form-select"><option>Instagram</option><option>TikTok</option><option>YouTube</option><option>X (Twitter)</option><option>Facebook</option><option>Other</option></select></div>
                    <div class="mb-2"><input type="text" name="handle" class="form-control" placeholder="Social handle (e.g. @yourhandle)" required></div>
                    <div class="mb-2"><input type="text" name="followers" class="form-control" placeholder="Follower count (e.g. 25k)"></div>
                    <div class="mb-3"><textarea name="pitch" class="form-control" rows="3" placeholder="How would you promote the referral program?"></textarea></div>
                    <button type="submit" name="apply_creator" class="btn btn-dark w-100 rounded-pill fw-bold">Apply to Promote</button>
                </form>
            </div></div>
        </div>
    </div>
</section>
<?php bc_app_foot(); ?>
