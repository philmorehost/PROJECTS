<?php session_start();
include("../func/bc-config.php");

if (isset($_GET["batch"])) {
	$batch = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["batch"])));

	if (!empty($batch)) {
		$get_cards = mysqli_query($connection_server, "SELECT * FROM sas_databundle_cards WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && user_id='" . $get_logged_user_details["id"] . "' && batch_reference='$batch'");
		if (mysqli_num_rows($get_cards) >= 1) {
			$all_cards = $get_cards;
		} else {
            $_SESSION["product_purchase_response"] = "Batch not found.";
            header("Location: DataBundleCardHistory.php");
            exit();
		}
	} else {
        $_SESSION["product_purchase_response"] = "Invalid Batch.";
        header("Location: DataBundleCardHistory.php");
        exit();
	}
}

// Fetch USSD settings and status
$get_vendor_ussd = mysqli_fetch_array(mysqli_query($connection_server, "SELECT hollatags_ussd_code, ussd_channel_mode FROM sas_vendors WHERE id='" . $get_logged_user_details["vendor_id"] . "' LIMIT 1"));
$ussd_code = $get_vendor_ussd ? $get_vendor_ussd['hollatags_ussd_code'] : '';
$ussd_channel_mode = $get_vendor_ussd ? $get_vendor_ussd['ussd_channel_mode'] : 'SMS Bridge Only';

$is_ussd_activated = false;
if (!empty($ussd_code) && $ussd_channel_mode !== 'SMS Bridge Only') {
    $check_activation = mysqli_query($connection_server, "SELECT status FROM sas_ussd_activations WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' AND user_id='" . $get_logged_user_details["id"] . "' LIMIT 1");
    if ($check_activation && mysqli_num_rows($check_activation) > 0) {
        $act_row = mysqli_fetch_assoc($check_activation);
        if ($act_row['status'] == 1 || $act_row['status'] == 'active') {
            $is_ussd_activated = true;
        }
    }
}
?>
<!DOCTYPE html>
<head>
	<title>View Data Bundle Cards | <?php echo $get_all_site_details["site_title"]; ?></title>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
	<style>
        body { background: #f4f7f6; }
        .card-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            grid-template-rows: repeat(8, 1fr);
            gap: 2px;
            width: 210mm;
            height: 297mm;
            padding: 10mm;
            margin: auto;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .bundle-card {
            border: 1px solid #ccc;
            padding: 5px;
            font-size: 10px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }
        .bundle-card img {
            max-width: 30px;
            height: auto;
        }
        .epin {
            font-weight: bold;
            font-size: 12px;
            margin: 2px 0;
        }
        .sn {
            font-size: 9px;
            color: #555;
        }
        .instruction {
            font-size: 8px;
            margin-top: 2px;
            line-height: 1;
        }
        @media print {
            body { background: none; margin: 0; padding: 0; }
            .card-grid { box-shadow: none; margin: 0; padding: 5mm; page-break-after: always; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <?php
        $first_card = mysqli_fetch_assoc($all_cards);
        mysqli_data_seek($all_cards, 0);
        $s_type = $first_card['service_type'] ?? 'data';
    ?>
    <div class="container no-print mt-3 mb-3 text-center">
        <button onclick="window.print()" class="btn btn-primary">Print Cards (A4)</button>
        <a href="DataBundleCard.php?type=<?php echo $s_type; ?>" class="btn btn-secondary">Back to Purchase</a>
    </div>

    <?php
        $count = 0;
        while($card = mysqli_fetch_assoc($all_cards)){
            if ($count % 40 == 0) {
                if ($count > 0) echo '</div>';
                echo '<div class="card-grid">';
            }
            
            $instruction_text = '';
            if ($ussd_channel_mode == 'USSD Only' && $is_ussd_activated) {
                $instruction_text = 'DIAL ' . $ussd_code . ' & ENTER PIN';
            } elseif ($ussd_channel_mode == 'Both' && $is_ussd_activated) {
                $instruction_text = 'DIAL ' . $ussd_code . ' OR SMS PIN TO ' . $card['sms_number'];
            } else {
                $instruction_text = 'SMS PIN TO ' . $card['sms_number'];
            }

            $icon_ext = ($card['service_type'] == 'data' || $card['service_type'] == 'airtime' || $card['service_type'] == 'cable') ? 'png' : 'jpg';
            $label = strtoupper($card['plan_name']);
            if ($card['validity'] > 0) $label .= ' ('.$card['validity'].' Days)';

            $brand_html = '';
            if (!empty($card['brand_name'])) {
                $brand_html = '<div style="font-size: 7.5px; font-weight: 700; border-bottom: 1px dashed #ddd; padding-bottom: 1px; margin-bottom: 2px; color: #444; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' . htmlspecialchars($card['brand_name']) . '</div>';
            }
            $display_price = !empty($card['custom_price']) ? htmlspecialchars($card['custom_price']) : $card['price'];

            echo '<div class="bundle-card">
                    ' . $brand_html . '
                    <div class="d-flex justify-content-between align-items-center">
                        <img src="../asset/'.$card['network'].'.'.$icon_ext.'">
                        <span style="font-weight: 600;">'.$label.'</span>
                    </div>
                    <div class="epin">'.$card['epin'].'</div>
                    <div class="sn">Price: ₦'.$display_price.' | S/N: '.$card['serial_number'].'</div>
                    <div class="instruction">
                        '.$instruction_text.'
                    </div>
                  </div>';
            $count++;
        }
        if ($count > 0) echo '</div>';
    ?>
</body>
</html>
