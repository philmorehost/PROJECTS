<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");

if (isset($_GET["ref"])) {
	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["ref"])));

	if (!empty($reference)) {
		$get_purchased_cards = mysqli_query($connection_server, "SELECT * FROM sas_card_purchaseds WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && username='" . $get_logged_user_details["username"] . "' && reference='$reference'");
		if (mysqli_num_rows($get_purchased_cards) == 1) {
			$all_purchased_cards = $get_purchased_cards;
		} else {
			if (mysqli_num_rows($get_purchased_cards) < 1) {
				//Invalid Card ID
				$json_response_array = array("desc" => "Invalid Card ID");
				$json_response_encode = json_encode($json_response_array, true);
			} else {
				if (mysqli_num_rows($get_purchased_cards) > 1) {
					//Duplicated Details, Contact Admin
					$json_response_array = array("desc" => "Duplicated Details, Contact Admin");
					$json_response_encode = json_encode($json_response_array, true);
				}
			}
		}
	} else {
		//Reference ID Empty
		$json_response_array = array("desc" => "Reference ID Empty");
		$json_response_encode = json_encode($json_response_array, true);
	}
	$json_response_decode = json_decode($json_response_encode, true);
	$_SESSION["product_purchase_response"] = $json_response_decode["desc"];
	//header("Location: ".$_SERVER["REQUEST_URI"]);
}
?>
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Card Receipt | " . ($get_all_site_details["site_title"]));
?>
<link rel="stylesheet" href="/cssfile/bc-style.css">


	<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "CARD RECEIPT", "crumb" => "Home / Card Receipt")); ?>

	<div style="text-align: center;"
		class="bg-2 m-block-dp s-block-dp m-position-rel s-position-rel br-radius-5px m-width-94 s-width-94 m-height-auto s-height-auto m-padding-lt-1 s-padding-lt-1 m-padding-rt-1 s-padding-rt-1 m-padding-tp-5 s-padding-tp-3 m-padding-bm-1 s-padding-bm-1 m-margin-lt-2 s-margin-lt-2 m-margin-bm-2 s-margin-bm-2">
		<span style="user-select: auto;"
			class="color-4 text-bold-500 m-font-size-20 s-font-size-25 m-inline-block-dp s-inline-block-dp m-margin-bm-1 s-margin-bm-1">CARD
			LISTS</span><br>

		<div style="text-align: center;" id="printable-div-area"
			class="bg-2 m-block-dp s-block-dp m-position-rel s-position-rel m-width-100 s-width-100 m-height-auto s-height-auto">
			<?php
			if (mysqli_num_rows($all_purchased_cards) == 1) {
				while ($card_details = mysqli_fetch_assoc($all_purchased_cards)) {
					$all_exploded_cards = array_filter(explode(",", trim($card_details["cards"])));
					$all_exploded_name = array_filter(explode("_", trim($card_details["card_name"])));
					$isp_name = $all_exploded_name[0];
					$card_qty = strtoupper($all_exploded_name[1]);
					$card_type = $card_details["card_type"];
					$date_puchased = formDate($card_details["date"]);
					$get_dial_code = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_cards WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && card_name='" . $card_details["card_name"] . "' LIMIT 1"));
					$explode_dialcode = array_filter(explode(",", trim($get_dial_code["dial_code"])));
					$dial_code = $explode_dialcode[0];
					$dial_code_2 = $explode_dialcode[1];
					if (empty($card_details["business_name"])) {
						$business_name = strtoupper(explode(".", trim($_SERVER["HTTP_HOST"]))[0]);
					} else {
						$business_name = $card_details["business_name"];
					}
					if (count($all_exploded_cards) >= 1) {
						foreach ($all_exploded_cards as $each_card) {
							echo
								'<div style="text-align: center;" class="bg-3 m-inline-block-dp s-inline-block-dp m-position-rel s-position-rel br-color-5 br-style-all-4 br-width-3 m-width-95 s-width-24 m-height-auto s-height-auto m-padding-lt-1 s-padding-lt-1 m-padding-rt-1 s-padding-rt-1 m-padding-tp-1 s-padding-tp-1 m-padding-bm-1 s-padding-bm-1">
            						<div style="text-align: center;" class="bg-3 m-block-dp s-block-dp m-position-rel s-position-rel m-width-100 s-width-100 m-height-auto s-height-auto m-margin-bm-2 s-margin-bm-2">
            							<div style="text-align: left;" class="bg-3 m-inline-block-dp s-inline-block-dp m-position-rel s-position-rel m-width-70 s-width-70 m-height-auto s-height-auto">
            								<span style="user-select: auto;" class="color-4 text-bold-500 m-font-size-20 s-font-size-25 m-inline-block-dp s-inline-block-dp m-margin-tp-1 s-margin-tp-1">' . $business_name . '</span>
            							</div>
            							<div style="text-align: right;" class="bg-3 m-inline-block-dp s-inline-block-dp m-position-rel s-position-rel m-width-30 s-width-30 m-height-auto s-height-auto m-float-rt s-float-rt m-clr-float-both s-clr-float-both ">
            								<span style="user-select: auto;" class="color-4 text-bold-500 m-font-size-20 s-font-size-25 m-inline-block-dp s-inline-block-dp m-margin-tp-1 s-margin-tp-1">' . $card_qty . '</span>
            								<img src="../asset/' . $isp_name . '.png" class="m-inline-block-dp s-inline-block-dp m-width-35 s-width-35 m-margin-lt-2 s-margin-lt-2 m-float-rt s-float-rt m-clr-float-both s-clr-float-both" />
            							</div>
            						</div>
            						<div style="text-align: left;" class="bg-3 m-block-dp s-block-dp m-position-rel s-position-rel m-width-100 s-width-100 m-height-auto s-height-auto">
            							<span style="user-select: auto;" class="color-4 text-bold-400 m-font-size-14 s-font-size-18 m-inline-block-dp s-inline-block-dp m-margin-tp-1 s-margin-tp-1">
            								Ref: <span class="text-bold-500">' . $reference . '</span><br/>
            								PIN: <span class="text-bold-500">' . $each_card . '</span><br/>
            								Date: <span class="text-bold-500">' . $date_puchased . '</span><br/>
            								Dial: <span class="text-bold-500">' . $dial_code . ' then enter PIN</span><br/>
            								Check ' . ucwords($card_type) . ' Balance: <span class="text-bold-500">' . $dial_code_2 . '</span><br/>
            							</span>
            						</div>
            					</div>';
						}

						echo "<br/>" . "\n";
						echo '<span onclick="printPage();" style="user-select: auto; text-decoration: underline;" class="a-cursor m-inline-block-dp s-inline-block-dp m-position-rel s-position-rel m-font-size-14 s-font-size-16 m-margin-tp-2 s-margin-tp-2">Print Card</span>';
					} else {
						//err no card
					}
				}
			} else {
				//err invalid card id
			}
			?>
		</div>
	</div>

	<script>
		function printPage() {

			var printableDivArea = document.getElementById("printable-div-area").innerHTML;
			const html = [];
			html.push('<html><head>');
			html.push('<link rel="stylesheet" href="/cssfile/bc-style.css">
				< meta name = "author" content = "Philmore Codes" >
	<meta name="dc.creator" content="Philmore Codes">');
		html.push('</head><body onload="window.focus(); window.print()"><div>');
		html.push(printableDivArea);
		html.push('</div></body></html > ');
	
		var mywindow = window.open('', '', 'width=640,height=480');
			mywindow.document.open("text/html");
			mywindow.document.write(html.join(""));
			mywindow.document.close();

		}
	</script>

	<?php bc_app_foot(); ?>

</body>

</html>