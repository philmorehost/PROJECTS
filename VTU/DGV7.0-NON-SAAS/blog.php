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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Blog & Insights | <?php echo htmlspecialchars($get_site_details['site_title'] ?? 'Our Website'); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link href="assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --accent-color: #287bff;
            --dark-bg: #0f172a;
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
            color: var(--text-main);
        }

        /* Hero / Featured Style */
        .featured-hero {
            position: relative;
            background-color: var(--dark-bg);
            border-radius: 30px;
            overflow: hidden;
            margin-top: 40px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
            min-height: 500px;
            display: flex;
            align-items: flex-end;
        }
        .featured-img-bg {
            position: absolute;
            top:0; left:0; width:100%; height:100%;
            object-fit: cover;
            opacity: 0.6;
            transition: transform 0.5s ease;
        }
        .featured-hero:hover .featured-img-bg { transform: scale(1.05); }
        .featured-overlay {
            position: relative;
            z-index: 2;
            padding: 60px;
            background: linear-gradient(transparent, rgba(15, 23, 42, 0.9));
            width: 100%;
        }
        .category-badge {
            display: inline-block;
            background-color: var(--accent-color);
            color: white;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        /* Grid Cards */
        .blog-card {
            border: none;
            border-radius: 20px;
            background: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .blog-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .card-img-wrapper {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            aspect-ratio: 16/10;
        }
        .card-img-wrapper img {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 0.5s ease;
        }
        .blog-card:hover .card-img-wrapper img { transform: scale(1.1); }

        .card-body { padding: 25px; flex-grow: 1; }
        .post-title {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1.4;
            margin-bottom: 15px;
            color: var(--text-main);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .post-excerpt {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .meta-info {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            color: var(--text-muted);
            gap: 15px;
        }

        /* Section Header */
        .section-header {
            margin-bottom: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .section-title {
            font-weight: 800;
            font-size: 2.5rem;
            position: relative;
        }
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px; left: 0;
            width: 60px; height: 5px;
            background-color: var(--accent-color);
            border-radius: 10px;
        }

        .navbar {
            background-color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 15px 0;
        }
        .navbar-brand img { height: 40px; }

        .pagination .page-link {
            border: none;
            color: var(--text-main);
            font-weight: 600;
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 10px !important;
            margin: 0 5px;
        }
        .pagination .page-item.active .page-link {
            background-color: var(--accent-color);
            color: white;
        }

        footer {
            background-color: var(--dark-bg);
            color: white;
            padding: 80px 0 40px;
            margin-top: 100px;
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="/uploaded-image/<?php echo str_replace(['.',':'],'-',$_SERVER['HTTP_HOST']).'_'; ?>logo.png" alt="Logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="blog.php">Insights</a></li>
                    <li class="nav-item"><a class="nav-link" href="web/APIDocs.php">API Docs</a></li>
                </ul>
                <div class="ms-lg-4">
                    <a href="web/Login.php" class="btn btn-outline-primary rounded-pill px-4 me-2">Login</a>
                    <a href="web/Register.php" class="btn btn-primary rounded-pill px-4">Get Started</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-5">

        <?php if ($page == 1 && $featured_post): ?>
        <div class="section-header">
            <div>
                <span class="text-uppercase fw-bold text-primary small">Trending Stories</span>
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
                <span class="text-uppercase fw-bold text-primary small">Explore More</span>
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
                                    <a href="single-post.php?id=<?php echo $post['id']; ?>" class="text-decoration-none text-dark"><?php echo htmlspecialchars($post['title']); ?></a>
                                </h3>
                                <p class="post-excerpt">
                                    <?php
                                        $content_plain = strip_tags(base64_decode($post['content']));
                                        echo substr($content_plain, 0, 120) . '...';
                                    ?>
                                </p>
                                <a href="single-post.php?id=<?php echo $post['id']; ?>" class="btn btn-link p-0 text-primary fw-bold text-decoration-none">
                                    Read Article <i class="bi bi-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <div class="bg-white p-5 rounded-4 shadow-sm">
                        <i class="bi bi-journal-x display-1 text-muted"></i>
                        <h3 class="mt-3">No more articles yet.</h3>
                        <p class="text-muted">Stay tuned for more updates and insights.</p>
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

    <footer>
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <h5 class="fw-bold mb-4"><?php echo htmlspecialchars($get_site_details['site_title'] ?? 'Our Platform'); ?></h5>
                    <p class="text-white-50"><?php echo htmlspecialchars($get_site_details['site_desc'] ?? ''); ?></p>
                </div>
                <div class="col-lg-2 ms-auto">
                    <h6 class="fw-bold mb-4">Quick Links</h6>
                    <ul class="list-unstyled text-white-50 small">
                        <li class="mb-2"><a href="index.php" class="text-white-50 text-decoration-none">Home</a></li>
                        <li class="mb-2"><a href="web/APIDocs.php" class="text-white-50 text-decoration-none">API Documentation</a></li>
                        <li class="mb-2"><a href="web/Pricing.php" class="text-white-50 text-decoration-none">Pricing</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h6 class="fw-bold mb-4">Support</h6>
                    <ul class="list-unstyled text-white-50 small">
                        <li class="mb-2"><i class="bi bi-envelope me-2"></i> <?php echo htmlspecialchars($vendor_account_details['email'] ?? ''); ?></li>
                        <li class="mb-2"><i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($vendor_account_details['phone_number'] ?? ''); ?></li>
                    </ul>
                </div>
            </div>
            <hr class="mt-5 border-white-50 opacity-25">
            <div class="text-center text-white-50 small pt-3">
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>