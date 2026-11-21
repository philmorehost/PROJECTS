<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
include("../func/bc-admin-config.php");

$post_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$post = null;
$post_categories = [];

if ($post_id) {
    $stmt = mysqli_prepare($connection_server, "SELECT * FROM blog_posts WHERE id=? AND author_id=?");
    $current_vendor_id = $get_logged_admin_details['id'];
    mysqli_stmt_bind_param($stmt, "ii", $post_id, $current_vendor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if($result && mysqli_num_rows($result) > 0) {
        $post = mysqli_fetch_assoc($result);

        $stmt_cat = mysqli_prepare($connection_server, "SELECT category_id FROM blog_post_categories WHERE post_id=?");
        mysqli_stmt_bind_param($stmt_cat, "i", $post_id);
        mysqli_stmt_execute($stmt_cat);
        $cat_res = mysqli_stmt_get_result($stmt_cat);

        while ($row = mysqli_fetch_assoc($cat_res)) {
            $post_categories[] = $row['category_id'];
        }
    } else {
        // Post not found, maybe redirect or show an error
        $_SESSION['page_alert'] = "Error: Post not found.";
        header("Location: BlogPosts.php");
        exit();
    }
}

if (isset($_POST['save_post'])) {
    $pin = mysqli_real_escape_string($connection_server, $_POST['security_pin']);
    if (empty($get_logged_admin_details['security_pin'])) {
         $_SESSION['page_alert'] = "Error: Please set your Security PIN in Account Settings first.";
         header("Location: AccountSettings.php");
         exit();
    }

    if (!verifyUserPIN($pin, $get_logged_admin_details)) {
         $_SESSION['page_alert'] = "Error: Invalid Security PIN.";
         header("Location: BlogPostEdit.php" . ($post_id ? "?edit_id=$post_id" : ""));
         exit();
    }

    $author_id = $get_logged_admin_details['id'];
    // §35: a code payload in the (plain-text) post title is refused + scored.
    if (function_exists('bc_content_code_scan') && bc_content_code_scan((string)$_POST['title'])) {
        if (function_exists('bc_abuse_signal')) bc_abuse_signal((int)$get_logged_admin_details['id'], 'vendor', (string)$get_logged_admin_details['id'], 'code_injection_attempt', 'blog post title');
        $_SESSION['page_alert'] = "Error: The post title contains code or script content, which is not allowed.";
        header("Location: BlogPostEdit.php" . ($post_id ? "?edit_id=$post_id" : ""));
        exit();
    }
    $title = $_POST['title'];
    $content = base64_encode($_POST['content']);
    $status = $_POST['status'] === 'published' ? 'published' : 'draft';
    $categories = $_POST['categories'] ?? [];

    $featured_image_path = $post['featured_image'] ?? null;

    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] == 0) {
        $upload_dir = '../uploaded-image/blog-featured/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Security: Validate file type and size
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB max
        // §35: server-side MIME only — the client-supplied type is attacker text.
        $file_type = function_exists('mime_content_type') ? (mime_content_type($_FILES['featured_image']['tmp_name']) ?: '') : '';

        if (!in_array($file_type, $allowed_types)) {
            $_SESSION['page_alert'] = "Error: Only JPG, PNG, GIF, and WebP images are allowed.";
            header("Location: BlogPostEdit.php" . ($post_id ? "?edit_id=$post_id" : ""));
            exit();
        }

        if ($_FILES['featured_image']['size'] > $max_size) {
            $_SESSION['page_alert'] = "Error: Image must be less than 5MB.";
            header("Location: BlogPostEdit.php" . ($post_id ? "?edit_id=$post_id" : ""));
            exit();
        }

        // §35: the stored extension derives from the VALIDATED MIME type,
        // never the client filename — a real image named shell.php lands as
        // .png, and an executable filename is scored as a webshell attempt.
        $bc_client_ext = strtolower(pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION));
        if (in_array($bc_client_ext, array('php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'sh', 'exe', 'com', 'htaccess'), true)
            && function_exists('bc_abuse_signal')) {
            bc_abuse_signal((int)$get_logged_admin_details['id'], 'vendor', (string)$get_logged_admin_details['id'], 'webshell_upload_attempt', 'blog featured image named .' . $bc_client_ext);
        }
        $bc_mime_ext = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
        $ext = $bc_mime_ext[$file_type] ?? '';
        if ($ext === '') {
            $_SESSION['page_alert'] = "Error: Only JPG, PNG, GIF, and WebP images are allowed.";
            header("Location: BlogPostEdit.php" . ($post_id ? "?edit_id=$post_id" : ""));
            exit();
        }
        $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target_file = $upload_dir . $filename;

        // Before uploading new, delete old if it exists and is different
        if ($post_id && $featured_image_path && file_exists('../' . $featured_image_path)) {
             unlink('../' . $featured_image_path);
        }

        if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $target_file)) {
            $featured_image_path = 'uploaded-image/blog-featured/' . $filename;
        } else {
            // Handle file upload error
            $_SESSION['page_alert'] = "Error: Could not upload the featured image. Please check file permissions.";
            header("Location: BlogPostEdit.php" . ($post_id ? "?edit_id=$post_id" : ""));
            exit();
        }
    }

    if ($post_id) {
        // Update
        $stmt = mysqli_prepare($connection_server, "UPDATE blog_posts SET title=?, content=?, status=?, featured_image=?, updated_at=NOW() WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ssssi", $title, $content, $status, $featured_image_path, $post_id);
        mysqli_stmt_execute($stmt);
    } else {
        // Insert
        $stmt = mysqli_prepare($connection_server, "INSERT INTO blog_posts (author_id, title, content, status, featured_image) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "issss", $author_id, $title, $content, $status, $featured_image_path);
        mysqli_stmt_execute($stmt);
        $post_id = mysqli_insert_id($connection_server);
    }

    // Update categories
    $stmt_del = mysqli_prepare($connection_server, "DELETE FROM blog_post_categories WHERE post_id=?");
    mysqli_stmt_bind_param($stmt_del, "i", $post_id);
    mysqli_stmt_execute($stmt_del);

    if (!empty($categories)) {
        $stmt_ins = mysqli_prepare($connection_server, "INSERT INTO blog_post_categories (post_id, category_id) VALUES (?, ?)");
        foreach ($categories as $cat_id) {
            $category_id = (int)$cat_id;
            mysqli_stmt_bind_param($stmt_ins, "ii", $post_id, $category_id);
            mysqli_stmt_execute($stmt_ins);
        }
    }

    $_SESSION['page_alert'] = "Post saved successfully!";
    header("Location: BlogPosts.php");
    exit();
}

$all_categories = mysqli_query($connection_server, "SELECT * FROM blog_categories ORDER BY name ASC");
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head(($post_id ? 'Edit' : 'Add New') . " Post", array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}
</style>
<style>
        .ck-editor__editable_inline {
            min-height: 300px;
        }
    </style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => ($post_id ? 'Edit' : 'Add New') . " Post", "crumb" => "")); ?>
    <section class="section dashboard">
        <div class="card">
            <div class="card-body pt-3">
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="title" class="form-label">Title</label>
                        <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($post['title'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="content" class="form-label">Content</label>
                        <textarea class="form-control" id="content" name="content" rows="10"><?php echo isset($post['content']) ? htmlspecialchars(base64_decode($post['content'])) : ''; ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="featured_image" class="form-label">Featured Image</label>
                                <input type="file" class="form-control" id="featured_image" name="featured_image" accept="image/*">
                                <?php if ($post && $post['featured_image']): ?>
                                    <div class="mt-2">
                                        <img src="../<?php echo htmlspecialchars($post['featured_image']); ?>" width="150" alt="Featured Image" />
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="draft" <?php echo (isset($post['status']) && $post['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="published" <?php echo (isset($post['status']) && $post['status'] == 'published') ? 'selected' : (!isset($post['status']) ? 'selected' : ''); ?>>Published</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                             <div class="mb-3">
                                <label class="form-label">Categories</label>
                                <div class="p-2 border rounded" style="max-height: 200px; overflow-y: auto;">
                                <?php
                                    if ($all_categories && mysqli_num_rows($all_categories) > 0) {
                                        while($cat = mysqli_fetch_assoc($all_categories)):
                                ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="categories[]" value="<?php echo $cat['id']; ?>" id="cat_<?php echo $cat['id']; ?>" <?php echo in_array($cat['id'], $post_categories) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="cat_<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></label>
                                    </div>
                                <?php
                                        endwhile;
                                    } else {
                                        echo "<p>No categories found. Please add categories first.</p>";
                                    }
                                ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-4 pt-3 border-top">
                        <label class="form-label fw-bold text-danger"><i class="bi bi-shield-lock me-2"></i>Security PIN Verification</label>
                        <input type="password" name="security_pin" class="form-control form-control-lg text-center fw-bold" maxlength="4" pattern="\d{4}" inputmode="numeric" placeholder="****" style="max-width: 200px; letter-spacing: 10px; font-size: 24px;" required>
                        <div class="form-text">Confirm your identity with your 4-digit Security PIN to save this post.</div>
                    </div>
                    <button type="submit" name="save_post" class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm">Save Post</button>
                    <a href="BlogPosts.php" class="btn btn-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </section>
    
    <script src="https://cdn.ckeditor.com/ckeditor5/36.0.1/classic/ckeditor.js"></script>
<script>
    ClassicEditor
        .create(document.querySelector('#content'), {
            toolbar: {
                items: [
                    'heading', '|',
                    'bold', 'italic', 'underline', 'link', '|',
                    'fontColor', 'fontBackgroundColor', '|',
                    'bulletedList', 'numberedList', 'outdent', 'indent', '|',
                    'blockQuote', 'insertTable', 'mediaEmbed', '|',
                    'undo', 'redo', 'sourceEditing'
                ]
            },
            language: 'en'
        })
        .catch(error => {
            console.error('There was a problem initializing the editor.', error);
        });
</script>



<?php bc_adm_foot(array("area" => "admin")); ?>
