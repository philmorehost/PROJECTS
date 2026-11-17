<?php
ob_start(); // keep output buffered so the delete redirect below isn't blocked
include 'includes/admin_header.php';
// require_permission('manage_modal_ads'); // Future permission

$message = "";

// --- Handle Form Submissions ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_ad'])) {
    $id = $_POST['id'];
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $start_time = empty($_POST['start_time']) ? NULL : $_POST['start_time'];
    $end_time = empty($_POST['end_time']) ? NULL : $_POST['end_time'];
    $show_countdown = isset($_POST['show_countdown']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $display_pages = json_encode($_POST['display_pages'] ?? []);
    $image_url = $_POST['existing_image'] ?? '';

    // Handle image upload
    if (isset($_FILES["image"]) && $_FILES["image"]["error"] == 0) {
        $allowed = ["jpg" => "image/jpeg", "png" => "image/png", "gif" => "image/gif"];
        $filename = $_FILES["image"]["name"];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, array_keys($allowed))) {
            $new_filename = "modal_ad_" . uniqid() . "." . $ext;
            if (move_uploaded_file($_FILES["image"]["tmp_name"], "../uploads/" . $new_filename)) {
                $image_url = $new_filename;
            }
        }
    }

    if (empty($id)) { // Add new
        $sql = "INSERT INTO modal_ads (title, content, image_url, start_time, end_time, show_countdown, display_pages, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssisi", $title, $content, $image_url, $start_time, $end_time, $show_countdown, $display_pages, $is_active);
    } else { // Update
        $sql = "UPDATE modal_ads SET title=?, content=?, image_url=?, start_time=?, end_time=?, show_countdown=?, display_pages=?, is_active=? WHERE id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssisii", $title, $content, $image_url, $start_time, $end_time, $show_countdown, $display_pages, $is_active, $id);
    }

    if ($stmt->execute()) {
        $message = '<div class="alert alert-success">Modal Ad saved successfully.</div>';
    } else {
        $message = '<div class="alert alert-danger">Error saving ad.</div>';
    }
    $stmt->close();
}

// Handle Delete
if(isset($_GET['delete'])){
    // This was an empty stub, so the Delete link silently did nothing.
    $stmt_delete = $mysqli->prepare("DELETE FROM modal_ads WHERE id = ?");
    if ($stmt_delete) {
        $ad_id = (int)$_GET['delete'];
        $stmt_delete->bind_param("i", $ad_id);
        $stmt_delete->execute();
        $stmt_delete->close();
    }
    header("Location: manage_modal_ads.php");
    exit;
}

// --- Data Fetching ---
$is_edit_mode = false;
$edit_ad = ['id'=>'', 'title'=>'', 'content'=>'', 'image_url'=>'', 'start_time'=>'', 'end_time'=>'', 'show_countdown'=>0, 'display_pages'=>'[]', 'is_active'=>1];
if(isset($_GET['edit'])){
    $is_edit_mode = true;
    $stmt = $mysqli->prepare("SELECT * FROM modal_ads WHERE id = ?");
    $stmt->bind_param("i", $_GET['edit']);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) $edit_ad = $result->fetch_assoc();
    $stmt->close();
}
$edit_ad['display_pages'] = json_decode($edit_ad['display_pages'], true) ?: [];

$ads_result = $mysqli->query("SELECT * FROM modal_ads ORDER BY created_at DESC");
$all_ads = $ads_result->fetch_all(MYSQLI_ASSOC);
$available_pages = ['index.php', 'products.php', 'product_detail.php', 'cart.php', 'checkout.php'];

?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Modal Ads</h2>
</div>
<?php echo $message; ?>

<div class="card shadow mb-4">
    <div class="card-header"><?php echo $is_edit_mode ? 'Edit Modal Ad' : 'Add New Modal Ad'; ?></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo $edit_ad['id']; ?>">
            <input type="hidden" name="existing_image" value="<?php echo $edit_ad['image_url']; ?>">
            <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($edit_ad['title']); ?>" required></div>
            <div class="mb-3"><label class="form-label">Content (HTML allowed)</label><textarea name="content" class="form-control" rows="4"><?php echo htmlspecialchars($edit_ad['content']); ?></textarea></div>
            <div class="mb-3"><label class="form-label">Image</label><input type="file" name="image" class="form-control"></div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Start Time (optional)</label><input type="datetime-local" name="start_time" class="form-control" value="<?php echo $edit_ad['start_time']; ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">End Time (optional)</label><input type="datetime-local" name="end_time" class="form-control" value="<?php echo $edit_ad['end_time']; ?>"></div>
            </div>
            <div class="mb-3"><label class="form-label">Display on Pages</label>
                <select name="display_pages[]" class="form-select" multiple size="5">
                    <?php foreach($available_pages as $page): ?>
                        <option value="<?php echo $page; ?>" <?php if(in_array($page, $edit_ad['display_pages'])) echo 'selected'; ?>><?php echo $page; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Hold Ctrl/Cmd to select multiple pages.</div>
            </div>
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_countdown" value="1" <?php if(!empty($edit_ad['show_countdown'])) echo 'checked'; ?>><label class="form-check-label">Show Countdown Timer</label></div>
            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php if(!empty($edit_ad['is_active'])) echo 'checked'; ?>><label class="form-check-label">Is Active</label></div>
            <button type="submit" name="save_ad" class="btn btn-primary">Save Ad</button>
        </form>
    </div>
</div>

<div class="card shadow">
    <div class="card-header">Existing Modal Ads</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead><tr><th>Title</th><th>Status</th><th>Display Pages</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($all_ads as $ad): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ad['title']); ?></td>
                        <td><?php echo $ad['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></td>
                        <td><?php echo implode(', ', json_decode($ad['display_pages'], true) ?: []); ?></td>
                        <td><a href="?edit=<?php echo $ad['id']; ?>" class="btn btn-sm btn-warning">Edit</a> <a href="?delete=<?php echo $ad['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
