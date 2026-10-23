<?php
session_start();
include("../func/bc-admin-config.php");

// Handle Delete Request
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];

    if ($delete_id > 0) {
        // Get the featured image path before deleting the post
        $stmt_img = mysqli_prepare($connection_server, "SELECT featured_image FROM blog_posts WHERE id=?");
        mysqli_stmt_bind_param($stmt_img, "i", $delete_id);
        mysqli_stmt_execute($stmt_img);
        $img_result = mysqli_stmt_get_result($stmt_img);
        if ($img_result && $img_row = mysqli_fetch_assoc($img_result)) {
            if (!empty($img_row['featured_image']) && file_exists('../' . $img_row['featured_image'])) {
                unlink('../' . $img_row['featured_image']);
            }
        }

        // Delete post category associations
        $stmt_cat = mysqli_prepare($connection_server, "DELETE FROM blog_post_categories WHERE post_id=?");
        mysqli_stmt_bind_param($stmt_cat, "i", $delete_id);
        mysqli_stmt_execute($stmt_cat);

        // Delete the post
        $stmt_post = mysqli_prepare($connection_server, "DELETE FROM blog_posts WHERE id=?");
        mysqli_stmt_bind_param($stmt_post, "i", $delete_id);
        mysqli_stmt_execute($stmt_post);
    }

    $_SESSION['page_alert'] = "Post deleted successfully!";
    header("Location: BlogPosts.php");
    exit();
}

$posts_result = mysqli_query($connection_server, "SELECT p.*, v.firstname, v.lastname FROM blog_posts p JOIN sas_vendors v ON p.author_id = v.id WHERE p.author_id = '{$get_logged_admin_details['id']}' ORDER BY p.created_at DESC");
?>
<!DOCTYPE html>
<head>
    <title>Manage Blog Posts</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
        <h1>Blog Posts</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Blog Posts</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">All Blog Posts</h5>
                <?php if(isset($_SESSION['page_alert'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['page_alert']; unset($_SESSION['page_alert']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <a href="BlogPostEdit.php" class="btn btn-primary mb-3">Add New Post</a>

                <table class="table datatable">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Title</th>
                            <th scope="col">Author</th>
                            <th scope="col">Status</th>
                            <th scope="col">Date</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            if ($posts_result && mysqli_num_rows($posts_result) > 0) {
                                $count = 1;
                                while($row = mysqli_fetch_assoc($posts_result)):
                        ?>
                        <tr>
                            <th scope="row"><?php echo $count++; ?></th>
                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                            <td><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></td>
                            <td><span class="badge bg-<?php echo $row['status'] == 'published' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                            <td><?php echo date('M j, Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <a href="BlogPostEdit.php?edit_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">Edit</a>
                                <a href="BlogPosts.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this post?');">Delete</a>
                            </td>
                        </tr>
                        <?php
                                endwhile;
                            }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
