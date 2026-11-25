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

    // --- FETCH SINGLE POST ---
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

    // --- FETCH RELATED POSTS ---
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
<html lang="en" data-theme="dark" data-world="business">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo htmlspecialchars($post['title']); ?> | <?php echo htmlspecialchars($site_title_post); ?></title>

    <link rel="stylesheet" href="/asset/meridian-1/meridian-1.css">
    <link rel="stylesheet" href="/asset/meridian-1/meridian-theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config_post, JSON_UNESCAPED_SLASHES); ?>;</script>

    <!-- ShareThis -->
    <script type='text/javascript' src='https://platform-api.sharethis.com/js/sharethis.js#property=65c490a071c82a001275d46a&product=inline-share-buttons' async='async'></script>

    <style>
        :root {
            --accent-color: #FFFFFF;
            --dark-bg: #050507;
            --text-main: #F8FAFC;
            --text-muted: #CBD5E1;
        }

        body {
            font-family: 'Figtree', system-ui, sans-serif;
            background-color: #050507;
            color: var(--text-main);
            line-height: 1.7;
            padding-top: 78px;
        }

        .article-header {
            padding: 52px 0 36px;
            text-align: center;
        }
        .article-title {
            font-weight: 800;
            font-size: clamp(2rem, 4.4vw, 3rem);
            margin-bottom: 20px;
            color: #FFFFFF;
        }
        .article-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 20px;
            color: #94A3B8;
            font-weight: 600;
        }

        .featured-image-container {
            border-radius: 26px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
            margin-bottom: 40px;
        }
        .featured-image-container img {
            width: 100%;
            height: auto;
            max-height: 580px;
            object-fit: cover;
        }

        .content-card {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.14);
            padding: 46px;
            border-radius: 26px;
            box-shadow: 0 14px 40px rgba(0,0,0,0.3);
            margin-bottom: 50px;
        }

        .post-body {
            font-size: 1.1rem;
            color: #E2E8F0;
        }
        .post-body p { margin-bottom: 22px; }
        .post-body h2, .post-body h3 { font-weight: 700; margin: 36px 0 18px; color: #FFFFFF; }
        .post-body a { color: #93C5FD; }

        .share-section {
            border-top: 1px solid rgba(255,255,255,.14);
            padding-top: 26px;
            margin-top: 44px;
        }

        .related-card {
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 20px;
            background: rgba(255,255,255,.04);
            overflow: hidden;
            transition: transform 0.25s ease, border-color 0.25s ease;
        }
        .related-card:hover { transform: translateY(-4px); border-color: rgba(255,255,255,.32); }
        .related-card img { height: 180px; object-fit: cover; }
        .related-card a { color: #FFFFFF !important; }

        @media (max-width: 768px) {
            .article-title { font-size: 1.9rem; }
            .content-card { padding: 24px; }
        }
    </style>
</head>
<body>

    <?php echo bc_meridian_header_and_menu($m_ctx, 'business'); ?>

    <article class="container">
        <header class="article-header">
            <span class="fw-bold text-uppercase small mb-2 d-block" style="color:#94A3B8">Article Insight</span>
            <h1 class="article-title"><?php echo htmlspecialchars($post['title']); ?></h1>
            <div class="article-meta">
                <span><i class="bi bi-person-circle me-2"></i><?php echo htmlspecialchars($post['firstname']); ?></span>
                <span><i class="bi bi-calendar3 me-2"></i><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                <span><i class="bi bi-clock me-2"></i>6 min read</span>
            </div>
        </header>

        <?php if(!empty($post['featured_image'])): ?>
        <div class="featured-image-container">
            <img src="<?php echo htmlspecialchars($post['featured_image']); ?>" alt="Featured Image">
        </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="content-card">
                    <div class="post-body">
                        <?php echo base64_decode($post['content']); ?>
                    </div>

                    <div class="share-section">
                        <h6 class="fw-bold mb-3 text-white">Share this story</h6>
                        <!-- ShareThis Inline Buttons -->
                        <div class="sharethis-inline-share-buttons"></div>
                    </div>
                </div>

                <!-- Related Posts -->
                <?php if(!empty($related_posts)): ?>
                <div class="mt-5 mb-5">
                    <h4 class="fw-bold mb-4 text-white">You might also like</h4>
                    <div class="row g-4">
                        <?php foreach($related_posts as $rel): ?>
                        <div class="col-md-4">
                            <div class="related-card h-100">
                                <a href="single-post.php?id=<?php echo $rel['id']; ?>">
                                    <img src="<?php echo htmlspecialchars($rel['featured_image']); ?>" class="w-100" alt="Related">
                                </a>
                                <div class="p-3">
                                    <h6 class="fw-bold mb-0">
                                        <a href="single-post.php?id=<?php echo $rel['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($rel['title']); ?></a>
                                    </h6>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </article>

    <?php echo bc_meridian_footer($m_ctx); ?>

    <script src="assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/asset/meridian-1/meridian-1.js"></script>
</body>
</html>