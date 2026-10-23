<?php
session_start();
include("../func/bc-admin-config.php");

// Function to create a URL-friendly slug
function create_slug($string){
   $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', strtolower(trim($string)));
   $slug = preg_replace('/-+/', '-', $slug);
   return $slug;
}

// Handle Add/Edit Category
if (isset($_POST['save_category'])) {
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $name = trim($_POST['name']);
    $slug = create_slug($name);

    if (empty($category_id)) {
        // Add New Category
        $stmt = mysqli_prepare($connection_server, "INSERT INTO blog_categories (name, slug) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, "ss", $name, $slug);
        $_SESSION['page_alert'] = "Category added successfully!";
    } else {
        // Update Existing Category
        $stmt = mysqli_prepare($connection_server, "UPDATE blog_categories SET name=?, slug=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ssi", $name, $slug, $category_id);
        $_SESSION['page_alert'] = "Category updated successfully!";
    }

    if(!mysqli_stmt_execute($stmt)) {
        $_SESSION['page_alert'] = "Error: " . mysqli_stmt_error($stmt);
    }
    header("Location: BlogCategories.php");
    exit();
}

// Handle Delete Request
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];

    $stmt = mysqli_prepare($connection_server, "DELETE FROM blog_categories WHERE id=?");
    mysqli_stmt_bind_param($stmt, "i", $delete_id);
    mysqli_stmt_execute($stmt);

    // Also remove associations
    $stmt_assoc = mysqli_prepare($connection_server, "DELETE FROM blog_post_categories WHERE category_id=?");
    mysqli_stmt_bind_param($stmt_assoc, "i", $delete_id);
    mysqli_stmt_execute($stmt_assoc);
    $_SESSION['page_alert'] = "Category deleted successfully!";
    header("Location: BlogCategories.php");
    exit();
}

// Fetch category for editing
$edit_category = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = mysqli_prepare($connection_server, "SELECT * FROM blog_categories WHERE id=?");
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $edit_category = mysqli_fetch_assoc($result);
    }
}

$categories_result = mysqli_query($connection_server, "SELECT * FROM blog_categories ORDER BY name ASC");
?>
<!DOCTYPE html>
<head>
    <title>Blog Categories Management</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
        <h1>Blog Categories</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Blog Categories</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <?php if(isset($_SESSION['page_alert'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['page_alert']; unset($_SESSION['page_alert']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo isset($edit_category) ? 'Edit' : 'Add New'; ?> Category</h5>
                        <form method="POST" action="BlogCategories.php">
                            <input type="hidden" name="category_id" value="<?php echo $edit_category['id'] ?? ''; ?>">
                            <div class="mb-3">
                                <label for="name" class="form-label">Category Name</label>
                                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($edit_category['name'] ?? ''); ?>" required>
                            </div>
                            <button type="submit" name="save_category" class="btn btn-primary">Save Category</button>
                            <?php if (isset($edit_category)): ?>
                                <a href="BlogCategories.php" class="btn btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Existing Categories</h5>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Slug</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    if ($categories_result && mysqli_num_rows($categories_result) > 0) {
                                        $count = 1;
                                        while($row = mysqli_fetch_assoc($categories_result)):
                                ?>
                                <tr>
                                    <th scope="row"><?php echo $count++; ?></th>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['slug']); ?></td>
                                    <td>
                                        <a href="BlogCategories.php?edit_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                        <a href="BlogCategories.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure? This will also remove the category from all posts.');">Delete</a>
                                    </td>
                                </tr>
                                <?php
                                        endwhile;
                                    } else {
                                        echo '<tr><td colspan="4">No categories found.</td></tr>';
                                    }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
