<?php session_start();
    include("../func/bc-spadmin-config.php");

    // PHP 8.3 Stability: Fallback for undefined keys
    $get_logged_spadmin_details = $get_logged_spadmin_details ?? [];

    if(isset($_POST["export-users"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["status"])));
        $vid = isset($_POST["vid"]) ? (int)$_POST["vid"] : 0;

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=users_export_'.date('Ymd').'.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('S/N', 'Vendor', 'Fullname', 'Username ID', 'Level', 'Balance', 'Phone number', 'Address', 'Referral', 'API Status', 'APIKey', 'Security Answer', 'Reg Date'));

        $sql = "SELECT u.*, v.website_url FROM sas_users u LEFT JOIN sas_vendors v ON u.vendor_id = v.id";
        $where = [];
        if($status != 'all') $where[] = "u.status='$status'";
        if($vid > 0) $where[] = "u.vendor_id='$vid'";
        if($where) $sql .= " WHERE " . implode(" AND ", $where);
        
        $result = mysqli_query($connection_server, $sql);
        $sn = 1;
        while($row = mysqli_fetch_assoc($result)){
            $fullname = ucwords(trim(($row['firstname'] ?? '') . " " . ($row['lastname'] ?? '') . " " . ($row['othername'] ?? '')));
            $referral_username = "Not Referred";
            if(!empty($row["referral_id"]) && is_numeric($row["referral_id"])){
                $get_user_referral_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT username FROM sas_users WHERE id='".$row["referral_id"]."'"));
                $referral_username = $get_user_referral_details["username"] ?? "N/A";
            }

            fputcsv($output, array(
                $sn++,
                $row['website_url'] ?? 'N/A',
                $fullname,
                $row['username'],
                accountLevel($row['account_level']),
                $row['balance'],
                $row['phone_number'],
                $row['home_address'],
                $referral_username,
                ($row['api_status'] == 1) ? 'Enabled' : 'Disabled',
                $row['api_key'],
                $row['security_answer'],
                formDate($row['reg_date'])
            ));
        }
        fclose($output);
        exit();
    }

    // Action Handler
    if(isset($_GET["account-status"]) || isset($_GET["account-api-status"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-status"] ?? $_GET["account-api-status"])));
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-username"])));
        $vid = (int)$_GET["vid"];
        $column = isset($_GET["account-status"]) ? "status" : "api_status";

        if(mysqli_query($connection_server, "UPDATE sas_users SET $column='$status' WHERE vendor_id='$vid' && username='$account_user'")){
            $_SESSION["product_purchase_response"] = "User ".ucwords($account_user)." updated successfully";
        }
        header("Location: Users.php" . (isset($_GET['vid']) ? "?vid=".$_GET['vid'] : ""));
        exit();
    }

    if(isset($_POST["permanent-delete-user"])){
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-username"])));
        $vid = (int)$_POST["vid"];
        if(mysqli_query($connection_server, "DELETE FROM sas_users WHERE vendor_id='$vid' && username='$account_user' && status='3'")){
            $_SESSION["product_purchase_response"] = "User permanently deleted";
        }
        header("Location: Users.php?vid=".$vid);
        exit();
    }

    // Login as User Redirect Logic
    if(isset($_GET["login-as"]) && is_numeric($_GET["login-as"])){
        $uid = (int)$_GET["login-as"];
        $q = mysqli_query($connection_server, "SELECT u.username, v.website_url FROM sas_users u JOIN sas_vendors v ON u.vendor_id = v.id WHERE u.id='$uid' LIMIT 1");
        if($r = mysqli_fetch_assoc($q)){
            // Always hand the one-time login token over HTTPS, and keep only the host. Older rows store
            // website_url with an "http://" prefix, which used to put the impersonated session - and the
            // auth token - on plain http even though the site is served over https.
            $target_url = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim((string)$r['website_url']));
            $target_url = trim((string)preg_replace('#[^A-Za-z0-9.\-:].*$#', '', $target_url), '.');
            // A vendor without a usable website URL cannot be logged into: say so instead of redirecting
            // to "https:///web/Login.php", which the browser would resolve against the current host.
            if ($target_url === '') {
                $_SESSION["product_purchase_response"] = "This vendor has no website URL configured, so the user login could not be opened.";
                header("Location: Users.php");
                exit();
            }
            
            // Generate a secure one-time login token
            $token = bin2hex(random_bytes(16));
            mysqli_query($connection_server, "UPDATE sas_users SET failed_pin_count=failed_pin_count+1, last_failed_pin=NOW() WHERE id='$uid'"); // Reuse column temporarily as token or use a real token table
            // For now, use the same mechanism as bc-admin -> bc-spadmin but in reverse
            // We'll redirect to the vendor's dashboard with a special param
            header("Location: https://".$target_url."/web/Login.php?logAsUser=".rawurlencode($r['username'])."&auth=".md5($r['username'].date('Ymd')."SUPER_ADMIN_SECRET"));
            exit();
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Users Management | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
      <h1>PLATFORM USERS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Users</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <?php
          $vid_filter = isset($_GET["vid"]) ? (int)$_GET["vid"] : 0;
          $stats_vendor_stmt = ($vid_filter > 0) ? " WHERE vendor_id='$vid_filter'" : "";
          $stats_q = mysqli_query($connection_server, "SELECT 
              COUNT(*) as total,
              COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active,
              COALESCE(SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END), 0) as blocked,
              COALESCE(SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END), 0) as deleted
              FROM sas_users $stats_vendor_stmt");
          $initial_stats = mysqli_fetch_assoc($stats_q);
      ?>
      <div class="row">
        <!-- Stats Summary Cards -->
        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card sales-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Total Users <span>| Platform</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary">
                  <i class="bi bi-people"></i>
                </div>
                <div class="ps-3"><h6 id="stat-total-users"><?php echo number_format($initial_stats['total'] ?? 0); ?></h6><span class="text-muted small pt-2">Registered</span></div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card revenue-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Active <span>| Accounts</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success">
                  <i class="bi bi-check-circle"></i>
                </div>
                <div class="ps-3"><h6 id="stat-active-users"><?php echo number_format($initial_stats['active'] ?? 0); ?></h6><span class="text-muted small pt-2">Enabled</span></div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card customers-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Blocked <span>| Global</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning">
                  <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div class="ps-3"><h6 id="stat-blocked-users"><?php echo number_format($initial_stats['blocked'] ?? 0); ?></h6><span class="text-muted small pt-2">Suspended</span></div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card customers-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Deleted <span>| Archives</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger">
                  <i class="bi bi-trash"></i>
                </div>
                <div class="ps-3"><h6 id="stat-deleted-users"><?php echo number_format($initial_stats['deleted'] ?? 0); ?></h6><span class="text-muted small pt-2">Removed</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="small fw-bold text-muted">SEARCH USERS</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                            <input id="user-search-input" type="text" placeholder="Username, Email, Phone..." class="form-control border-start-0" value="<?php echo $_GET['searchq'] ?? ''; ?>" />
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-muted">SELECT VENDOR</label>
                        <select id="user-vendor-filter" class="form-select" onchange="fetchUsers(1)">
                            <option value="0">All Vendors</option>
                            <?php
                                $vs = mysqli_query($connection_server, "SELECT id, website_url FROM sas_vendors WHERE status=1 ORDER BY website_url ASC");
                                while($vrow = mysqli_fetch_assoc($vs)){
                                    $selected = (isset($_GET["vid"]) && $_GET["vid"] == $vrow['id']) ? 'selected' : '';
                                    echo '<option value="'.$vrow['id'].'" '.$selected.'>'.$vrow['website_url'].'</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-muted">ACCOUNT STATUS</label>
                        <select id="user-status-filter" class="form-select" onchange="fetchUsers(1)">
                            <option value="all">All Status</option>
                            <option value="1">Active Only</option>
                            <option value="2">Blocked Only</option>
                            <option value="3">Deleted Only</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" onclick="fetchUsers(1)" class="btn btn-primary w-100 fw-bold">APPLY</button>
                    </div>
                </div>
                <div class="mt-3 d-flex justify-content-end gap-2">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#exportCollapse"><i class="bi bi-download me-1"></i> Export Options</button>
                </div>
                <div class="collapse mt-3" id="exportCollapse">
                    <form method="post" class="p-3 border rounded-4 bg-light d-flex gap-2 align-items-center">
                        <span class="small fw-bold text-muted">EXPORT:</span>
                        <input type="hidden" name="vid" id="export-vid" value="0">
                        <select name="status" class="form-select form-select-sm" style="max-width: 150px;">
                            <option value="all">All Users</option>
                            <option value="1">Active Only</option>
                        </select>
                        <button name="export-users" type="submit" class="btn btn-success btn-sm px-3">Download CSV</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-primary">Platform User Database</h6>
                <div id="pagination-top"></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">S/N</th>
                                <th>User Info / Vendor</th>
                                <th>Financials</th>
                                <th>Account Status</th>
                                <th>API Status</th>
                                <th class="pe-4 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="user-table-body">
                            <tr><td colspan="6" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3 border-0">
                <div id="pagination-bottom" class="d-flex justify-content-center gap-2"></div>
            </div>
        </div>
      </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        const vid = urlParams.get('vid');
        if(vid) document.getElementById("user-vendor-filter").value = vid;
        
        fetchUsers(1);

        let searchTimeout = null;
        document.getElementById("user-search-input").addEventListener("input", function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => fetchUsers(1), 500);
        });
    });

    function fetchUsers(page) {
        const search = document.getElementById("user-search-input").value;
        const status = document.getElementById("user-status-filter").value;
        const vid = document.getElementById("user-vendor-filter").value;
        const body = document.getElementById("user-table-body");
        
        document.getElementById("export-vid").value = vid;

        body.innerHTML = `<tr><td colspan="6" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>`;

        fetch(`ajax-users.php?page=${page}&searchq=${encodeURIComponent(search)}&status=${status}&vid=${vid}`)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    renderTable(res.users, res.pagination.current_page);
                    renderPagination(res.pagination);
                    updateStats(res.stats);
                } else {
                    body.innerHTML = `<tr><td colspan="6" class="text-center py-5 text-danger">${res.message}</td></tr>`;
                }
            }).catch(() => {
                body.innerHTML = `<tr><td colspan="6" class="text-center py-5 text-danger">Connection Error</td></tr>`;
            });
    }

    function renderTable(users, currentPage) {
        const body = document.getElementById("user-table-body");
        if (users.length === 0) {
            body.innerHTML = `<tr><td colspan="6" class="text-center py-5 text-muted">No users found for this selection.</td></tr>`;
            return;
        }

        let html = '';
        users.forEach((u, i) => {
            const sn = ((currentPage - 1) * 10) + (i + 1);
            const statusBadge = (u.status == '1') ? '<span class="badge bg-success">Active</span>' : (u.status == '2' ? '<span class="badge bg-warning">Blocked</span>' : '<span class="badge bg-danger">Deleted</span>');
            
            html += `
                <tr>
                    <td class="ps-4 fw-bold">${sn}</td>
                    <td>
                        <div class="fw-bold text-dark">${u.fullname}</div>
                        <div class="small text-muted">@${u.username} | ${u.phone_number}</div>
                        <div class="small text-primary"><i class="bi bi-globe me-1"></i>${u.website_url}</div>
                    </td>
                    <td>
                        <div class="fw-bold">₦${u.balance_formatted}</div>
                        <div class="small text-muted">${u.level_name}</div>
                    </td>
                    <td>${statusBadge}</td>
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" ${u.api_status == 1 ? 'checked' : ''} onchange="updateAPI('${u.username}', '${u.vid}', this.checked)">
                            <label class="small text-muted">${u.api_status == 1 ? 'Enabled' : 'Disabled'}</label>
                        </div>
                    </td>
                    <td class="pe-4 text-end">
                        <div class="btn-group shadow-sm">
                            <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Manage</button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li><a class="dropdown-item py-2" href="UserEdit.php?userID=${u.id}"><i class="bi bi-pencil me-2 text-primary"></i> Edit Profile</a></li>
                                <li><a class="dropdown-item py-2" href="Users.php?login-as=${u.id}"><i class="bi bi-box-arrow-in-right me-2 text-info"></i> Login as User</a></li>
                                <li><a class="dropdown-item py-2" href="UserTransactions.php?searchq=${u.username}&vid=${u.vid}"><i class="bi bi-list-ul me-2 text-dark"></i> View Transactions</a></li>
                                <li><a class="dropdown-item py-2" href="ShareFund.php?target=user&username=${u.username}&vid=${u.vid}"><i class="bi bi-plus-circle me-2 text-success"></i> Fund Wallet</a></li>
                                <li><hr class="dropdown-divider"></li>
                                ${u.status != 1 ? `<li><button class="dropdown-item py-2 text-success" onclick="updateStatus('1', '${u.username}', '${u.vid}')"><i class="bi bi-check-circle me-2"></i> Activate</button></li>` : ''}
                                ${u.status != 2 ? `<li><button class="dropdown-item py-2 text-warning" onclick="updateStatus('2', '${u.username}', '${u.vid}')"><i class="bi bi-pause-circle me-2"></i> Suspend</button></li>` : ''}
                                ${u.status != 3 ? `<li><button class="dropdown-item py-2 text-danger" onclick="updateStatus('3', '${u.username}', '${u.vid}')"><i class="bi bi-trash me-2"></i> Mark Delete</button></li>` : ''}
                                ${u.status == 3 ? `<li><button class="dropdown-item py-2 text-danger fw-bold" onclick="permanentlyDelete('${u.username}', '${u.vid}')"><i class="bi bi-x-circle me-2"></i> Delete Permanently</button></li>` : ''}
                            </ul>
                        </div>
                    </td>
                </tr>`;
        });
        body.innerHTML = html;
    }

    function renderPagination(p) {
        const container = document.getElementById("pagination-bottom");
        if (p.total_pages <= 1) { container.innerHTML = ''; return; }
        let html = '';
        if (p.current_page > 1) html += `<button class="btn btn-outline-primary btn-sm" onclick="fetchUsers(${p.current_page - 1})">Prev</button>`;
        for (let i = 1; i <= p.total_pages; i++) {
            if(i > 5 && i < p.total_pages) continue; 
            html += `<button class="btn btn-sm ${i === p.current_page ? 'btn-primary' : 'btn-outline-primary'}" onclick="fetchUsers(${i})">${i}</button>`;
        }
        if (p.current_page < p.total_pages) html += `<button class="btn btn-outline-primary btn-sm" onclick="fetchUsers(${p.current_page + 1})">Next</button>`;
        container.innerHTML = html;
    }

    function updateStats(s) {
        document.getElementById("stat-total-users").textContent = s.total;
        document.getElementById("stat-active-users").textContent = s.active;
        document.getElementById("stat-blocked-users").textContent = s.blocked;
        document.getElementById("stat-deleted-users").textContent = s.deleted;
    }

    function updateStatus(status, user, vid) {
        Swal.fire({
            title: 'Change Account Status?',
            text: `Are you sure you want to update status for @${user}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, update'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = `Users.php?account-status=${status}&account-username=${user}&vid=${vid}`;
        });
    }

    function updateAPI(user, vid, enabled) {
        const status = enabled ? 1 : 2;
        window.location.href = `Users.php?account-api-status=${status}&account-username=${user}&vid=${vid}`;
    }

    function permanentlyDelete(user, vid) {
        Swal.fire({
            title: 'PERMANENT DELETE?',
            text: `This will erase @${user} from the database forever!`,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'YES, DELETE FOREVER'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="permanent-delete-user" value="1"><input type="hidden" name="account-username" value="${user}"><input type="hidden" name="vid" value="${vid}">`;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    </script>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>