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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo htmlspecialchars($post['title']); ?> | Blog</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link href="assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">

    <!-- ShareThis -->
    <script type='text/javascript' src='https://platform-api.sharethis.com/js/sharethis.js#property=65c490a071c82a001275d46a&product=inline-share-buttons' async='async'></script>

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
            line-height: 1.7;
        }

        .navbar {
            background-color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 15px 0;
        }
        .navbar-brand img { height: 40px; }

        .article-header {
            padding: 60px 0;
            text-align: center;
        }
        .article-title {
            font-weight: 800;
            font-size: 3rem;
            margin-bottom: 25px;
            color: var(--dark-bg);
        }
        .article-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .featured-image-container {
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 30px 60px -12px rgba(50, 50, 93, 0.25);
            margin-bottom: 50px;
        }
        .featured-image-container img {
            width: 100%;
            height: auto;
            max-height: 600px;
            object-fit: cover;
        }

        .content-card {
            background: white;
            padding: 50px;
            border-radius: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            margin-bottom: 50px;
        }

        .post-body {
            font-size: 1.15rem;
            color: #334155;
        }
        .post-body p { margin-bottom: 25px; }
        .post-body h2, .post-body h3 { font-weight: 700; margin: 40px 0 20px; color: var(--dark-bg); }
        
        .share-section {
            border-top: 1px solid #e2e8f0;
            padding-top: 30px;
            margin-top: 50px;
        }

        .related-card {
            border: none;
            border-radius: 20px;
            background: white;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.3s ease;
        }
        .related-card:hover { transform: translateY(-5px); }
        .related-card img { height: 180px; object-fit: cover; }

        footer {
            background-color: var(--dark-bg);
            color: white;
            padding: 80px 0 40px;
            margin-top: 100px;
        }

        @media (max-width: 768px) {
            .article-title { font-size: 2rem; }
            .content-card { padding: 25px; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="/uploaded-image/<?php echo str_replace(['.',':'],'-',$_SERVER['HTTP_HOST']).'_'; ?>logo.png" alt="Logo">
            </a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="blog.php">Insights</a></li>
                    <li class="nav-item"><a class="nav-link" href="web/APIDocs.php">API Docs</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <article class="container">
        <header class="article-header">
            <span class="text-primary fw-bold text-uppercase small mb-2 d-block">Article Insight</span>
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
                        <h6 class="fw-bold mb-3">Share this story</h6>
                        <!-- ShareThis Inline Buttons -->
                        <div class="sharethis-inline-share-buttons"></div>
                    </div>
                </div>

                <!-- Related Posts -->
                <?php if(!empty($related_posts)): ?>
                <div class="mt-5">
                    <h4 class="fw-bold mb-4">You might also like</h4>
                    <div class="row g-4">
                        <?php foreach($related_posts as $rel): ?>
                        <div class="col-md-4">
                            <div class="related-card h-100">
                                <a href="single-post.php?id=<?php echo $rel['id']; ?>">
                                    <img src="<?php echo htmlspecialchars($rel['featured_image']); ?>" class="w-100" alt="Related">
                                </a>
                                <div class="p-3">
                                    <h6 class="fw-bold mb-0">
                                        <a href="single-post.php?id=<?php echo $rel['id']; ?>" class="text-decoration-none text-dark"><?php echo htmlspecialchars($rel['title']); ?></a>
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

    <footer>
        <div class="container text-center">
            <h5 class="fw-bold mb-4"><?php echo htmlspecialchars($get_site_details['site_title'] ?? 'Our Platform'); ?></h5>
            <div class="d-flex justify-content-center gap-4 mb-4">
                <a href="index.php" class="text-white-50 text-decoration-none">Home</a>
                <a href="blog.php" class="text-white-50 text-decoration-none">Insights</a>
                <a href="web/APIDocs.php" class="text-white-50 text-decoration-none">API Docs</a>
            </div>
            <p class="text-white-50 small">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?>. All rights reserved.</p>
        </div>
    </footer>

    <script src="assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>