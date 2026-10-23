<?php session_start();
include("../func/bc-admin-config.php");

$vid = $get_logged_admin_details['id'];
$esc_vid = (int)$vid;

// Handle Update API Gateway
if (isset($_POST['update-api'])) {
    bc_validate_csrf();
    $api_id = (int)$_POST['api_id'];
    $api_key = bc_sanitize($_POST['api_key'] ?? '');
    $status = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
    $apply_to_all_matching = isset($_POST['apply_to_all_matching']) && $_POST['apply_to_all_matching'] == '1';

    $api_key_esc = mysqli_real_escape_string($connection_server, $api_key);

    if ($apply_to_all_matching) {
        // A single provider (e.g. HDKDATA.COM) is often configured under several service types
        // (SME Data, CG Data, Shared Data...) with the SAME key. Instead of editing each one
        // individually, update every sas_apis row for this vendor sharing this base URL at once.
        $get_base_url_q = mysqli_query($connection_server, "SELECT api_base_url FROM sas_apis WHERE id='$api_id' AND vendor_id='$esc_vid' LIMIT 1");
        $get_base_url_row = $get_base_url_q ? mysqli_fetch_assoc($get_base_url_q) : null;
        if ($get_base_url_row) {
            $base_url_esc = mysqli_real_escape_string($connection_server, $get_base_url_row['api_base_url']);
            mysqli_query($connection_server, "UPDATE sas_apis SET api_key='$api_key_esc', status='$status' WHERE vendor_id='$esc_vid' AND api_base_url='$base_url_esc'");
            $affected = mysqli_affected_rows($connection_server);
            $_SESSION['product_purchase_response'] = "✅ Updated $affected API Gateway(s) sharing base URL '" . htmlspecialchars($get_base_url_row['api_base_url']) . "'!";
        } else {
            $_SESSION['product_purchase_response'] = "❌ Gateway not found.";
        }
    } else {
        mysqli_query($connection_server, "UPDATE sas_apis SET api_key='$api_key_esc', status='$status' WHERE id='$api_id' AND vendor_id='$esc_vid'");
        $_SESSION['product_purchase_response'] = "✅ API Gateway updated successfully!";
    }
    header("Location: MarketPlace.php");
    exit();
}

// Handle Add Custom API Gateway
if (isset($_POST['add-api'])) {
    bc_validate_csrf();
    $api_type = bc_sanitize($_POST['api_type'] ?? 'airtime');
    $api_base_url = bc_sanitize($_POST['api_base_url'] ?? '');
    $api_key = bc_sanitize($_POST['api_key'] ?? '');
    $status = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
    
    if (!empty($api_base_url)) {
        $api_type_esc = mysqli_real_escape_string($connection_server, $api_type);
        $api_url_esc = mysqli_real_escape_string($connection_server, strtolower(str_replace(["http://", "https://"], "", $api_base_url)));
        $api_key_esc = mysqli_real_escape_string($connection_server, $api_key);
        
        mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_type, api_base_url, api_key, status) VALUES ('$esc_vid', '$api_type_esc', '$api_url_esc', '$api_key_esc', '$status')");
        $_SESSION['product_purchase_response'] = "✅ Custom API Gateway added successfully!";
    } else {
        $_SESSION['product_purchase_response'] = "❌ Please enter a valid base URL.";
    }
    header("Location: MarketPlace.php");
    exit();
}

// Handle Delete API Gateway (Optional safety feature for custom additions)
if (isset($_GET['delete-api'])) {
    $api_id = (int)$_GET['delete-api'];
    $get_deleted_api = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT api_type FROM sas_apis WHERE id='$api_id' AND vendor_id='$esc_vid' LIMIT 1"));
    mysqli_query($connection_server, "DELETE FROM sas_apis WHERE id='$api_id' AND vendor_id='$esc_vid'");

    // sas_apis has no FK/cascade, so every product/network still assigned to this gateway (in
    // whichever sas_*_status table matches its api_type) would otherwise keep a dangling api_id
    // forever and fail every purchase with "Gateway Error" instead of falling back cleanly.
    // Re-point those rows at another remaining active gateway of the same type if one exists,
    // otherwise clear the reference so the product correctly shows as unconfigured.
    if ($get_deleted_api) {
        $status_tables_by_type = [
            'airtime' => 'sas_airtime_status', 'shared-data' => 'sas_shared_data_status',
            'sme-data' => 'sas_sme_data_status', 'cg-data' => 'sas_cg_data_status',
            'dd-data' => 'sas_dd_data_status', 'cable' => 'sas_cable_status',
            'electric' => 'sas_electric_status', 'exam' => 'sas_exam_status',
            'betting' => 'sas_betting_status', 'bulk-sms' => 'sas_bulk_sms_status',
            'datacard' => 'sas_datacard_status', 'dollarcard' => 'sas_dollarcard_status',
            'nairacard' => 'sas_nairacard_status', 'rechargecard' => 'sas_rechargecard_status',
        ];
        $api_type_esc = mysqli_real_escape_string($connection_server, $get_deleted_api['api_type']);
        $status_table = $status_tables_by_type[$get_deleted_api['api_type']] ?? null;
        if ($status_table) {
            $fallback = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT id FROM sas_apis WHERE vendor_id='$esc_vid' AND api_type='$api_type_esc' AND status='1' ORDER BY id DESC LIMIT 1"));
            if ($fallback) {
                mysqli_query($connection_server, "UPDATE $status_table SET api_id='" . (int)$fallback['id'] . "' WHERE vendor_id='$esc_vid' AND api_id='$api_id'");
            } else {
                mysqli_query($connection_server, "UPDATE $status_table SET api_id='0' WHERE vendor_id='$esc_vid' AND api_id='$api_id'");
            }
        }
    }

    $_SESSION['product_purchase_response'] = "API Gateway deleted successfully.";
    header("Location: MarketPlace.php");
    exit();
}

// Load current API Gateways
$search_q = isset($_GET['searchq']) ? trim(strip_tags($_GET['searchq'])) : '';
$type_filter = isset($_GET['type']) ? trim(strip_tags($_GET['type'])) : '';

$where_clause = "WHERE vendor_id='$esc_vid'";
if (!empty($search_q)) {
    $search_esc = mysqli_real_escape_string($connection_server, $search_q);
    $where_clause .= " AND (api_base_url LIKE '%$search_esc%' OR api_type LIKE '%$search_esc%')";
}
if (!empty($type_filter)) {
    $type_esc = mysqli_real_escape_string($connection_server, $type_filter);
    $where_clause .= " AND api_type='$type_esc'";
}

$apis_q = mysqli_query($connection_server, "SELECT * FROM sas_apis $where_clause ORDER BY api_type ASC, api_base_url ASC");

// How many total entries (across ALL service types, ignoring the current search/type filter) share
// each base URL — used to surface the "apply to all matching" option only where it's meaningful.
$base_url_counts = [];
$count_q = mysqli_query($connection_server, "SELECT api_base_url, COUNT(*) as cnt FROM sas_apis WHERE vendor_id='$esc_vid' GROUP BY api_base_url");
if ($count_q) {
    while ($count_row = mysqli_fetch_assoc($count_q)) {
        $base_url_counts[$count_row['api_base_url']] = (int)$count_row['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>API Gateway Manager | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .api-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 1.25rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
        }
        .api-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }
        .filter-btn {
            border: none;
            background: #e2e8f0;
            border-radius: 50px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #64748b;
            text-decoration: none;
            transition: all 0.2s;
        }
        .filter-btn.active, .filter-btn:hover {
            background: var(--primary-color, #0d6efd);
            color: white !important;
        }
        .badge-type {
            font-size: 0.7rem;
            padding: 6px 12px;
            font-weight: 700;
            border-radius: 50px;
        }
    </style>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle mb-4">
        <h1 class="fw-bold">API Gateway Control Hub</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">API Manager</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <?php if (isset($_SESSION["product_purchase_response"])): ?>
            <div class="alert alert-info border-0 rounded-4 shadow-sm animate__animated animate__fadeInDown mb-4">
                <i class="bi bi-info-circle me-2"></i> <?php echo $_SESSION["product_purchase_response"]; unset($_SESSION["product_purchase_response"]); ?>
            </div>
        <?php endif; ?>

        <!-- Information Notice -->
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-start">
            <i class="bi bi-exclamation-triangle-fill text-warning fs-4 me-3"></i>
            <div>
                <h6 class="fw-bold mb-1 text-dark">Important API Notice</h6>
                <span class="small text-dark">All the API gateways listed here are pre-configured in the script's core API manager. Adding an ordinary or random URL will not work unless the corresponding API integration files have been preconfigured by the developer.</span>
            </div>
        </div>

        <!-- Search & Control Header -->
        <div class="card border-0 rounded-4 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <form method="get" class="d-flex gap-2">
                            <?php if(!empty($type_filter)): ?>
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">
                            <?php endif; ?>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-0"><i class="bi bi-search text-muted"></i></span>
                                <input name="searchq" type="text" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Search gateways..." class="form-control border-0 bg-light">
                            </div>
                            <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">Search</button>
                        </form>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <button class="btn btn-primary rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addApiModal">
                            <i class="bi bi-plus-circle me-2"></i>Add Custom Gateway
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a href="MarketPlace.php" class="filter-btn <?php echo empty($type_filter) ? 'active' : ''; ?>">All Services</a>
                    <?php
                    // Key must match the api_type value each service page actually filters sas_apis by
                    // (e.g. BulkSMS.php uses 'bulk-sms', not 'sms') so a gateway added here shows up there.
                    $types = ['airtime' => 'Airtime', 'sme-data' => 'SME Data', 'cg-data' => 'CG Data', 'dd-data' => 'Direct Data', 'shared-data' => 'Shared Data', 'cable' => 'Cable TV', 'electric' => 'Electricity', 'exam' => 'Exam Pins', 'betting' => 'Betting', 'bulk-sms' => 'Bulk SMS'];
                    foreach ($types as $key => $lbl):
                    ?>
                        <a href="MarketPlace.php?type=<?php echo $key; ?>&searchq=<?php echo urlencode($search_q); ?>" class="filter-btn <?php echo $type_filter === $key ? 'active' : ''; ?>"><?php echo $lbl; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- API Gateways Grid -->
        <div class="row g-4 mb-5">
            <?php if ($apis_q && mysqli_num_rows($apis_q) > 0): while($api = mysqli_fetch_assoc($apis_q)): 
                $provider = htmlspecialchars($api['api_base_url']);
                $type = htmlspecialchars($api['api_type']);
                $key = htmlspecialchars($api['api_key']);
                $status = (int)$api['status'];
                
                // Color badge based on service type
                $badge_colors = [
                    'airtime' => 'bg-primary-subtle text-primary',
                    'sme-data' => 'bg-success-subtle text-success',
                    'cg-data' => 'bg-info-subtle text-info',
                    'dd-data' => 'bg-warning-subtle text-warning-emphasis',
                    'shared-data' => 'bg-indigo-subtle text-indigo',
                    'cable' => 'bg-danger-subtle text-danger',
                    'electric' => 'bg-purple-subtle text-purple',
                    'exam' => 'bg-secondary-subtle text-secondary-emphasis',
                    'betting' => 'bg-success-subtle text-success',
                    'bulk-sms' => 'bg-pink-subtle text-pink'
                ];
                $badge_class = $badge_colors[$type] ?? 'bg-light text-dark';
            ?>
            <div class="col-md-6 col-lg-4 col-xl-3 animate__animated animate__fadeInUp">
                <div class="card h-100 api-card border-0 p-2 bg-white">
                    <div class="card-body d-flex flex-column p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-light p-2 rounded-3 me-2">
                                    <i class="bi bi-link-45deg fs-4 text-primary"></i>
                                </div>
                                <span class="badge badge-type <?php echo $badge_class; ?>"><?php echo strtoupper($types[$type] ?? $type); ?></span>
                            </div>
                            <div class="form-check form-switch p-0 m-0">
                                <span class="badge rounded-pill px-2 py-1 <?php echo $status === 1 ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $status === 1 ? 'ACTIVE' : 'INACTIVE'; ?>
                                </span>
                            </div>
                        </div>

                        <h6 class="fw-bold text-dark mb-1">https://<?php echo $provider; ?></h6>
                        <div class="mb-3">
                            <?php if(!empty($key)): ?>
                                <span class="text-success small fw-bold"><i class="bi bi-shield-check me-1"></i>Key Configured</span>
                            <?php else: ?>
                                <span class="text-danger small fw-bold"><i class="bi bi-shield-exclamation me-1"></i>No API Key Set</span>
                            <?php endif; ?>
                        </div>

                        <div class="mt-auto pt-3 d-flex gap-2">
                            <button class="btn btn-outline-dark rounded-pill px-3 py-2 btn-sm w-100 fw-bold"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editApiModal"
                                    data-id="<?php echo $api['id']; ?>"
                                    data-url="<?php echo $provider; ?>"
                                    data-type="<?php echo $type; ?>"
                                    data-key="<?php echo $key; ?>"
                                    data-status="<?php echo $status; ?>"
                                    data-count="<?php echo $base_url_counts[$api['api_base_url']] ?? 1; ?>">
                                <i class="bi bi-pencil-square me-1"></i>Configure
                            </button>
                            <a href="MarketPlace.php?delete-api=<?php echo $api['id']; ?>" 
                               class="btn btn-outline-danger rounded-circle p-2 btn-sm" 
                               onclick="return confirm('Are you sure you want to delete this custom API provider?')" 
                               title="Delete Gateway">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; else: ?>
            <div class="col-12 text-center py-5">
                <i class="bi bi-exclamation-octagon text-muted display-4 d-block mb-3"></i>
                <h5 class="text-muted fw-bold">No API Providers Found</h5>
                <p class="text-muted">No API providers match your search filters.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- EDIT API MODAL -->
    <div class="modal fade" id="editApiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-0 bg-light p-4">
                    <h5 class="modal-title fw-bold" id="editModalLabel">Configure Gateway</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <?php echo bc_csrf_field(); ?>
                    <input type="hidden" name="api_id" id="edit_api_id">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Gateway Website</label>
                            <input type="text" id="edit_api_url" class="form-control border-0 bg-light" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Service Type</label>
                            <input type="text" id="edit_api_type" class="form-control border-0 bg-light" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">API Key / Token</label>
                            <input type="password" name="api_key" id="edit_api_key" class="form-control rounded-3" placeholder="Enter API Key / Token">
                        </div>
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" name="status" value="1" id="edit_api_status">
                            <label class="form-check-label fw-bold text-dark" for="edit_api_status">Enable this Gateway Provider</label>
                        </div>
                        <div class="form-check mt-3 p-3 rounded-3 bg-light d-none" id="edit_apply_all_wrapper">
                            <input class="form-check-input" type="checkbox" name="apply_to_all_matching" value="1" id="edit_apply_all">
                            <label class="form-check-label small text-dark" for="edit_apply_all">
                                Apply this Key & Status to <b><span id="edit_apply_all_count"></span> other entries</b> that use this same Base URL (e.g. across SME Data, CG Data, Shared Data, etc.)
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update-api" class="btn btn-dark rounded-pill px-4 fw-bold">Save Configuration</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ADD API MODAL -->
    <div class="modal fade" id="addApiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-0 bg-light p-4">
                    <h5 class="modal-title fw-bold">Add Custom Gateway</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <?php echo bc_csrf_field(); ?>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Gateway Base URL <span class="text-danger">*</span></label>
                            <input type="text" name="api_base_url" class="form-control rounded-3" placeholder="e.g. customvtuapi.com" required>
                            <small class="text-muted">Do not include http:// or https://</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Service Type</label>
                            <select name="api_type" class="form-select rounded-3">
                                <?php foreach($types as $key => $lbl): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $lbl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">API Key / Token</label>
                            <input type="password" name="api_key" class="form-control rounded-3" placeholder="Enter API Key / Token">
                        </div>
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" name="status" value="1" id="add_api_status" checked>
                            <label class="form-check-label fw-bold text-dark" for="add_api_status">Enable this Gateway Provider immediately</label>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add-api" class="btn btn-primary rounded-pill px-4 fw-bold">Add Gateway</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include("../func/bc-admin-footer.php"); ?>
    <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        // Populate Configure Modal dynamically on click
        const editApiModal = document.getElementById('editApiModal');
        if (editApiModal) {
            editApiModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const url = button.getAttribute('data-url');
                const type = button.getAttribute('data-type');
                const key = button.getAttribute('data-key');
                const status = parseInt(button.getAttribute('data-status'));
                const count = parseInt(button.getAttribute('data-count')) || 1;

                document.getElementById('edit_api_id').value = id;
                document.getElementById('edit_api_url').value = 'https://' + url;
                document.getElementById('edit_api_type').value = type.toUpperCase().replace('_', ' ').replace('-', ' ');
                document.getElementById('edit_api_key').value = key;
                document.getElementById('edit_api_status').checked = (status === 1);

                const applyAllWrapper = document.getElementById('edit_apply_all_wrapper');
                const applyAllCheckbox = document.getElementById('edit_apply_all');
                applyAllCheckbox.checked = false;
                if (count > 1) {
                    document.getElementById('edit_apply_all_count').textContent = (count - 1);
                    applyAllWrapper.classList.remove('d-none');
                } else {
                    applyAllWrapper.classList.add('d-none');
                }
            });
        }
    </script>
</body>
</html>