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
$get_vendor_ussd = mysqli_fetch_array(mysqli_query($connection_server, "SELECT ussd_access, ussd_channel_mode FROM sas_vendors WHERE id='" . $get_logged_user_details["vendor_id"] . "' LIMIT 1"));
$ussd_code = getSuperAdminOption('hollatags_ussd_code', '');
$ussd_channel_mode = $get_vendor_ussd ? $get_vendor_ussd['ussd_channel_mode'] : 'SMS Only';
$is_ussd_activated = ($get_vendor_ussd && $get_vendor_ussd['ussd_access'] == 1 && getSuperAdminOption('ussd_access_enabled', '0') == '1');
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
        .brand-name {
            font-size: 9px;
            font-weight: 800;
            color: #1a202c;
            margin-top: 1px;
            border-top: 1px dashed #eee;
            padding-top: 1px;
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

            $show_price = ($card['display_price'] > 0) ? $card['display_price'] : $card['price'];
            $biz_name = !empty($card['business_name']) ? strtoupper($card['business_name']) : '';

            echo '<div class="bundle-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <img src="../asset/'.$card['network'].'.'.$icon_ext.'">
                        <span>'.$label.'</span>
                    </div>
                    <div class="epin">'.$card['epin'].'</div>
                        <div class="sn">Price: N'.number_format($show_price).' | S/N: '.$card['serial_number'].'</div>
                    <div class="instruction">
                        '.$instruction_text.'
                    </div>';
            if(!empty($biz_name)){
                echo '<div class="brand-name">'.$biz_name.'</div>';
            }
            echo '</div>';
            $count++;
        }
        if ($count > 0) echo '</div>';
    ?>
</body>
</html>