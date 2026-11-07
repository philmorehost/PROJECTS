<?php session_start();
    include("../func/bc-admin-config.php");

    // Manual re-check for a stuck order: "Verify & Process" (pending_payment) re-verifies the
    // payment with PayHub and fulfills if genuinely paid; "Requery" (pending) asks the provider
    // for the true final status. Both use the exact same code paths the app's own status poll
    // drives — this is just a button for support/ops to force one immediately.
    if (isset($_GET['process']) && !empty(trim(strip_tags($_GET['process'])))) {
        include_once("../web/guest-api/guest-bootstrap.php");
        include_once("../web/guest-api/fulfill.php");
        $process_ref = trim(strip_tags($_GET['process']));
        $process_order = guest_get_order($process_ref, $get_logged_admin_details['id']);
        if ($process_order) {
            // Orders created before the checkout-init fix that captures PayHub's own reference
            // never got a correct payment_reference, so automatic verify can't find them on
            // PayHub's side. Let an admin paste the real reference from PayHub's own dashboard
            // (shown to the guest on PayHub's success page) to unstick exactly those.
            $payhub_ref_override = trim(strip_tags($_GET['payhub_ref'] ?? ''));
            if (!empty($payhub_ref_override)) {
                guest_update_order($process_ref, 'payment_reference', $payhub_ref_override);
            }
            if ((int)$process_order['status'] === GUEST_STATUS_PENDING_PAYMENT) {
                guest_attempt_paid_fulfillment($process_ref);
            } elseif ((int)$process_order['status'] === GUEST_STATUS_GATEWAY_PENDING) {
                // Bypass the poll throttle for an explicit admin click.
                guest_merge_extra_data($process_ref, ['last_requery_at' => 0]);
                guest_requery_pending_order(guest_get_order($process_ref));
            }
            $updated_order = guest_get_order($process_ref);
            $_SESSION['product_purchase_response'] = "Order $process_ref re-checked. Current status code: " . $updated_order['status'];
        } else {
            $_SESSION['product_purchase_response'] = "Order not found.";
        }
        header("Location: GuestOrders.php");
        exit();
    }
?>
<!DOCTYPE html>
<head>
    <title>Guest Orders | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

          <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>GUEST ORDERS (PayHub Guest App)</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Guest Orders</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">

        <?php
            // Pay-per-transaction orders from the no-login Guest App (sas_guest_orders) — these
            // never appear in sas_transactions since there is no user/wallet, so this page is
            // the only place an admin can see and trace them.
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                $page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
                $offset_statement = " OFFSET ".((20 * $page_num) - 20);
            }else{
                $offset_statement = "";
            }

            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $searchq_esc = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])));
                $search_statement = " && (reference LIKE '%$searchq_esc%' OR identity LIKE '%$searchq_esc%' OR service_type LIKE '%$searchq_esc%' OR payment_reference LIKE '%$searchq_esc%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_guest_orders = mysqli_query($connection_server, "SELECT * FROM sas_guest_orders WHERE vendor_id='".$get_logged_admin_details["id"]."' $search_statement ORDER BY created_at DESC LIMIT 20 $offset_statement");

            // Same values as web/guest-api/guest-bootstrap.php's GUEST_STATUS_* constants.
            $guest_status_labels = array(
                0 => array("Pending Payment", "secondary"),
                1 => array("Processing", "info"),
                2 => array("Successful", "success"),
                3 => array("Pending", "warning"),
                4 => array("Failed", "danger"),
            );
        ?>
        <div class="card info-card px-3 py-5 px-lg-5">
            <div class="row">
                <form method="get" action="GuestOrders.php" class="">
                    <input style="user-select: auto;" name="searchq" type="text" value="<?php echo htmlspecialchars(trim(strip_tags($_GET["searchq"] ?? ''))); ?>" placeholder="Reference, phone/IUC/meter number, service" class="form-control mt-3" />
                    <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                        <i class="bi bi-search"></i> Search
                    </button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Service</th>
                            <th>Recipient</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Description</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($get_guest_orders && mysqli_num_rows($get_guest_orders) > 0){ ?>
                            <?php while($guest_order = mysqli_fetch_assoc($get_guest_orders)){
                                $status_info = $guest_status_labels[(int)$guest_order["status"]] ?? array("Unknown", "dark");
                            ?>
                            <tr>
                                <td class="text-nowrap"><?php echo htmlspecialchars($guest_order["created_at"]); ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($guest_order["reference"]); ?></td>
                                <td><?php echo htmlspecialchars(ucwords(str_replace(array("-", "_"), " ", $guest_order["service_type"]))); ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($guest_order["identity"]); ?></td>
                                <td class="text-nowrap">&#8358;<?php echo number_format((float)$guest_order["discounted_amount"], 2); ?></td>
                                <td><span class="badge bg-<?php echo $status_info[1]; ?>"><?php echo $status_info[0]; ?></span></td>
                                <td style="max-width:280px;"><?php echo htmlspecialchars($guest_order["description"] ?? ''); ?></td>
                                <td class="text-nowrap">
                                    <?php if((int)$guest_order["status"] === 0){ ?>
                                        <a href="GuestOrders.php?process=<?php echo urlencode($guest_order["reference"]); ?>" class="btn btn-sm btn-outline-primary">Verify &amp; Process</a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                            onclick="var r=prompt('Optional: paste the reference PayHub showed the customer on its success page (only needed for orders placed before this fix). Leave blank to just re-verify normally.'); if(r!==null){ window.location='GuestOrders.php?process=<?php echo urlencode($guest_order["reference"]); ?>'+(r?('&payhub_ref='+encodeURIComponent(r)):''); }"
                                        >Verify w/ PayHub Ref…</button>
                                    <?php }elseif((int)$guest_order["status"] === 3){ ?>
                                        <a href="GuestOrders.php?process=<?php echo urlencode($guest_order["reference"]); ?>" class="btn btn-sm btn-outline-warning">Requery</a>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                        <?php }else{ ?>
                            <tr><td colspan="8" class="text-center py-4">No guest orders found.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-2 justify-content-between justify-items-center">
                <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
                <a href="GuestOrders.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Prev</button>
                </a>
                <?php } ?>
                <?php
                	if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                		$trans_next = (trim(strip_tags($_GET["page"])) +1);
                	}else{
                		$trans_next = 2;
                	}
                ?>
                <a href="GuestOrders.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Next</button>
                </a>
            </div>
        </div>
      </div>
    </section>
    <?php include("../func/bc-admin-footer.php"); ?>

</body>
</html>
