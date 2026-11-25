<?php
    // --- DATABASE CONNECTION & VENDOR DETAILS ---
    include_once("func/bc-connect.php");

    $vendor_account_details = null;
    if ($connection_server) {
        $host = $_SERVER["HTTP_HOST"];
        $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE website_url = ? AND status = 1 LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $host);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $vendor_account_details = $row;
        }
        mysqli_stmt_close($stmt);
    }
    $current_vendor_id = $vendor_account_details['id'] ?? 0;

    // --- FETCH DATA ---
    $get_site_details = null;
    $featured_post = null;
    $featured_id = 0;
    $posts_result = null;
    $total_pages = 0;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;

    if ($connection_server) {
        $get_site_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$current_vendor_id' LIMIT 1"));

        $featured_res = mysqli_query($connection_server, "SELECT p.*, v.firstname, v.lastname FROM blog_posts p JOIN sas_vendors v ON p.author_id = v.id WHERE p.status = 'published' AND p.author_id = '$current_vendor_id' ORDER BY p.created_at DESC LIMIT 1");
        if ($featured_res) {
            $featured_post = mysqli_fetch_assoc($featured_res);
            $featured_id = $featured_post['id'] ?? 0;
        }

        $limit = 9;
        $offset = ($page > 0) ? ($page - 1) * $limit : 0;

        $total_posts_res = mysqli_query($connection_server, "SELECT COUNT(id) as count FROM blog_posts WHERE status = 'published' AND author_id = '$current_vendor_id' AND id != '$featured_id'");
        if ($total_posts_res) {
            $total_posts = mysqli_fetch_assoc($total_posts_res)['count'];
            $total_pages = ceil($total_posts / $limit);
        }

        $posts_query = "
            SELECT p.*, v.firstname, v.lastname
            FROM blog_posts p
            JOIN sas_vendors v ON p.author_id = v.id
            WHERE p.status = 'published' AND p.author_id = ? AND p.id != ?
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $posts_stmt = mysqli_prepare($connection_server, $posts_query);
        if ($posts_stmt) {
            mysqli_stmt_bind_param($posts_stmt, "iiii", $current_vendor_id, $featured_id, $limit, $offset);
            mysqli_stmt_execute($posts_stmt);
            $posts_result = mysqli_stmt_get_result($posts_stmt);
        }
    }
    require_once __DIR__ . '/func/bc-public-meridian.php';
    $m_ctx = bc_meridian_context();
    $site_title_blog = trim((string)($get_site_details['site_title'] ?? ''));
    if ($site_title_blog === '') $site_title_blog = $m_ctx['site_name'];
    $personal_vars_blog = bc_meridian_personal_style_vars($m_ctx['primary_color']);
    $js_config_blog = array(
        'siteName' => $site_title_blog,
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
<html lang="en" data-theme="dark" data-world="personal" style="<?php echo htmlspecialchars($personal_vars_blog, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Blog &amp; Insights | <?php echo htmlspecialchars($site_title_blog); ?></title>

    <link rel="stylesheet" href="/asset/meridian-1/meridian-1.css">
    <link rel="stylesheet" href="/asset/meridian-1/meridian-theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config_blog, JSON_UNESCAPED_SLASHES); ?>;</script>
</head>
<body>

    <?php echo bc_meridian_header_and_menu($m_ctx, 'personal'); ?>

    <main class="wrap m-sub-main" style="padding-top:96px">
        <section class="m-sub-hero" style="padding-top:18px">
            <p class="eyebrow"><i></i>Journal &amp; Updates</p>
            <h1>Blog &amp; Insights</h1>
            <p class="lede">Product guides, bill-payment tips, cross-border transfer notes, and platform news from <?php echo htmlspecialchars($site_title_blog); ?>.</p>
        </section>

        <?php if ($page == 1 && $featured_post): ?>
        <article class="m-featured-hero">
            <img src="<?php echo htmlspecialchars($featured_post['featured_image']); ?>" alt="<?php echo htmlspecialchars($featured_post['title']); ?>">
            <div class="m-featured-overlay">
                <span class="m-cs-pill">Featured Post</span>
                <h2 style="margin:12px 0 10px;font-size:clamp(24px,3.6vw,40px)">
                    <a href="single-post.php?id=<?php echo (int)$featured_post['id']; ?>" style="text-decoration:none"><?php echo htmlspecialchars($featured_post['title']); ?></a>
                </h2>
                <p class="small" style="color:var(--text2,#CBD5E1)">
                    By <?php echo htmlspecialchars($featured_post['firstname']); ?> · <?php echo date('M d, Y', strtotime($featured_post['created_at'])); ?>
                </p>
            </div>
        </article>
        <?php endif; ?>

        <section class="chapter tight">
            <div class="chap-head">
                <p class="eyebrow"><i></i>Explore More</p>
                <h2 style="margin-top:10px">Recent Articles</h2>
            </div>

            <?php if ($posts_result && mysqli_num_rows($posts_result) > 0): ?>
            <div class="m-blog-grid">
                <?php while($post = mysqli_fetch_assoc($posts_result)): ?>
                <article class="m-blog-card">
                    <a href="single-post.php?id=<?php echo (int)$post['id']; ?>">
                        <img src="<?php echo htmlspecialchars($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" loading="lazy">
                    </a>
                    <div class="m-blog-card-body">
                        <span class="small" style="color:var(--text3,#94A3B8)"><?php echo date('M d, Y', strtotime($post['created_at'])); ?> · 5 min read</span>
                        <h3 style="margin:0;font-size:19px">
                            <a href="single-post.php?id=<?php echo (int)$post['id']; ?>" style="text-decoration:none"><?php echo htmlspecialchars($post['title']); ?></a>
                        </h3>
                        <p class="small" style="color:var(--text2,#CBD5E1);margin:0;flex:1">
                            <?php
                                $content_plain = strip_tags(base64_decode($post['content']));
                                echo htmlspecialchars(substr($content_plain, 0, 120)) . '...';
                            ?>
                        </p>
                        <a class="bz-link" style="margin-top:4px" href="single-post.php?id=<?php echo (int)$post['id']; ?>">Read Article →</a>
                    </div>
                </article>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="m-showcase-card" style="text-align:center;margin:20px 0 40px">
                <h3 style="margin:0">No articles published yet.</h3>
                <p class="small" style="margin:0;color:var(--text2,#CBD5E1)">Stay tuned for guides, updates, and insights from <?php echo htmlspecialchars($site_title_blog); ?>.</p>
                <div style="margin-top:6px"><a class="btn primary" href="/Services.php">Explore Services</a></div>
            </div>
            <?php endif; ?>

            <?php if ($total_pages > 1): ?>
            <nav aria-label="Blog pagination" style="display:flex;justify-content:center;gap:8px;margin:24px 0 44px;flex-wrap:wrap">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a class="btn <?php echo $i == $page ? 'primary' : 'ghost'; ?>" style="padding:9px 15px" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </nav>
            <?php endif; ?>
        </section>
    </main>

    <?php echo bc_meridian_footer($m_ctx); ?>
    <script src="/asset/meridian-1/meridian-1.js"></script>
</body>
</html>
