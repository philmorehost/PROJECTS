<?php session_start();
include("../func/bc-admin-config.php");

if (isset($_GET["approve"])) {
	$req_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["approve"])));
	$get_req = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_api_requests WHERE id='$req_id' AND vendor_id='" . $get_logged_admin_details["id"] . "' AND status='pending'"));

	if ($get_req) {
		$uid = $get_req['user_id'];
		$domain = $get_req['api_domain'];
		// Upgrade user to level 3 (API Vendor) and status 1
		mysqli_query($connection_server, "UPDATE sas_users SET account_level='3', api_status='1', status='1', api_domain='$domain' WHERE id='$uid' AND vendor_id='" . $get_logged_admin_details["id"] . "'");
		mysqli_query($connection_server, "UPDATE sas_api_requests SET status='approved', date_approved=NOW() WHERE id='$req_id'");

		$_SESSION["product_purchase_response"] = "API Access Approved and User Upgraded Successfully!";
	}
	header("Location: APIRequests.php");
	exit();
}

if (isset($_GET["reject"])) {
	$req_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["reject"])));
	mysqli_query($connection_server, "UPDATE sas_api_requests SET status='rejected' WHERE id='$req_id' AND vendor_id='" . $get_logged_admin_details["id"] . "'");
	$_SESSION["product_purchase_response"] = "API Access Request Rejected.";
	header("Location: APIRequests.php");
	exit();
}

$api_requests = mysqli_query($connection_server, "SELECT r.*, u.email as user_email, u.phone_number FROM sas_api_requests r JOIN sas_users u ON r.user_id = u.id WHERE r.vendor_id='" . $get_logged_admin_details["id"] . "' ORDER BY r.date_requested DESC");
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<title>API Access Requests | Admin</title>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
	<link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
	<link href="../assets-2/css/style.css" rel="stylesheet">
</head>

<body>
	<?php include("../func/bc-admin-header.php"); ?>

	<div class="pagetitle">
		<h1>API ACCESS REQUESTS</h1>
		<nav>
			<ol class="breadcrumb">
				<li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
				<li class="breadcrumb-item active">API Requests</li>
			</ol>
		</nav>
	</div>

	<section class="section">
		<div class="row">
			<div class="col-lg-12">
				<div class="card border-0 shadow-sm rounded-4">
					<div class="card-body p-4">
						<h5 class="fw-bold mb-4">Pending & Recent Requests</h5>

						<div class="table-responsive">
							<table class="table table-hover align-middle">
								<thead class="bg-light">
									<tr>
										<th>User Details</th>
										<th>Target Domain</th>
										<th>Date Requested</th>
										<th>Status</th>
										<th class="text-center">Action</th>
									</tr>
								</thead>
								<tbody>
									<?php if (mysqli_num_rows($api_requests) > 0) {
										while ($row = mysqli_fetch_assoc($api_requests)) { ?>
											<tr>
												<td>
													<div class="fw-bold text-dark"><?php echo htmlspecialchars($row['username']); ?></div>
													<div class="small text-muted"><?php echo htmlspecialchars($row['user_email']); ?></div>
													<div class="small text-muted"><?php echo htmlspecialchars($row['phone_number']); ?></div>
												</td>
												<td class="small">
													<div class="fw-bold text-primary"><?php echo !empty($row['api_domain']) ? htmlspecialchars($row['api_domain']) : '<span class="text-danger small">No Domain Set</span>'; ?></div>
												</td>
												<td class="small"><?php echo date('M d, Y H:i', strtotime($row['date_requested'])); ?></td>
												<td>
													<?php if ($row['status'] == 'pending') { ?>
														<span class="badge bg-warning text-dark px-3 py-2 rounded-pill">Pending</span>
													<?php } elseif ($row['status'] == 'approved') { ?>
														<span class="badge bg-success px-3 py-2 rounded-pill">Approved</span>
														<div class="small text-muted mt-1"><?php echo date('M d, Y', strtotime($row['date_approved'])); ?></div>
													<?php } else { ?>
														<span class="badge bg-danger px-3 py-2 rounded-pill">Rejected</span>
													<?php } ?>
												</td>
												<td class="text-center">
													<?php if ($row['status'] == 'pending') { ?>
														<div class="d-flex gap-2 justify-content-center">
															<a href="?approve=<?php echo $row['id']; ?>" class="btn btn-primary btn-sm rounded-pill px-3" onclick="return confirm('Approve this request? User will be upgraded to API level.')">Approve</a>
															<a href="?reject=<?php echo $row['id']; ?>" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirm('Reject this request?')">Reject</a>
														</div>
													<?php } else { ?>
														<span class="text-muted small">-</span>
													<?php } ?>
												</td>
											</tr>
										<?php }
									} else { ?>
										<tr>
											<td colspan="4" class="text-center py-5 text-muted">No API requests found.</td>
										</tr>
									<?php } ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<?php include("../func/bc-admin-footer.php"); ?>
</body>

</html>