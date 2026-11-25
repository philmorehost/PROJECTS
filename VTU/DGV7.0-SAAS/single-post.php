<?php
    // --- DATABASE CONNECTION & VENDOR DETAILS ---
    include_once("func/bc-connect.php");

    $vendor_account_details = null;
    if ($connection_server) {
        $host = $_SERVER["HTTP_HOST"];
        $stmt_vendor = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE website_url = ? AND status = 1 LIMIT 1");
        mysqli_stmt_bind_param($stmt_vendor, "s", $host);
        mysqli_stmt_execute($stmt_vendor);
        $result_vendor = mysqli_stmt_get_result($stmt_vendor);
        if ($row = mysqli_fetch_assoc($result_vendor)) {
            $vendor_account_details = $row;
        }
        mysqli_stmt_close($stmt_vendor);
    }
    $current_vendor_id = $vendor_account_details['id'] ?? 0;

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        header("Location: blog.php");
        exit();
    }
    $post_id = (int)$_GET['id'];

    $stmt_post = mysqli_prepare($connection_server, "SELECT p.*, v.firstname, v.lastname FROM blog_posts p JOIN sas_vendors v ON p.author_id = v.id WHERE p.id = ? AND p.author_id = ? AND p.status = 'published'");
    mysqli_stmt_bind_param($stmt_post, "ii", $post_id, $current_vendor_id);
    mysqli_stmt_execute($stmt_post);
    $post_res = mysqli_stmt_get_result($stmt_post);

    if (mysqli_num_rows($post_res) === 0) {
        header("Location: blog.php");
        exit();
    }
    $post = mysqli_fetch_assoc($post_res);

    $related_posts = [];
    $related_sql = "SELECT id, title, featured_image, created_at FROM blog_posts WHERE author_id = ? AND id != ? AND status = 'published' ORDER BY created_at DESC LIMIT 3";
    $related_stmt = mysqli_prepare($connection_server, $related_sql);
    mysqli_stmt_bind_param($related_stmt, "ii", $current_vendor_id, $post_id);
    mysqli_stmt_execute($related_stmt);
    $related_posts_res = mysqli_stmt_get_result($related_stmt);
    while($rel = mysqli_fetch_assoc($related_posts_res)) $related_posts[] = $rel;

    $get_site_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$current_vendor_id' LIMIT 1"));
    require_once __DIR__ . '/func/bc-public-meridian.php';
    $m_ctx = bc_meridian_context();
    $site_title_post = trim((string)($get_site_details['site_title'] ?? ''));
    if ($site_title_post === '') $site_title_post = $m_ctx['site_name'];
    $personal_vars_post = bc_meridian_personal_style_vars($m_ctx['primary_color']);
    $js_config_post = array(
        'siteName' => $site_title_post,
        'appUrl' => $m_ctx['app_url'] ?? '',
        'downloadPageUrl' => '/DownloadApp.php',
        'businessUrl' => '/Business.php',
        'personalUrl' => '/',
        'registerUrl' => '/web/Register.php',
        'loginUrl' => '/web/Login.php',
        'rates' => $m_ctx['rates'] ?? array(),
    );
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-world="personal" style="<?php echo htmlspecialchars($personal_vars_post, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo htmlspecialchars($post['title']); ?> | <?php echo htmlspecialchars($site_title_post); ?></title>

    <link rel="stylesheet" href="/asset/meridian-1/meridian-1.css">
    <link rel="stylesheet" href="/asset/meridian-1/meridian-theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config_post, JSON_UNESCAPED_SLASHES); ?>;</script>
    <script type='text/javascript' src='https://platform-api.sharethis.com/js/sharethis.js#property=65c490a071c82a001275d46a&product=inline-share-buttons' async='async'></script>
    <style>
        .m-post-body { font-size: 1.06rem; line-height: 1.75; color: #E2E8F0; }
        .m-post-body p { margin-bottom: 18px; }
        .m-post-body h2, .m-post-body h3 { margin: 28px 0 12px; color: #FFFFFF; }
        .m-post-body a { color: #93C5FD; }
    </style>
</head>
<body>

    <?php echo bc_meridian_header_and_menu($m_ctx, 'personal'); ?>

    <main class="wrap m-sub-main" style="padding-top:96px">
        <article>
            <header class="m-sub-hero" style="text-align:center;max-width:860px;margin:0 auto">
                <p class="eyebrow"><i></i>Article Insight</p>
                <h1><?php echo htmlspecialchars($post['title']); ?></h1>
                <p class="small" style="color:var(--text3,#94A3B8)">
                    By <?php echo htmlspecialchars($post['firstname']); ?> · <?php echo date('M d, Y', strtotime($post['created_at'])); ?> · 6 min read
                </p>
            </header>

            <?php if(!empty($post['featured_image'])): ?>
            <div style="max-width:860px;margin:0 auto 28px;border-radius:26px;overflow:hidden;border:1px solid rgba(255,255,255,.14)">
                <img src="<?php echo htmlspecialchars($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" style="width:100%;max-height:520px;object-fit:cover;display:block">
            </div>
            <?php endif; ?>

            <div class="m-article-card">
                <div class="m-post-body">
                    <?php echo base64_decode($post['content']); ?>
                </div>
                <div style="border-top:1px solid rgba(255,255,255,.14);padding-top:22px;margin-top:34px">
                    <h4 style="margin:0 0 12px">Share this story</h4>
                    <div class="sharethis-inline-share-buttons"></div>
                </div>
            </div>

            <?php if(!empty($related_posts)): ?>
            <section class="chapter tight">
                <div class="chap-head">
                    <p class="eyebrow"><i></i>Continue Reading</p>
                    <h2 style="margin-top:10px">You might also like</h2>
                </div>
                <div class="m-blog-grid">
                    <?php foreach($related_posts as $rel): ?>
                    <article class="m-blog-card">
                        <a href="single-post.php?id=<?php echo (int)$rel['id']; ?>">
                            <img src="<?php echo htmlspecialchars($rel['featured_image']); ?>" alt="<?php echo htmlspecialchars($rel['title']); ?>" loading="lazy">
                        </a>
                        <div class="m-blog-card-body">
                            <h3 style="margin:0;font-size:18px">
                                <a href="single-post.php?id=<?php echo (int)$rel['id']; ?>" style="text-decoration:none"><?php echo htmlspecialchars($rel['title']); ?></a>
                            </h3>
                            <a class="bz-link" style="margin-top:auto" href="single-post.php?id=<?php echo (int)$rel['id']; ?>">Read Article →</a>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </article>
    </main>

    <?php echo bc_meridian_footer($m_ctx); ?>
    <script src="/asset/meridian-1/meridian-1.js"></script>
</body>
</html>
