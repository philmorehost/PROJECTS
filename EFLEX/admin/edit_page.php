<?php
// Includes
include 'includes/admin_header.php';
require_permission('manage_pages');
require_once '../includes/db_connect.php';

// Initialize variables
$page_id = null;
$title = '';
$slug = '';
$content = '';
$is_published = 0;
$form_action = 'create';
$page_title = 'Add New Page';

// Check if we are editing an existing page
if (isset($_GET['id'])) {
    $page_id = $_GET['id'];
    $form_action = 'update';
    $page_title = 'Edit Page';

    $stmt = $mysqli->prepare("SELECT title, slug, content, is_published FROM pages WHERE id = ?");
    $stmt->bind_param("i", $page_id);
    $stmt->execute();
    $stmt->bind_result($title, $slug, $content, $is_published);
    $stmt->fetch();
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $slug = $_POST['slug'];
    $content = $_POST['content'];
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $page_id = $_POST['page_id'] ?? null;

    // `pages.slug` is UNIQUE and mysqli runs in strict error mode, so a duplicate slug
    // throws instead of returning false - without this catch the error branch below
    // was unreachable and the page died with an uncaught mysqli_sql_exception.
    try {
        if ($form_action === 'create') {
            $stmt = $mysqli->prepare("INSERT INTO pages (title, slug, content, is_published) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $title, $slug, $content, $is_published);
            $stmt->execute();
            $_SESSION['success_message'] = "Page created successfully.";
        } else { // update
            $stmt = $mysqli->prepare("UPDATE pages SET title = ?, slug = ?, content = ?, is_published = ? WHERE id = ?");
            $stmt->bind_param("sssii", $title, $slug, $content, $is_published, $page_id);
            $stmt->execute();
            $_SESSION['success_message'] = "Page updated successfully.";
        }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        $_SESSION['error_message'] = "Error saving page. The slug may already be in use.";
    }
    header("Location: manage_pages.php");
    exit;
}

?>

<!-- TinyMCE CDN -->
<script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
  tinymce.init({
    selector: 'textarea#content',
    plugins: 'code table lists image link media',
    toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | indent outdent | bullist numlist | code | table | image link media'
  });
</script>

<div class="container-fluid">
    <h1 class="h3 mb-2 text-gray-800"><?php echo $page_title; ?></h1>
    <p class="mb-4">Use the form below to manage page details.</p>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Page Details</h6>
        </div>
        <div class="card-body">
            <form action="edit_page.php<?php echo $page_id ? '?id='.$page_id : ''; ?>" method="POST">
                <input type="hidden" name="page_id" value="<?php echo $page_id; ?>">

                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="slug" class="form-label">Slug</label>
                    <input type="text" class="form-control" id="slug" name="slug" value="<?php echo htmlspecialchars($slug); ?>" required>
                    <div class="form-text">The slug is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.</div>
                </div>

                <div class="mb-3">
                    <label for="content" class="form-label">Content</label>
                    <textarea class="form-control" id="content" name="content" rows="15"><?php echo htmlspecialchars($content); ?></textarea>
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="is_published" name="is_published" value="1" <?php echo $is_published ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="is_published">Publish Page</label>
                </div>

                <button type="submit" class="btn btn-primary">Save Page</button>
                <a href="manage_pages.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
</div>

<script>
    // Auto-generate slug from title
    const titleInput = document.getElementById('title');
    const slugInput = document.getElementById('slug');

    const generateSlug = (title) => {
        return title
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9\s-]/g, '')  // Remove non-alphanumeric characters except spaces and hyphens
            .replace(/\s+/g, '-')         // Replace spaces with hyphens
            .replace(/-+/g, '-');          // Replace multiple hyphens with a single one
    };

    titleInput.addEventListener('keyup', () => {
        slugInput.value = generateSlug(titleInput.value);
    });
</script>

<?php include 'includes/admin_footer.php'; ?>
