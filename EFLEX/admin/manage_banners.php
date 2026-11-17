<?php
require_once '../includes/db_connect.php'; // Need DB for processing
session_start(); // Need session for require_permission

$message = "";

// Function to handle image upload
function handle_banner_upload($file_input_name) {
    if(isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]["error"] == 0){
        $allowed = ["jpg" => "image/jpeg", "png" => "image/png", "gif" => "image/gif"];
        $filename = $_FILES[$file_input_name]["name"];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if(!array_key_exists($ext, $allowed)) {
            return [ "error" => "Invalid file type. Please upload a JPG, PNG, or GIF." ];
        }

        $new_filename = "banner_" . uniqid() . "." . $ext;
        $upload_path = "../uploads/" . $new_filename;

        if(move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $upload_path)){
            return [ "success" => $new_filename ];
        } else {
            return [ "error" => "Error uploading file." ];
        }
    }
    return [ "no_file" => true ];
}

// Handle Add Banner
if(isset($_POST['add_banner'])){
    $link_url = trim($_POST['link_url']);

    $upload_result = handle_banner_upload('image');
    if(isset($upload_result['error'])){
        $message = '<div class="alert alert-danger">' . $upload_result['error'] . '</div>';
    } elseif(isset($upload_result['no_file'])) {
        $message = '<div class="alert alert-danger">Please select an image to upload.</div>';
    } else {
        $image_filename = $upload_result['success'];
        $sql = "INSERT INTO banners (image_url, link_url, is_active) VALUES (?, ?, ?)";
        if($stmt = $mysqli->prepare($sql)){
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $stmt->bind_param("ssi", $image_filename, $link_url, $is_active);
            if($stmt->execute()){
                $message = '<div class="alert alert-success">Banner added successfully.</div>';
            } else {
                $message = '<div class="alert alert-danger">Error adding banner.</div>';
            }
            $stmt->close();
        }
    }
}

// Handle Delete Banner
if(isset($_GET['delete'])){
    $id = $_GET['delete'];

    // Get image filename to delete it
    $sql_img = "SELECT image_url FROM banners WHERE id = ?";
    $stmt_img = $mysqli->prepare($sql_img);
    $stmt_img->bind_param("i", $id);
    $stmt_img->execute();
    $stmt_img->bind_result($image_to_delete);
    $stmt_img->fetch();
    $stmt_img->close();

    // Delete from DB
    $sql_delete = "DELETE FROM banners WHERE id = ?";
    if($stmt_delete = $mysqli->prepare($sql_delete)){
        $stmt_delete->bind_param("i", $id);
        if($stmt_delete->execute()){
            if($image_to_delete && file_exists("../uploads/" . $image_to_delete)){
                unlink("../uploads/" . $image_to_delete);
            }
            header("location: manage_banners.php");
            exit();
        } else {
            $message = '<div class="alert alert-danger">Error deleting banner.</div>';
        }
        $stmt_delete->close();
    }
}

// Now include the header and display the page
include 'includes/admin_header.php';
require_permission('manage_banners');

// Fetch all banners for display
$sql = "SELECT * FROM banners ORDER BY created_at DESC";
$result = $mysqli->query($sql);
$banners = $result->fetch_all(MYSQLI_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Banners</h2>
</div>

<?php echo $message; ?>

<!-- Form for Add Banner -->
<div class="card shadow mb-4">
    <div class="card-header">Add New Banner</div>
    <div class="card-body">
        <form action="manage_banners.php" method="post" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="image" class="form-label">Banner Image</label>
                    <input type="file" name="image" id="image" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="link_url" class="form-label">Link URL (optional)</label>
                    <input type="url" name="link_url" id="link_url" class="form-control" placeholder="https://example.com">
                </div>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                <label class="form-check-label" for="is_active">
                    Active
                </label>
            </div>
            <button class="btn btn-primary" type="submit" name="add_banner">Add Banner</button>
        </form>
    </div>
</div>

<!-- Banners Table -->
<div class="card shadow">
    <div class="card-header">Existing Banners</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Link URL</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($banners) > 0): ?>
                        <?php foreach ($banners as $banner): ?>
                        <tr>
                            <td>
                                <img src="../uploads/<?php echo htmlspecialchars($banner['image_url']); ?>" alt="Banner" style="width: 150px; object-fit: cover;">
                            </td>
                            <td><?php echo htmlspecialchars($banner['link_url']); ?></td>
                            <td><?php echo $banner['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></td>
                            <td class="text-end">
                                <a href="manage_banners.php?delete=<?php echo $banner['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this banner?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center">No banners found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>
