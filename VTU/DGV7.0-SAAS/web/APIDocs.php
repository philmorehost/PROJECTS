<?php session_start();
include("../func/bc-config.php");

$api_request_status = null;
if (isset($get_logged_user_details)) {
	$check_req = mysqli_query($connection_server, "SELECT status FROM sas_api_requests WHERE user_id='" . $get_logged_user_details['id'] . "' ORDER BY id DESC LIMIT 1");
	if (mysqli_num_rows($check_req) > 0) {
		$api_request_status = mysqli_fetch_assoc($check_req)['status'];
	}
}

if (isset($_POST["request-api"])) {
	if (isset($get_logged_user_details) && $get_logged_user_details['account_level'] != 3) {
		if ($api_request_status !== 'pending') {
			$uid = $get_logged_user_details['id'];
			$uname = $get_logged_user_details['username'];
			$vid = $get_logged_user_details['vendor_id'];
			$domain = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['api_domain'])));
			mysqli_query($connection_server, "INSERT INTO sas_api_requests (vendor_id, user_id, username, api_domain, status) VALUES ('$vid', '$uid', '$uname', '$domain', 'pending')");
			$_SESSION["product_purchase_response"] = "API Access Request Submitted Successfully!";
			header("Location: " . $_SERVER["REQUEST_URI"]);
			exit();
		}
	}
}

if (isset($_POST["update-domain"])) {
	if (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3) {
		$domain = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['api_domain'])));
		mysqli_query($connection_server, "UPDATE sas_users SET api_domain='$domain' WHERE id='" . $get_logged_user_details["id"] . "'");
		$_SESSION["product_purchase_response"] = "API Whitelisted Domain Updated Successfully!";
		header("Location: " . $_SERVER["REQUEST_URI"]);
		exit();
	}
}

if (isset($_POST["generate-apikey"])) {
	if (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3) {
		$api_key = bin2hex(random_bytes(32));
		mysqli_query($connection_server, "UPDATE sas_users SET api_key='$api_key' WHERE id='" . $get_logged_user_details["id"] . "'");
		$_SESSION["product_purchase_response"] = "API Key Regenerated Successfully!";
		header("Location: " . $_SERVER["REQUEST_URI"]);
		exit();
	}
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<title>Developer API Documentation | <?php echo $get_all_site_details["site_title"]; ?></title>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
	<link rel="stylesheet" href="/cssfile/bc-style.css">

	<!-- Vendor CSS Files -->
	<link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
	<link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
	<link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
	<link href="../assets-2/css/style.css" rel="stylesheet">

	<style>
		:root {
			--api-primary: #4e73df;
			--api-dark: #1e293b;
			--api-bg: #f8fafc;
			--api-accent: #2563eb;
		}

		body {
			background-color: var(--api-bg);
			color: #334155;
		}

		.api-header {
			background: linear-gradient(135deg, var(--api-primary), var(--api-accent));
			color: white;
			padding: 100px 0 60px;
			margin-bottom: 50px;
			border-radius: 0 0 50px 50px;
			position: relative;
			z-index: 1;
		}

		.api-docs-sidebar {
			position: sticky;
			top: 100px;
			background: white;
			padding: 20px;
			border-radius: 20px;
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
			max-height: calc(100vh - 120px);
			overflow-y: auto;
		}

		.api-docs-sidebar .nav-link.api-link {
			color: #64748b !important;
			font-weight: 500;
			padding: 12px 15px;
			border-radius: 12px;
			margin-bottom: 5px;
			transition: all 0.2s;
			background: transparent !important;
		}

		.api-docs-sidebar .nav-link.api-link:hover,
		.api-docs-sidebar .nav-link.api-link.active {
			background-color: var(--api-primary) !important;
			color: white !important;
			transform: translateX(5px);
		}

		.card-api {
			border: none;
			border-radius: 20px;
			box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
			margin-bottom: 40px;
			background: white;
			overflow: hidden;
			transition: transform 0.3s ease;
		}

		.card-api:hover {
			transform: translateY(-5px);
		}

		.card-api .card-header {
			background-color: #f1f5f9;
			border-bottom: 1px solid #e2e8f0;
			padding: 20px 25px;
		}

		.card-api .card-body {
			padding: 30px 40px;
		}

		code {
			color: #2563eb;
			background: #eff6ff;
			padding: 4px 8px;
			border-radius: 6px;
			font-weight: 600;
		}

		pre {
			background: #0f172a;
			color: #f8fafc;
			padding: 20px;
			border-radius: 15px;
			position: relative;
			overflow-x: auto;
			border: 1px solid #334155;
			margin: 20px 0;
		}

		pre code {
			background: transparent !important;
			color: #38bdf8 !important;
			padding: 0;
			font-weight: 400;
		}

		/* Custom Scrollbar */
		.api-docs-sidebar::-webkit-scrollbar,
		pre::-webkit-scrollbar {
			width: 6px;
			height: 8px;
		}
		.api-docs-sidebar::-webkit-scrollbar-track,
		pre::-webkit-scrollbar-track {
			background: transparent;
		}
		.api-docs-sidebar::-webkit-scrollbar-thumb,
		pre::-webkit-scrollbar-thumb {
			background: #cbd5e1;
			border-radius: 10px;
		}
		pre::-webkit-scrollbar-thumb {
			background: #475569;
		}

		.copy-btn {
			position: absolute;
			top: 15px;
			right: 15px;
			background: rgba(255, 255, 255, 0.1);
			border: none;
			color: #94a3b8;
			padding: 8px 12px;
			border-radius: 10px;
			font-size: 13px;
			transition: all 0.2s;
		}

		.copy-btn:hover {
			background: rgba(255, 255, 255, 0.2);
			color: white;
		}

		.endpoint-box {
			background: #f8fafc;
			border: 1px solid #e2e8f0;
			border-radius: 12px;
			padding: 15px 20px;
			display: flex;
			align-items: center;
			gap: 15px;
			margin-bottom: 25px;
			flex-wrap: wrap;
		}

		.badge-method {
			padding: 8px 15px;
			border-radius: 8px;
			font-weight: 800;
			font-size: 12px;
			text-transform: uppercase;
		}
		.badge-get { background-color: #dbeafe; color: #1e40af; }
		.badge-post { background-color: #dcfce7; color: #166534; }

		.endpoint-url {
			font-family: 'Courier New', Courier, monospace;
			font-weight: 600;
			color: #1e293b;
			word-break: break-all;
		}

		.param-table thead th {
			background-color: #f8fafc;
			border-bottom: 2px solid #e2e8f0;
			color: #64748b;
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}

		.section-title {
			border-left: 5px solid var(--api-primary);
			padding-left: 15px;
			margin-bottom: 25px;
		}

		/* Test Section Styles */
		.test-section {
			background: #f8fafc;
			border-radius: 15px;
			padding: 25px;
			margin-top: 30px;
			border: 1px solid #e2e8f0;
		}
		.test-section h5 {
			font-weight: 700;
			margin-bottom: 20px;
			color: #1e293b;
			display: flex;
			align-items: center;
			gap: 10px;
		}
		.test-response {
			margin-top: 20px;
			display: none;
		}
		.test-response pre {
			margin: 0;
			font-size: 13px;
		}
		.btn-test {
			background-color: var(--api-primary);
			color: white;
			border: none;
			padding: 10px 25px;
			border-radius: 10px;
			font-weight: 600;
			transition: all 0.2s;
		}
		.btn-test:hover {
			background-color: var(--api-accent);
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
		}
		.loader {
			display: none;
			width: 20px;
			height: 20px;
			border: 3px solid #f3f3f3;
			border-top: 3px solid var(--api-primary);
			border-radius: 50%;
			animation: spin 1s linear infinite;
		}
		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		.mobile-nav-toggle {
			display: none;
			position: fixed;
			bottom: 20px;
			right: 20px;
			z-index: 999;
			background: var(--api-primary);
			color: white;
			width: 50px;
			height: 50px;
			border-radius: 50%;
			border: none;
			box-shadow: 0 4px 10px rgba(0,0,0,0.3);
			align-items: center;
			justify-content: center;
			font-size: 24px;
		}

		@media (max-width: 991px) {
			.mobile-nav-toggle {
				display: flex;
			}
			.api-docs-sidebar {
				position: fixed;
				top: 0;
				left: -100%;
				width: 280px;
				height: 100vh;
				z-index: 1000;
				transition: 0.3s;
				border-radius: 0;
				max-height: none;
			}
			.api-docs-sidebar.show {
				left: 0;
			}
			.sidebar-overlay {
				display: none;
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background: rgba(0,0,0,0.5);
				z-index: 999;
			}
			.sidebar-overlay.show {
				display: block;
			}
		}

		@media (max-width: 768px) {
			.card-api .card-body { padding: 20px; }
			.api-header { padding: 60px 0 40px; }
			.section-title { font-size: 1.5rem; }
		}
	</style>
</head>

<body data-bs-spy="scroll" data-bs-target="#apiDocsNav">
	<?php include("../func/bc-header.php"); ?>

	<div class="api-header text-center">
		<div class="container">
			<h1 class="display-4 fw-bold">Developer API Documentation</h1>
			<p class="lead opacity-75">Scale your business with our lightning-fast VTU & Bill Payment REST API.</p>
		</div>
	</div>

	<button class="mobile-nav-toggle" onclick="toggleApiNav()"><i class="bi bi-list"></i></button>
	<div class="sidebar-overlay" onclick="toggleApiNav()"></div>

	<div class="container">
		<div class="row">
			<!-- Sidebar -->
			<div class="col-lg-3">
				<div class="api-docs-sidebar" id="apiDocsNav">
					<h6 class="text-uppercase text-muted fw-bold mb-3 small">Getting Started</h6>
					<nav class="nav flex-column mb-4">
						<a class="nav-link api-link" href="#authentication">Authentication</a>
						<a class="nav-link api-link" href="#status-codes">Response Codes</a>
					</nav>

					<h6 class="text-uppercase text-muted fw-bold mb-3 small">Wallet & Accounts</h6>
					<nav class="nav flex-column mb-4">
						<a class="nav-link api-link" href="#profile">Profile & Wallet</a>
						<a class="nav-link api-link" href="#virtual-banks">Virtual Banks</a>
						<a class="nav-link api-link" href="#funding">Webhooks & Funding</a>
						<a class="nav-link api-link" href="#share-fund">Share Fund API</a>
					</nav>

					<h6 class="text-uppercase text-muted fw-bold mb-3 small">Core Services</h6>
					<nav class="nav flex-column">
						<a class="nav-link api-link" href="#data-plans">Data Plans</a>
						<a class="nav-link api-link" href="#airtime">Airtime VTU</a>
						<a class="nav-link api-link" href="#data-bundle">Data Bundle</a>
						<a class="nav-link api-link" href="#cable-tv">Cable TV</a>
						<a class="nav-link api-link" href="#electricity">Electricity</a>
						<a class="nav-link api-link" href="#exam-pins">Exam PINs</a>
						<a class="nav-link api-link" href="#bulksms">Bulk SMS</a>
						<a class="nav-link api-link" href="#betting">Betting</a>
						<a class="nav-link api-link" href="#gift-card">Gift Cards</a>
						<a class="nav-link api-link" href="#virtual-card">Virtual Cards</a>
						<a class="nav-link api-link" href="#printhub">Print Hub API</a>
						<a class="nav-link api-link" href="#bvn-verify">BVN Verification</a>
						<a class="nav-link api-link" href="#requery">Requery API</a>
						<a class="nav-link api-link" href="#ai-service">AI Assistant Service</a>
					</nav>

					<h6 class="text-uppercase text-muted fw-bold mb-3 mt-4 small">Mobile APK Support</h6>
					<nav class="nav flex-column">
						<a class="nav-link api-link" href="#mobile-app">APP Integration</a>
					</nav>
				</div>
			</div>

			<!-- Main Content -->
			<div class="col-lg-9">

				<?php if(isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && empty($get_logged_user_details['api_domain'])): ?>
				<div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4 p-4 d-flex align-items-center">
					<div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3 me-3">
						<i class="bi bi-exclamation-triangle-fill fs-3"></i>
					</div>
					<div>
						<h5 class="fw-bold mb-1">Action Required: API Whitelist Missing</h5>
						<p class="mb-2 opacity-75">Your API will cease to function unless you update your domain name in the API settings below.</p>
						<a href="#authentication" class="btn btn-danger btn-sm rounded-pill px-4 fw-bold">Update Domain Now</a>
					</div>
				</div>
				<?php endif; ?>

				<!-- Authentication -->
				<div id="authentication" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Authentication</h3>
						<p>To access our endpoints, every request must include your <code>api_key</code>. This key acts as your identity and authorization token.</p>

						<?php if (isset($get_logged_user_details)) { ?>
							<?php if ($get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) { ?>
								<div class="bg-primary bg-opacity-10 p-4 rounded-4 border border-primary border-opacity-25 mt-4">
									<div class="d-flex justify-content-between align-items-center mb-3">
										<label class="small fw-bold text-muted text-uppercase">Your Private API Key</label>
										<form method="post">
											<button name="generate-apikey" class="btn btn-link btn-sm text-danger text-decoration-none fw-bold" onclick="return confirm('Regenerate API Key? Your existing integrations will stop working.')">
												<i class="bi bi-arrow-clockwise"></i> Regenerate Key
											</button>
										</form>
									</div>
									<div class="input-group mb-3 shadow-sm">
										<input type="text" value="<?php echo $get_logged_user_details["api_key"]; ?>" class="form-control border-0 bg-white fw-bold py-3" readonly id="apiKeyInput">
										<button class="btn btn-primary px-4" onclick="copyText('API Key copied', '<?php echo $get_logged_user_details['api_key']; ?>')">
											<i class="bi bi-clipboard me-2"></i> Copy
										</button>
									</div>

									<form method="post" class="mt-4 pt-4 border-top">
										<label class="small fw-bold text-muted text-uppercase mb-2">Whitelisted Domain Name</label>
										<div class="input-group shadow-sm mb-2">
											<span class="input-group-text bg-white border-0"><i class="bi bi-globe"></i></span>
											<input type="text" name="api_domain" value="<?php echo $get_logged_user_details["api_domain"]; ?>" class="form-control border-0 bg-white py-2" placeholder="e.g. yoursite.com" required>
											<button name="update-domain" class="btn btn-dark px-4 fw-bold">Update Domain</button>
										</div>
										<p class="small text-muted mb-0">API requests will only be accepted from this domain.</p>
									</form>

									<p class="text-danger small mt-4 mb-0"><i class="bi bi-exclamation-triangle-fill"></i> Security Tip: Never share this key or push it to public repositories.</p>
								</div>
							<?php } else { ?>
								<div class="bg-light p-5 rounded-4 text-center mt-4 border">
									<i class="bi bi-lock display-4 text-primary mb-3"></i>
									<h5 class="fw-bold"><?php echo ($get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] != 1) ? 'API Access Disabled' : 'Upgrade to API Level Required'; ?></h5>
									<p class="text-muted small mb-4">Integrate our services into your own apps and websites by becoming an API Vendor.</p>
									<?php if ($get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] != 1): ?>
										<div class="alert alert-warning py-2 mb-0 rounded-pill px-4 d-inline-block fw-bold small"><i class="bi bi-info-circle me-1"></i> Contact Administrator to enable your API access.</div>
									<?php elseif ($api_request_status === 'pending'): ?>
										<button class="btn btn-secondary rounded-pill px-5 fw-bold" disabled>
											<i class="bi bi-hourglass-split me-2"></i> Request Pending Approval
										</button>
									<?php else: ?>
										<form method="post">
											<div class="row justify-content-center">
												<div class="col-md-8">
													<div class="mb-3 text-start">
														<label class="form-label small fw-bold text-muted text-uppercase">Website Domain Name</label>
														<input type="text" name="api_domain" class="form-control rounded-3" placeholder="e.g. yoursite.com" required>
													</div>
													<button name="request-api" class="btn btn-primary rounded-pill px-5 fw-bold shadow w-100">
														<i class="bi bi-rocket-takeoff me-2"></i> Request API Access
													</button>
												</div>
											</div>
										</form>
									<?php endif; ?>
								</div>
							<?php } ?>
						<?php } else { ?>
							<div class="bg-dark p-5 rounded-4 text-center mt-4">
								<h5 class="fw-bold text-white mb-3">Join our Developer Community</h5>
								<p class="text-white-50 mb-4">Login or Create an account to view your unique API credentials.</p>
								<div class="d-flex gap-3 justify-content-center">
									<a href="Login.php" class="btn btn-outline-light rounded-pill px-5">Login</a>
									<a href="Register.php" class="btn btn-primary rounded-pill px-5">Register</a>
								</div>
							</div>
						<?php } ?>
					</div>
				</div>

				<!-- Profile & Wallet -->
				<div id="profile" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Profile & Wallet</h3>
						<p>Fetch real-time user profile data including current wallet balance, KYC status, and account level.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/profile.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive mb-4">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your account authentication key.</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-profile-form" onsubmit="runTest(event, '/web/api/profile.php', 'test-profile-form', 'test-profile-res')">
								<div class="row g-3">
									<div class="col-md-10">
										<input type="text" name="api_key" class="form-control" placeholder="Paste your API Key here" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-2">
										<button type="submit" class="btn btn-test w-100">Fetch</button>
									</div>
								</div>
							</form>
							<div id="test-profile-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Virtual Banks -->
				<div id="virtual-banks" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Dedicated Virtual Banks</h3>
						<p>Fetch the list of dedicated virtual bank accounts (Monnify, Paystack, Payvessel, PayHub) assigned to your account for automated funding.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/virtual-banks.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive mb-4">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your account authentication key.</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-vb-form" onsubmit="runTest(event, '/web/api/virtual-banks.php', 'test-vb-form', 'test-vb-res')">
								<div class="row g-3">
									<div class="col-md-10">
										<input type="text" name="api_key" class="form-control" placeholder="Paste your API Key here" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-2">
										<button type="submit" class="btn btn-test w-100">Fetch Banks</button>
									</div>
								</div>
							</form>
							<div id="test-vb-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Share Fund -->
				<div id="share-fund" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Share Fund API</h3>
						<p>Transfer funds between wallets within the same vendor platform.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/share-fund.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>user</code></td><td>Yes</td><td>Recipient's username</td></tr>
									<tr><td><code>amount</code></td><td>Yes</td><td>Amount to transfer</td></tr>
									<tr><td><code>pin</code></td><td>Yes</td><td>Your 4-digit Transaction PIN</td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Webhooks & Funding -->
				<div id="funding" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Automated Funding (Webhooks)</h3>
						<p>Integrate our automated funding system via webhooks. When a payment is made through any of our supported gateways, our system sends a notification to your server or handles it automatically if you use our virtual accounts.</p>

						<div class="alert alert-warning border-0 rounded-4 mb-4">
							<h6 class="fw-bold mb-1"><i class="bi bi-shield-lock me-2"></i>Security Note</h6>
							<p class="small mb-0">Webhooks are processed using a signature verification system. Ensure you configure your <b>Secret Hash</b> or <b>Public Key</b> in the Admin Panel for each gateway.</p>
						</div>

						<h5 class="fw-bold mb-3">Webhook Endpoints</h5>
						<div class="table-responsive mb-4">
							<table class="table table-bordered align-middle">
								<thead class="bg-light">
									<tr><th>Gateway</th><th>Webhook URL</th></tr>
								</thead>
								<tbody>
									<tr>
										<td><span class="fw-bold">Monnify</span></td>
										<td><code><?php echo $web_http_host; ?>/users-monnify.php</code></td>
									</tr>
									<tr>
										<td><span class="fw-bold">Paystack</span></td>
										<td><code><?php echo $web_http_host; ?>/users-paystack.php</code></td>
									</tr>
									<tr>
										<td><span class="fw-bold">Flutterwave</span></td>
										<td><code><?php echo $web_http_host; ?>/web/api/flutterwave_webhook.php</code></td>
									</tr>
									<tr>
										<td><span class="fw-bold">Payvessel</span></td>
										<td><code><?php echo $web_http_host; ?>/users-payvessel.php</code></td>
									</tr>
									<tr>
										<td><span class="fw-bold">PayHub</span></td>
										<td><code><?php echo $web_http_host; ?>/users-payhub.php</code></td>
									</tr>
								</tbody>
							</table>
						</div>

						<h3 class="fw-bold section-title mt-5">Manual Funding Notification</h3>
						<p>Use this endpoint to notify the admin after a manual bank transfer or deposit. This will create a pending transaction for review.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/fund-manual.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>amount</code></td><td>Yes</td><td>The amount deposited.</td></tr>
									<tr><td><code>gateway</code></td><td>No</td><td>Bank name or payment method used.</td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Data Plans -->
				<div id="data-plans" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Fetch Data Plans</h3>
						<p>Use this endpoint to dynamically fetch all available networks and their specific plan codes (quantities) for your account level.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/data-plans.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive mb-4">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your account authentication key.</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-plans-form" onsubmit="runTest(event, '/web/api/data-plans.php', 'test-plans-form', 'test-plans-res')">
								<div class="row g-3">
									<div class="col-md-10">
										<input type="text" name="api_key" class="form-control" placeholder="Paste your API Key here" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-2">
										<button type="submit" class="btn btn-test w-100">Fetch</button>
									</div>
								</div>
							</form>
							<div id="test-plans-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Airtime -->
				<div id="airtime" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Airtime VTU</h3>
						<p>Seamlessly vend airtime for MTN, Airtel, Glo, and 9mobile.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/airtime.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>network</code></td><td>Yes</td><td><code>mtn</code>, <code>airtel</code>, <code>glo</code>, <code>9mobile</code></td></tr>
									<tr><td><code>amount</code></td><td>Yes</td><td>Numeric value (Minimum 100)</td></tr>
									<tr><td><code>phone_no</code></td><td>Yes</td><td>Recipient's 11-digit phone number</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-airtime-form" onsubmit="runTest(event, '/web/api/airtime.php', 'test-airtime-form', 'test-airtime-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Network</label>
										<select name="network" class="form-select">
											<option value="mtn">MTN</option>
											<option value="airtel">Airtel</option>
											<option value="glo">Glo</option>
											<option value="9mobile">9mobile</option>
										</select>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Phone Number</label>
										<input type="text" name="phone_no" class="form-control" placeholder="08012345678" required>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Amount (₦)</label>
										<input type="number" name="amount" class="form-control" placeholder="100" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Execute Purchase</button>
									</div>
								</div>
							</form>
							<div id="test-airtime-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Data Bundle -->
				<div id="data-bundle" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Data Bundle (Gifting)</h3>
						<p>Vend all types of data bundles including SME, Gifting, and Corporate Data.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/data.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>type</code></td><td>Yes</td><td><code>sme-data</code>, <code>shared-data</code>, <code>cg-data</code>, <code>dd-data</code></td></tr>
									<tr><td><code>network</code></td><td>Yes</td><td><code>mtn</code>, <code>airtel</code>, <code>glo</code>, <code>9mobile</code></td></tr>
									<tr><td><code>quantity</code></td><td>Yes</td><td>Plan Code (obtain from Fetch Data Plans API)</td></tr>
									<tr><td><code>phone_no</code></td><td>Yes</td><td>Recipient's 11-digit phone number</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-data-form" onsubmit="runTest(event, '/web/api/data.php', 'test-data-form', 'test-data-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Network</label>
										<select name="network" class="form-select">
											<option value="mtn">MTN</option>
											<option value="airtel">Airtel</option>
											<option value="glo">Glo</option>
											<option value="9mobile">9mobile</option>
										</select>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Data Type</label>
										<select name="type" class="form-select">
											<option value="sme-data">SME Data</option>
											<option value="shared-data">Shared Data</option>
											<option value="cg-data">CG Data</option>
											<option value="dd-data">Direct Data</option>
										</select>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Plan Code</label>
										<input type="text" name="quantity" class="form-control" placeholder="e.g. 1000" required>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Phone Number</label>
										<input type="text" name="phone_no" class="form-control" placeholder="08012345678" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Purchase Data</button>
									</div>
								</div>
							</form>
							<div id="test-data-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Cable TV -->
				<div id="cable-tv" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Cable TV API</h3>
						<p>Renew subscriptions for DSTV, GOTV, and Startimes.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/cable.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>type</code></td><td>Yes</td><td><code>dstv</code>, <code>gotv</code>, <code>startimes</code></td></tr>
									<tr><td><code>package</code></td><td>Yes</td><td>Package Plan Code</td></tr>
									<tr><td><code>iuc_number</code></td><td>Yes</td><td>Smartcard or IUC number</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-cable-form" onsubmit="runTest(event, '/web/api/cable.php', 'test-cable-form', 'test-cable-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Provider Type</label>
										<select name="type" class="form-select">
											<option value="dstv">DSTV</option>
											<option value="gotv">GOTV</option>
											<option value="startimes">StarTimes</option>
										</select>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">IUC/SmartCard Number</label>
										<input type="text" name="iuc_number" class="form-control" placeholder="1023456789" required>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Package Code</label>
										<input type="text" name="package" class="form-control" placeholder="e.g. gotv-max" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Execute Payment</button>
									</div>
								</div>
							</form>
							<div id="test-cable-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Electricity -->
				<div id="electricity" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Electricity API</h3>
						<p>Pay electricity bills and receive meter tokens instantly.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/electric.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>provider</code></td><td>Yes</td><td>Disco name (e.g. <code>ikedc</code>, <code>ekedc</code>, <code>aedc</code>)</td></tr>
									<tr><td><code>type</code></td><td>Yes</td><td><code>prepaid</code> or <code>postpaid</code></td></tr>
									<tr><td><code>amount</code></td><td>Yes</td><td>Minimum 1000</td></tr>
									<tr><td><code>meter_number</code></td><td>Yes</td><td>Customer's meter number</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-electric-form" onsubmit="runTest(event, '/web/api/electric.php', 'test-electric-form', 'test-electric-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Disco Provider</label>
										<select name="provider" class="form-select">
											<option value="ikedc">IKEDC</option>
											<option value="ekedc">EKEDC</option>
											<option value="aedc">AEDC</option>
											<option value="ibedc">IBEDC</option>
											<option value="jedc">JEDC</option>
										</select>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Meter Type</label>
										<select name="type" class="form-select">
											<option value="prepaid">Prepaid</option>
											<option value="postpaid">Postpaid</option>
										</select>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Meter Number</label>
										<input type="text" name="meter_number" class="form-control" placeholder="14123456789" required>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Amount (₦)</label>
										<input type="number" name="amount" class="form-control" placeholder="1000" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Pay Bill</button>
									</div>
								</div>
							</form>
							<div id="test-electric-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Exam PINs -->
				<div id="exam-pins" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Exam PIN API</h3>
						<p>Generate result checker pins for WAEC, NECO, and NABTEB.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/exam.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>type</code></td><td>Yes</td><td><code>waec</code>, <code>neco</code>, <code>nabteb</code></td></tr>
									<tr><td><code>quantity</code></td><td>Yes</td><td>Number of pins required</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-exam-form" onsubmit="runTest(event, '/web/api/exam.php', 'test-exam-form', 'test-exam-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-3">
										<label class="small fw-bold">Exam Type</label>
										<select name="type" class="form-select">
											<option value="waec">WAEC</option>
											<option value="neco">NECO</option>
											<option value="nabteb">NABTEB</option>
										</select>
									</div>
									<div class="col-md-3">
										<label class="small fw-bold">Quantity</label>
										<input type="number" name="quantity" class="form-control" value="1" min="1" max="5" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Generate PINs</button>
									</div>
								</div>
							</form>
							<div id="test-exam-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Bulk SMS -->
				<div id="bulksms" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Bulk SMS API</h3>
						<p>Send customized text messages to any Nigerian network with high delivery rates.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/sms.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>network</code></td><td>Yes</td><td>Recipient network (mtn, airtel, etc.)</td></tr>
									<tr><td><code>phone_number</code></td><td>Yes</td><td>11-digit phone number</td></tr>
									<tr><td><code>sender_id</code></td><td>Yes</td><td>Max 11 characters alphanumeric</td></tr>
									<tr><td><code>message</code></td><td>Yes</td><td>UTF-8 encoded message text</td></tr>
									<tr><td><code>type</code></td><td>Yes</td><td><code>plain</code> or <code>flash</code></td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-sms-form" onsubmit="runTest(event, '/web/api/sms.php', 'test-sms-form', 'test-sms-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Network</label>
										<select name="network" class="form-select">
											<option value="mtn">MTN</option>
											<option value="airtel">Airtel</option>
											<option value="glo">Glo</option>
											<option value="9mobile">9mobile</option>
										</select>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Sender ID</label>
										<input type="text" name="sender_id" class="form-control" placeholder="PHILMORE" maxlength="11" required>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Recipient</label>
										<input type="text" name="phone_number" class="form-control" placeholder="08012345678" required>
									</div>
									<div class="col-12">
										<label class="small fw-bold">Message</label>
										<textarea name="message" class="form-control" rows="3" placeholder="Hello from API!" required></textarea>
									</div>
									<input type="hidden" name="type" value="plain">
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Send SMS</button>
									</div>
								</div>
							</form>
							<div id="test-sms-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Betting -->
				<div id="betting" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Betting Wallet Funding</h3>
						<p>Instantly fund customer wallets for all major sports betting platforms.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/betting.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>provider</code></td><td>Yes</td><td>e.g. <code>bet9ja</code>, <code>betking</code>, <code>1xbet</code></td></tr>
									<tr><td><code>customer_id</code></td><td>Yes</td><td>Customer's User ID on the platform</td></tr>
									<tr><td><code>amount</code></td><td>Yes</td><td>Amount to fund</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-betting-form" onsubmit="runTest(event, '/web/api/betting.php', 'test-betting-form', 'test-betting-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Provider</label>
										<select name="provider" class="form-select">
											<option value="bet9ja">Bet9ja</option>
											<option value="betking">BetKing</option>
											<option value="1xbet">1xBet</option>
											<option value="sportybet">SportyBet</option>
										</select>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Customer ID</label>
										<input type="text" name="customer_id" class="form-control" placeholder="1234567" required>
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Amount (₦)</label>
										<input type="number" name="amount" class="form-control" placeholder="500" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Fund Wallet</button>
									</div>
								</div>
							</form>
							<div id="test-betting-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Gift Cards -->
				<div id="gift-card" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Gift Card API</h3>
						<p>Purchase and manage gift cards from over 2,000 brands globally.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/gift-card.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>action</code></td><td>Yes</td><td><code>list_products</code>, <code>purchase</code>, <code>my_cards</code></td></tr>
									<tr><td><code>product_id</code></td><td>No</td><td>Required for <code>purchase</code></td></tr>
									<tr><td><code>amount</code></td><td>No</td><td>Amount in USD (Required for <code>purchase</code>)</td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Virtual Cards -->
				<div id="virtual-card" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Virtual Card API (Chimoney)</h3>
						<p>Issue and fund premium USD Virtual Cards for global payments.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/virtual-card.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>action</code></td><td>Yes</td><td><code>list_cards</code>, <code>issue</code>, <code>fund</code>, <code>reveal</code></td></tr>
									<tr><td><code>product_id</code></td><td>No</td><td>Required for <code>issue</code></td></tr>
									<tr><td><code>amount_usd</code></td><td>No</td><td>Required for <code>issue</code> (Min $5) or <code>fund</code></td></tr>
									<tr><td><code>pin</code></td><td>No</td><td>Required for <code>reveal</code></td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Print Hub API -->
				<div id="printhub" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Print Hub (Universal EPIN API)</h3>
						<p>Generate printable EPINs for Data, Airtime, and other services in bulk.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/databundle-card.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key (sent via GET)</td></tr>
									<tr><td><code>network</code></td><td>Yes</td><td>Network Name (mtn, airtel, etc.)</td></tr>
									<tr><td><code>data_type</code></td><td>Yes</td><td><code>sme-data</code>, <code>cg-data</code>, <code>airtime</code>, etc.</td></tr>
									<tr><td><code>plan_code</code></td><td>Yes</td><td>The plan identifier code</td></tr>
									<tr><td><code>quantity</code></td><td>Yes</td><td>Number of pins (1-40)</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-print-form" onsubmit="runTest(event, '/web/api/databundle-card.php', 'test-print-form', 'test-print-res', true)">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Network</label>
										<select name="network" class="form-select">
											<option value="mtn">MTN</option>
											<option value="airtel">Airtel</option>
										</select>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Type</label>
										<select name="data_type" class="form-select">
											<option value="sme-data">SME Data</option>
											<option value="cg-data">CG Data</option>
										</select>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Plan Code</label>
										<input type="text" name="plan_code" class="form-control" placeholder="e.g. 1gb" required>
									</div>
									<div class="col-md-4">
										<label class="small fw-bold">Qty (1-40)</label>
										<input type="number" name="quantity" class="form-control" value="1" min="1" max="40" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Generate EPINs</button>
									</div>
								</div>
							</form>
							<div id="test-print-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- AI Assistant Service -->
				<div id="ai-service" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">AI Assistant Service</h3>
						<p>Integrate our powerful, intelligent AI directly into your own websites and applications. The AI can answer VTU queries, help with technical troubleshooting, or serve as a general assistant for your users.</p>

						<div class="alert alert-info border-0 rounded-4 mb-4">
							<h6 class="fw-bold mb-1"><i class="bi bi-wallet2 me-2"></i>Billing & Cost</h6>
							<p class="small mb-0">Unlike the Mobile App which uses a separate AI Tokens balance, this API endpoint deducts the cost directly from your <b>Main Wallet Balance</b>. The standard cost is <b>₦<?php echo (float)(isset($get_vendor_details['ai_per_tx_cost']) ? $get_vendor_details['ai_per_tx_cost'] : getSuperAdminOption('ai_price_per_request', '5')); ?></b> per successful request.</p>
						</div>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/ai-chat.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters (JSON)</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>prompt</code></td><td>Yes</td><td>The message or question you want to send to the AI.</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-ai-form" onsubmit="runTest(event, '/web/api/ai-chat.php', 'test-ai-form', 'test-ai-res')">
								<div class="row g-3">
									<div class="col-md-12">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-12">
										<label class="small fw-bold">Your Prompt</label>
										<textarea name="prompt" class="form-control" rows="3" placeholder="e.g. What is the current price for 1GB MTN SME?" required></textarea>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test w-100"><i class="bi bi-robot me-2"></i> Send to AI Engine</button>
									</div>
								</div>
							</form>
							<div id="test-ai-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Mobile APP API -->
				<div id="mobile-app" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Mobile APK Integration</h3>
						<p>Use these endpoints to build a native mobile application (Android/iOS) for your platform. These endpoints support registration, login, and profile management.</p>

						<div class="alert alert-info py-3 border-0 rounded-4 shadow-sm mb-4">
							<h6 class="fw-bold"><i class="bi bi-rocket-takeoff me-2"></i>Futuristic Mobile Architecture</h6>
							<p class="small mb-3">Our mobile API is designed for **Futuristic UI/UX** layouts. It supports glassmorphism designs, real-time shimmer animations, and biometric security.</p>
							<ul class="small mb-0">
								<li><a href="/web/api/MOBILE_APP_SPEC.md" target="_blank" class="fw-bold">Full Mobile API Spec (v2.0)</a></li>
								<li><a href="/web/api/MOBILE_APP_ANDROID_STUDIO.md" target="_blank" class="fw-bold">Android Studio Local Setup Guide</a></li>
								<li class="mt-2 text-primary"><b>New:</b> Professional Kotlin Source Code (DGV6.7-Futuristic) is now available in <code>/DG6-Android</code> for GitHub compilation.</li>
							</ul>
						</div>

						<h5 class="fw-bold mt-4 mb-3">1. Registration</h5>
						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/register.php</span>
						</div>
						<p class="small text-muted">Parameters: <code>user</code>, <code>pass</code>, <code>first</code>, <code>last</code>, <code>email</code>, <code>phone</code>, <code>address</code>.</p>

						<h5 class="fw-bold mt-4 mb-3">2. Login</h5>
						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/login.php</span>
						</div>
						<p class="small text-muted">Parameters: <code>user</code>, <code>pass</code>. Returns <code>api_key</code> and user details.</p>

						<h5 class="fw-bold mt-4 mb-3">3. Biometric Verification</h5>
						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/biometric.php?action=verify_mobile_login</span>
						</div>
						<p class="small text-muted">Used for native mobile app login. Requires <code>username</code> and <code>api_key</code>.</p>

						<h5 class="fw-bold mt-4 mb-3">4. Unified Services Fetcher</h5>
						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/services.php</span>
						</div>
						<p class="small text-muted">Parameters: <code>api_key</code>. Returns all enabled services and their corresponding plan lists for the dashboard.</p>

						<h5 class="fw-bold mt-4 mb-3">4. KYC Identity Verification</h5>
						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/kyc_submit.php</span>
						</div>
						<p class="small text-muted">Used for Face ID Liveness and Govt ID upload. Supports <code>multipart/form-data</code> for file uploads and <code>face_image_data</code> as Base64.</p>

						<h5 class="fw-bold mt-4 mb-3">5. Payment Gateway Webhooks</h5>
						<p class="small text-muted">Use these URLs in your gateway provider dashboards. They automatically credit user wallets upon successful payment notifications.</p>

						<div class="table-responsive">
							<table class="table table-sm small table-bordered">
								<thead class="bg-light">
									<tr><th>Provider</th><th>Webhook URL</th><th>Security Method</th></tr>
								</thead>
								<tbody>
									<tr>
										<td>Flutterwave</td>
										<td><code><?php echo $web_http_host; ?>/web/api/flutterwave_webhook.php</code></td>
										<td>HTTP_VERIF_HASH</td>
									</tr>
									<tr>
										<td>Paystack</td>
										<td><code><?php echo $web_http_host; ?>/users-paystack.php</code></td>
										<td>X-Paystack-Signature</td>
									</tr>
									<tr>
										<td>Monnify</td>
										<td><code><?php echo $web_http_host; ?>/users-monnify.php</code></td>
										<td>monnify-signature</td>
									</tr>
									<tr>
										<td>PayHub</td>
										<td><code><?php echo $web_http_host; ?>/users-payhub.php</code></td>
										<td>Secret Hash Verification</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- BVN Verification API -->
				<div id="bvn-verify" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">BVN Verification</h3>
						<p>Verify any Bank Verification Number (BVN) and retrieve associated identity information instantly.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-post">POST</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/bvn-verify.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>bvn</code></td><td>Yes</td><td>11-digit Bank Verification Number</td></tr>
								</tbody>
							</table>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Response</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Field</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>status</code></td><td><span class="text-success fw-bold">success</span> / <span class="text-danger fw-bold">failed</span></td></tr>
									<tr><td><code>firstname</code></td><td>First name on BVN</td></tr>
									<tr><td><code>middlename</code></td><td>Middle name on BVN</td></tr>
									<tr><td><code>lastname</code></td><td>Last name on BVN</td></tr>
									<tr><td><code>date_of_birth</code></td><td>Date of birth</td></tr>
									<tr><td><code>gender</code></td><td>Male / Female</td></tr>
									<tr><td><code>phone</code></td><td>Registered phone number</td></tr>
									<tr><td><code>bank_of_enrolment</code></td><td>Bank where BVN was registered</td></tr>
									<tr><td><code>level_of_account</code></td><td>Account level</td></tr>
									<tr><td><code>fee_charged</code></td><td>Amount deducted from wallet</td></tr>
									<tr><td><code>reference</code></td><td>Unique transaction reference</td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Requery API -->
				<div id="requery" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Requery Transaction</h3>
						<p>Verify the final status of a transaction using the reference identifier.</p>

						<div class="endpoint-box">
							<span class="badge-method badge-get">GET</span>
							<span class="endpoint-url"><?php echo $web_http_host; ?>/web/api/requery.php</span>
						</div>

						<h6 class="fw-bold small text-muted text-uppercase mb-3">Parameters</h6>
						<div class="table-responsive">
							<table class="table param-table">
								<thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><code>api_key</code></td><td>Yes</td><td>Your API Key</td></tr>
									<tr><td><code>reference</code></td><td>Yes</td><td>The <code>ref</code> returned in the initial purchase response.</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Test Section -->
						<div class="test-section">
							<h5><i class="bi bi-play-circle-fill text-primary"></i> Try it out</h5>
							<form id="test-requery-form" onsubmit="runTest(event, '/web/api/requery.php', 'test-requery-form', 'test-requery-res')">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="small fw-bold">API Key</label>
										<input type="text" name="api_key" class="form-control" required value="<?php echo (isset($get_logged_user_details) && $get_logged_user_details['account_level'] == 3 && $get_logged_user_details['api_status'] == 1) ? $get_logged_user_details['api_key'] : ''; ?>">
									</div>
									<div class="col-md-6">
										<label class="small fw-bold">Transaction Reference</label>
										<input type="text" name="reference" class="form-control" placeholder="TRX_123456789" required>
									</div>
									<div class="col-12 mt-4">
										<button type="submit" class="btn btn-test">Verify Status</button>
									</div>
								</div>
							</form>
							<div id="test-requery-res" class="test-response">
								<pre><code class="language-json"></code></pre>
							</div>
						</div>
					</div>
				</div>

				<!-- Response Codes -->
				<div id="status-codes" class="card card-api">
					<div class="card-body">
						<h3 class="fw-bold section-title">Standard Response Codes</h3>
						<p>All API responses follow this consistent structure to make integration easier.</p>

						<pre class="position-relative"><button class="copy-btn" onclick="copyText('JSON copied', this.nextElementSibling.innerText)"><i class="bi bi-clipboard"></i></button><code>{
  "status": "success",
  "ref": "TRX_87654321",
  "desc": "Transaction Successful",
  "amount": "500.00",
  "balance_after": "4500.00"
}</code></pre>

						<h6 class="fw-bold small text-muted text-uppercase mb-3 mt-4">Status Definitions</h6>
						<div class="table-responsive">
							<table class="table table-sm small">
								<thead><tr><th>Status</th><th>Description</th></tr></thead>
								<tbody>
									<tr><td><span class="text-success fw-bold">success</span></td><td>The operation completed successfully.</td></tr>
									<tr><td><span class="text-warning fw-bold">pending</span></td><td>Processing. Requery after 30 seconds.</td></tr>
									<tr><td><span class="text-danger fw-bold">failed</span></td><td>Operation failed. Check the <code>desc</code> for details.</td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

			</div>
		</div>
	</div>

	<?php include("../func/bc-footer.php"); ?>
	<script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

	<script>
		function toggleApiNav() {
			document.getElementById('apiDocsNav').classList.toggle('show');
			document.querySelector('.sidebar-overlay').classList.toggle('show');
		}

		// Close nav when clicking a link on mobile
		document.querySelectorAll('.api-link').forEach(link => {
			link.addEventListener('click', () => {
				if(window.innerWidth < 992) toggleApiNav();
			});
		});

		async function runTest(event, endpoint, formId, responseId, isPrintHub = false) {
			event.preventDefault();
			const form = document.getElementById(formId);
			const responseDiv = document.getElementById(responseId);
			const codeBlock = responseDiv.querySelector('code');
			const btn = event.submitter;
			const originalText = btn.innerText;

			btn.disabled = true;
			btn.innerHTML = '<span class="loader d-inline-block"></span> Executing...';
			responseDiv.style.display = 'block';
			codeBlock.innerText = '// Processing request...';

			try {
				const formData = new FormData(form);
				let url = endpoint;
				let options = {};

				if (isPrintHub) {
					// Print Hub uses GET for api_key and POST for others
					const apiKey = formData.get('api_key');
					formData.delete('api_key');
					url += `?api_key=${apiKey}`;
					options = {
						method: 'POST',
						body: formData // Use FormData for multipart/form-data as expected by $_POST
					};
				} else {
					// Others use JSON POST
					const data = {};
					formData.forEach((value, key) => { data[key] = value; });
					options = {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(data)
					};
				}

				const response = await fetch(url, options);
				const result = await response.json();
				codeBlock.innerText = JSON.stringify(result, null, 2);
			} catch (error) {
				codeBlock.innerText = JSON.stringify({
					status: "error",
					desc: "Local test failed: " + error.message,
					tip: "Ensure you are using a valid API Key and have sufficient balance."
				}, null, 2);
			} finally {
				btn.disabled = false;
				btn.innerText = originalText;
			}
		}

		function copyText(msg, text) {
			navigator.clipboard.writeText(text).then(() => {
				alert(msg);
			});
		}
	</script>
</body>

</html>