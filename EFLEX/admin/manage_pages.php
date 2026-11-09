<?php
// Includes
include 'includes/admin_header.php';
require_permission('manage_pages');
require_once '../includes/db_connect.php';

// Handle Delete Action
if (isset($_GET['delete'])) {
    $id_to_delete = $_GET['delete'];
    $delete_stmt = $mysqli->prepare("DELETE FROM pages WHERE id = ?");
    $delete_stmt->bind_param("i", $id_to_delete);
    if ($delete_stmt->execute()) {
        $_SESSION['success_message'] = "Page deleted successfully.";
    } else {
        $_SESSION['error_message'] = "Error deleting page.";
    }
    $delete_stmt->close();
    header("Location: manage_pages.php");
    exit;
}


// Fetch all pages from the database
$result = $mysqli->query("SELECT id, title, slug, is_published, updated_at FROM pages ORDER BY updated_at DESC");

?>

<div class="container-fluid">
    <h1 class="h3 mb-2 text-gray-800">Manage Pages</h1>
    <p class="mb-4">Here you can create, edit, and delete custom pages for your website.</p>

    <?php
    if (isset($_SESSION['success_message'])) {
        echo '<div class="alert alert-success">'.$_SESSION['success_message'].'</div>';
        unset($_SESSION['success_message']);
    }
    if (isset($_SESSION['error_message'])) {
        echo '<div class="alert alert-danger">'.$_SESSION['error_message'].'</div>';
        unset($_SESSION['error_message']);
    }
    ?>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">All Pages</h6>
            <a href="edit_page.php" class="btn btn-primary btn-sm float-right" style="margin-top: -25px;">Add New Page</a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Slug</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while($page = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($page['title']); ?></td>
                                    <td>/page/<?php echo htmlspecialchars($page['slug']); ?></td>
                                    <td>
                                        <?php if ($page['is_published']): ?>
                                            <span class="badge bg-success text-white">Published</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary text-white">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date("M j, Y, g:i a", strtotime($page['updated_at'])); ?></td>
                                    <td>
                                        <a href="edit_page.php?id=<?php echo $page['id']; ?>" class="btn btn-warning btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="manage_pages.php?delete=<?php echo $page['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this page?');">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                         <a href="../page.php?slug=<?php echo $page['slug']; ?>" class="btn btn-info btn-sm" target="_blank">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">No pages found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
