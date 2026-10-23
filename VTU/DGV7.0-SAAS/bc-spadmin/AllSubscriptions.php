<?php
session_start();
include("../func/bc-spadmin-config.php");

$page_title = "All Vendor Subscriptions";
?>
<!DOCTYPE html>
<head>
    <title><?php echo $page_title; ?> | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
      <h1><?php echo $page_title; ?></h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-4 border-0">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-0 text-primary">Vendor Subscriptions</h5>
                            <p class="text-muted small mb-0">Track and manage all recurring billing records</p>
                        </div>
                        <div class="col-md-6">
                            <form method="get" action="AllSubscriptions.php" class="d-flex gap-2 justify-content-md-end">
                                <input name="searchq" type="text" value="<?php echo htmlspecialchars($_GET['searchq'] ?? ''); ?>" placeholder="Search vendor email..." class="form-control rounded-pill px-3" style="max-width: 250px;">
                                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Search</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr class="small text-uppercase text-muted">
                                    <th class="ps-4">S/N</th>
                                    <th>Vendor</th>
                                    <th>Package</th>
                                    <th>Purchase Date</th>
                                    <th>Expiry</th>
                                    <th class="text-end pe-4">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $limit = 20;
                                $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
                                $offset = ($page - 1) * $limit;

                                $search_query = "";
                                $params = [];
                                $types = "";

                                if (!empty($_GET['searchq'])) {
                                    $search_term = '%' . $_GET['searchq'] . '%';
                                    $search_query = " WHERE v.email LIKE ?";
                                    $params[] = $search_term;
                                    $types .= "s";
                                }

                                $count_stmt = $connection_server->prepare("SELECT COUNT(*) FROM sas_vendor_subscriptions s JOIN sas_vendors v ON s.vendor_id = v.id" . $search_query);
                                if ($search_query) {
                                    $count_stmt->bind_param($types, ...$params);
                                }
                                $count_stmt->execute();
                                $total_records = $count_stmt->get_result()->fetch_row()[0];
                                $total_pages = ceil($total_records / $limit);
                                $count_stmt->close();

                                $sql = "SELECT s.purchase_date, s.expiry_date, s.amount_paid, p.name as package_name, v.email as vendor_email
                                        FROM sas_vendor_subscriptions s
                                        JOIN sas_vendors v ON s.vendor_id = v.id
                                        JOIN sas_billing_packages p ON s.package_id = p.id
                                        $search_query
                                        ORDER BY s.purchase_date DESC
                                        LIMIT ? OFFSET ?";

                                $params_list = array_merge($params, [$limit, $offset]);
                                $types_list = $types . "ii";

                                $stmt = $connection_server->prepare($sql);
                                $stmt->bind_param($types_list, ...$params_list);
                                $stmt->execute();
                                $result = $stmt->get_result();

                                if ($result->num_rows > 0) {
                                    $count = $offset + 1;
                                    while ($row = $result->fetch_assoc()) {
                                        $is_expired = (strtotime($row['expiry_date']) < time());
                                        $expiry_badge = $is_expired ? '<span class="badge bg-danger bg-opacity-10 text-danger rounded-pill">Expired</span>' : '<span class="badge bg-success bg-opacity-10 text-success rounded-pill">Active</span>';

                                        echo '<tr>
                                                <td class="ps-4 text-muted">' . $count++ . '</td>
                                                <td><div class="fw-bold text-dark">' . htmlspecialchars($row['vendor_email']) . '</div></td>
                                                <td><span class="badge bg-primary bg-opacity-10 text-dark-primary rounded-3 px-3">' . htmlspecialchars($row['package_name']) . '</span></td>
                                                <td class="small text-muted">' . date("M j, Y, g:ia", strtotime($row['purchase_date'])) . '</td>
                                                <td>
                                                    <div class="small fw-bold ' . ($is_expired ? 'text-danger' : 'text-dark') . '">' . date("M j, Y", strtotime($row['expiry_date'])) . '</div>
                                                    ' . $expiry_badge . '
                                                </td>
                                                <td class="text-end pe-4 fw-bold">₦' . number_format($row['amount_paid'], 2) . '</td>
                                              </tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="6" class="text-center py-5 text-muted">No subscription records found.</td></tr>';
                                }
                                $stmt->close();
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if($total_pages > 1): ?>
                <div class="card-footer bg-white py-4 border-0">
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php if ($i == $page) echo 'active'; ?>">
                                    <a class="page-link rounded-circle mx-1 border-0 shadow-sm" href="?page=<?php echo $i; ?>&searchq=<?php echo htmlspecialchars($_GET['searchq'] ?? ''); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
      </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
