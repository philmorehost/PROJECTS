<?php session_start();
include("../func/bc-admin-config.php");
include("../func/bc-crypto-func.php");

$vid = $get_logged_admin_details['id'];

// Search/Filter
$where = "WHERE vendor_id='$vid'";
if(!empty($_GET['username'])) {
    $u = mysqli_real_escape_string($connection_server, $_GET['username']);
    $where .= " AND username='$u'";
}
if(!empty($_GET['status'])) {
    $s = mysqli_real_escape_string($connection_server, $_GET['status']);
    $where .= " AND status='$s'";
}

$sql = "SELECT * FROM sas_crypto_transactions $where ORDER BY created_at DESC LIMIT 500";
$res = mysqli_query($connection_server, $sql);
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Crypto Invoices | Admin", array("area" => "admin"));
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

.pk-app__wrap .badge{padding:.45rem .75rem}
.pk-app__wrap code{font-size:.8rem}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "CRYPTO INVOICE HISTORY", "crumb" => "Home / Crypto Invoices")); ?>

    <section class="section p-4">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3">
                <form class="row g-2">
                    <div class="col-md-4">
                        <input type="text" name="username" class="form-control" placeholder="Search Username" value="<?php echo htmlspecialchars($_GET['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="1" <?php echo ($_GET['status']??'')=='1'?'selected':''; ?>>Success</option>
                            <option value="2" <?php echo ($_GET['status']??'')=='2'?'selected':''; ?>>Pending</option>
                            <option value="3" <?php echo ($_GET['status']??'')=='3'?'selected':''; ?>>Expired/Failed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">User</th>
                                <th>Reference</th>
                                <th>Asset</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th class="pe-4">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($res && mysqli_num_rows($res) > 0): while($row = mysqli_fetch_assoc($res)):
                                $s = $row['status'];
                                $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                $txt = ($s == 1) ? 'Success' : (($s == 2) ? 'Pending' : 'Expired');
                            ?>
                            <tr>
                                <td class="ps-4">@<?php echo $row['username']; ?></td>
                                <td><small class="text-muted"><?php echo $row['reference']; ?></small></td>
                                <td><?php echo $row['currency_code']; ?></td>
                                <td class="fw-bold"><?php echo (float)$row['amount']; ?></td>
                                <td><span class="badge bg-<?php echo $c; ?> bg-opacity-10 text-<?php echo $c; ?>"><?php echo $txt; ?></span></td>
                                <td class="pe-4"><small><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></small></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No crypto invoices found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    


<?php bc_adm_foot(array("area" => "admin")); ?>
