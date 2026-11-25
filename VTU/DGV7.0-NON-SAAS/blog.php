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
        // --- FETCH SITE DETAILS ---
        $get_site_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$current_vendor_id' LIMIT 1"));

        // --- FEATURED POST (Latest Post) ---
        $featured_res = mysqli_query($connection_server, "SELECT p.*, v.firstname, v.lastname FROM blog_posts p JOIN sas_vendors v ON p.author_id = v.id WHERE p.status = 'published' AND p.author_id = '$current_vendor_id' ORDER BY p.created_at DESC LIMIT 1");
        if ($featured_res) {
            $featured_post = mysqli_fetch_assoc($featured_res);
            $featured_id = $featured_post['id'] ?? 0;
        }

        // --- PAGINATION & POST FETCHING LOGIC ---
        $limit = 9; // Grid of 3x3
        $offset = ($page > 0) ? ($page - 1) * $limit : 0;

        // Get total number of published posts (excluding featured if on page 1)
        $total_posts_res = mysqli_query($connection_server, "SELECT COUNT(id) as count FROM blog_posts WHERE status = 'published' AND author_id = '$current_vendor_id' AND id != '$featured_id'");
        if ($total_posts_res) {
            $total_posts = mysqli_fetch_assoc($total_posts_res)['count'];
            $total_pages = ceil($total_posts / $limit);
        }

        // Fetch grid posts
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
<html lang="en" data-theme="dark" data-world="business">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Blog &amp; Insights | <?php echo htmlspecialchars($site_title_blog); ?></title>

    <link rel="stylesheet" href="/asset/meridian-1/meridian-1.css">
    <link rel="stylesheet" href="/asset/meridian-1/meridian-theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config_blog, JSON_UNESCAPED_SLASHES); ?>;</script>

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
            padding-top: 78px;
        }

        /* Hero / Featured Style */
        .featured-hero {
            position: relative;
            background-color: #0B0D14;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 26px;
            overflow: hidden;
            margin-top: 32px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.45);
            min-height: 460px;
            display: flex;
            align-items: flex-end;
        }
        .featured-img-bg {
            position: absolute;
            top:0; left:0; width:100%; height:100%;
            object-fit: cover;
            opacity: 0.48;
            transition: transform 0.5s ease;
        }
        .featured-hero:hover .featured-img-bg { transform: scale(1.04); }
        .featured-overlay {
            position: relative;
            z-index: 2;
            padding: 48px;
            background: linear-gradient(transparent, rgba(5, 5, 7, 0.94));
            width: 100%;
        }
        .category-badge {
            display: inline-block;
            background-color: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.24);
            color: #FFFFFF;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        /* Grid Cards */
        .blog-card {
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 20px;
            background: rgba(255,255,255,.04);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            display: flex;
            flex-direction: column;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.28);
        }
        .blog-card:hover {
            transform: translateY(-6px);
            border-color: rgba(255,255,255,.32);
        }
        .card-img-wrapper {
            position: relative;
            border-radius: 20px 20px 0 0;
            overflow: hidden;
            aspect-ratio: 16/10;
        }
        .card-img-wrapper img {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 0.5s ease;
        }
        .blog-card:hover .card-img-wrapper img { transform: scale(1.06); }

        .card-body { padding: 24px; flex-grow: 1; }
        .post-title {
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.4;
            margin-bottom: 12px;
            color: #FFFFFF;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .post-title a { color: #FFFFFF !important; }
        .post-excerpt {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 18px;
        }

        .meta-info {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            color: #94A3B8;
            gap: 15px;
        }

        /* Section Header */
        .section-header {
            margin-bottom: 34px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .section-title {
            font-weight: 800;
            font-size: 2.2rem;
            color: #FFFFFF;
            position: relative;
        }
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px; left: 0;
            width: 60px; height: 4px;
            background-color: rgba(255,255,255,.45);
            border-radius: 10px;
        }

        .pagination .page-link {
            border: 1px solid rgba(255,255,255,.16);
            background: rgba(255,255,255,.05);
            color: #F8FAFC;
            font-weight: 600;
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 10px !important;
            margin: 0 5px;
        }
        .pagination .page-item.active .page-link {
            background-color: #FFFFFF;
            color: #09090B;
        }
        .blog-empty {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.14);
            color: #F8FAFC;
        }
        .blog-empty p { color: #CBD5E1; }
    </style>
</head>
<body>

    <?php echo bc_meridian_header_and_menu($m_ctx, 'business'); ?>

    <div class="container py-5">

        <?php if ($page == 1 && $featured_post): ?>
        <div class="section-header">
            <div>
                <span class="text-uppercase fw-bold small" style="color:#94A3B8">Trending Stories</span>
                <h2 class="section-title">Latest Insight</h2>
            </div>
        </div>

        <div class="featured-hero mb-5">
            <img src="<?php echo htmlspecialchars($featured_post['featured_image']); ?>" class="featured-img-bg" alt="Featured">
            <div class="featured-overlay">
                <span class="category-badge">Featured Post</span>
                <h1 class="display-5 fw-bold text-white mb-3">
                    <a href="single-post.php?id=<?php echo $featured_post['id']; ?>" class="text-white text-decoration-none"><?php echo htmlspecialchars($featured_post['title']); ?></a>
                </h1>
                <div class="meta-info text-white-50">
                    <span><i class="bi bi-person me-2"></i><?php echo htmlspecialchars($featured_post['firstname']); ?></span>
                    <span><i class="bi bi-calendar3 me-2"></i><?php echo date('M d, Y', strtotime($featured_post['created_at'])); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-header mt-5">
            <div>
                <span class="text-uppercase fw-bold small" style="color:#94A3B8">Explore More</span>
                <h2 class="section-title">Recent Articles</h2>
            </div>
        </div>

        <div class="row g-4">
            <?php if ($posts_result && mysqli_num_rows($posts_result) > 0): ?>
                <?php while($post = mysqli_fetch_assoc($posts_result)): ?>
                    <div class="col-lg-4 col-md-6">
                        <div class="blog-card h-100">
                            <div class="card-img-wrapper">
                                <a href="single-post.php?id=<?php echo $post['id']; ?>">
                                    <img src="<?php echo htmlspecialchars($post['featured_image']); ?>" alt="Article">
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="meta-info mb-3">
                                    <span><i class="bi bi-calendar3 me-2"></i><?php echo date('M d', strtotime($post['created_at'])); ?></span>
                                    <span><i class="bi bi-clock me-2"></i>5 min read</span>
                                </div>
                                <h3 class="post-title">
                                    <a href="single-post.php?id=<?php echo $post['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($post['title']); ?></a>
                                </h3>
                                <p class="post-excerpt">
                                    <?php
                                        $content_plain = strip_tags(base64_decode($post['content']));
                                        echo substr($content_plain, 0, 120) . '...';
                                    ?>
                                </p>
                                <a href="single-post.php?id=<?php echo $post['id']; ?>" class="btn btn-link p-0 fw-bold text-decoration-none" style="color:#FFFFFF">
                                    Read Article <i class="bi bi-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <div class="blog-empty p-5 rounded-4">
                        <i class="bi bi-journal-x display-1" style="color:#94A3B8"></i>
                        <h3 class="mt-3 text-white">No more articles yet.</h3>
                        <p>Stay tuned for more updates and insights.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if($total_pages > 1): ?>
        <div class="d-flex justify-content-center mt-5">
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php if($i == $page) echo 'active'; ?>">
                        <a class="page-link shadow-sm" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

    </div>

    <?php echo bc_meridian_footer($m_ctx); ?>

    <script src="assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/asset/meridian-1/meridian-1.js"></script>
</body>
</html>