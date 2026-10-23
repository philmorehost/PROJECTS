<?php session_start();
	if(!isset($_SESSION["user"])){
		header("Location: /login.php");
	}else{
		include(__DIR__."/include/config.php");
		include(__DIR__."/include/user-details.php");
	}
	
	//GET USER DETAILS
	$user_session = $_SESSION["user"];
	$all_user_details = mysqli_fetch_assoc(mysqli_query($conn_server_db,"SELECT firstname, lastname, email, password, phone_number, referral, home_address, wallet_balance, account_type, commission, apikey, account_status, transaction_pin FROM users WHERE email='$user_session'"));
	
	$monnify_keys = mysqli_fetch_assoc(mysqli_query($conn_server_db,"SELECT * FROM payment_api WHERE website='monnify'"));
	$flutterwave_keys = mysqli_fetch_assoc(mysqli_query($conn_server_db,"SELECT * FROM payment_api WHERE website='flutterwave'"));
	$paystack_keys = mysqli_fetch_assoc(mysqli_query($conn_server_db,"SELECT * FROM payment_api WHERE website='paystack'"));
	
	$raw_number = "123456789012345678901234567890";
	$reference = substr(str_shuffle($raw_number),0,15);
	
?>
<!DOCTYPE html>
<html>
<head>
<title><?php echo mysqli_fetch_array(mysqli_query($conn_server_db,"SELECT * FROM site_info WHERE 1"))["sitetitle"]; ?></title>
<meta charset="utf-8" />
<meta http-equiv="Content-Type" content="text/html; " />
<meta name="theme-color" content="skyblue" />
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<link rel="stylesheet" href="/css/site.css">
<script src="/scripts/auth.js"></script>
<script type="text/javascript" src="https://sdk.monnify.com/plugin/monnify.js"></script>
<script src="https://checkout.flutterwave.com/v3.js"></script>
<script src="https://js.paystack.co/v1/inline.js"></script> 
</head>
<body>
<?php include(__DIR__."/include/header-html.php"); ?>


<center>
	<div class="container-box bg-4 mobile-width-85 system-width-65 mobile-margin-top-2 system-margin-top-5 mobile-padding-top-3 system-padding-top-3 mobile-padding-left-3 system-padding-left-3 mobile-padding-right-3 system-padding-right-3 mobile-padding-bottom-3 system-padding-bottom-3">
		<form>
			<span style="font-weight: bolder;" class="color-8 mobile-font-size-20 system-font-size-25">Pay with ATM or USSD</a></span><br>
			<select onchange="chooseGateway();" id="paymentGateway" class="select-box color-8 bg-10 mobile-font-size-12 system-font-size-14 mobile-width-97 system-width-60">
				<option selected disabled>Choose Payment Gateway</option>
				<option <?php if($monnify_keys["api_status"] == false){ echo "hidden"; } ?> value="monnify">Pay With Monnify</option>
				<option <?php if($flutterwave_keys["api_status"] == false){ echo "hidden"; } ?> value="flutterwave">Pay With Flutterwave</option>
				<option <?php if($paystack_keys["api_status"] == false){ echo "hidden"; } ?> value="paystack">Pay With Paystack</option>
			</select><br>
			<input hidden value="<?php echo $all_user_details['firstname']." ".$all_user_details['lastname']; ?>" id="fullname" type="text"/>
			<input hidden value="<?php echo $all_user_details['email']; ?>" id="email" type="email"/>
			<input hidden value="<?php echo $reference; ?>" id="ref" type="number"/>
			<input hidden value="<?php echo $all_user_details['phone_number']; ?>" id="phone" type="number" placeholder="Phone Number" required/>
			<input hidden id="public-key" type="text" placeholder="Public Key" required/>
			<input hidden id="encrypt-key" type="text" placeholder="Encrypt Key" required/>
			<input id="amount" type="number" placeholder="Amount" class="input-box mobile-width-95 system-width-58" required/><br>
			<span id="monnify-amount-div" class="color-8 mobile-font-size-12 system-font-size-16"></span>
			<span id="paystack-amount-div" class="color-8 mobile-font-size-12 system-font-size-16"></span><br>
			
			<button style="display:none;" type="button" id="monnify-btn" class="button-box color-8 bg-5 mobile-font-size-15 system-font-size-16 mobile-width-95 system-width-59" onclick="payWithMonnify();">Pay With Monnify</button>
			<button style="display:none;" type="button" id="flutterwave-btn" class="button-box color-8 bg-5 mobile-font-size-15 system-font-size-16 mobile-width-95 system-width-59" onclick="makePaymentFlutterwave();">Pay With Flutterwave</button>
			<button style="display:none;" type="button" id="paystack-btn" class="button-box color-8 bg-5 mobile-font-size-15 system-font-size-16 mobile-width-95 system-width-59" onclick="makePaymentPaystack();">Pay With Paystack</button>

		</form>
	</div>
</center>
<script>
	
	setInterval(function(){
		if(document.getElementById("paymentGateway").value == "monnify"){
			document.getElementById("monnify-amount-div").innerHTML = "Amount To Pay minus Discount: N"+Number(Number(document.getElementById("amount").value.replace("-",""))-Number(Number(document.getElementById("amount").value.replace("-",""))*1.5/100));
		}else{
			document.getElementById("monnify-amount-div").innerHTML = "";
		}
		
		if(document.getElementById("paymentGateway").value == "paystack"){
			document.getElementById("paystack-amount-div").innerHTML = "Amount To Pay minus Discount: N"+Number(Number(document.getElementById("amount").value.replace("-",""))-Number(Number(document.getElementById("amount").value.replace("-",""))*1.5/100));
		}else{
			document.getElementById("paystack-amount-div").innerHTML = "";
		}
		
	});
	
	function chooseGateway(){
		if(document.getElementById("paymentGateway").value == "monnify"){
			document.getElementById("public-key").value = '<?php echo $monnify_keys["public_key"]; ?>';
			document.getElementById("encrypt-key").value = '<?php echo $monnify_keys["encrypt_key"]; ?>';
			document.getElementById("monnify-btn").style.display = "inline-block";
		}else{
			document.getElementById("monnify-btn").style.display = "none";
		}
		
		if(document.getElementById("paymentGateway").value == "flutterwave"){
			document.getElementById("public-key").value = '<?php echo $flutterwave_keys["public_key"]; ?>';
			document.getElementById("flutterwave-btn").style.display = "inline-block";
		}else{
			document.getElementById("flutterwave-btn").style.display = "none";
		}
		
		if(document.getElementById("paymentGateway").value == "paystack"){
			document.getElementById("public-key").value = '<?php echo $paystack_keys["public_key"]; ?>';
			document.getElementById("paystack-btn").style.display = "inline-block";
		}else{
			document.getElementById("paystack-btn").style.display = "none";
		}
	}
			
			//MONNIFY CHECKOUT GATEWAY
			function payWithMonnify() {
			MonnifySDK.initialize({
			amount: Number(document.getElementById("amount").value.replace("-","").trim()),
			currency: "NGN",
			reference: Number(document.getElementById("ref").value.trim()),
			customerName: "",
			customerEmail: document.getElementById("email").value.trim(),
			apiKey: document.getElementById("public-key").value.trim(),
			contractCode: document.getElementById("encrypt-key").value,
			paymentDescription: "Wallet Funding",
			isTestMode: false,
			metadata: {
			"name": "",
			"age": "",
			},
			paymentMethods: ["CARD"],
			incomeSplitConfig: 	[],
			onComplete: function(response){
				window.location.href = "./dashboard.php";
			},
			onClose: function(data){
				window.location.href = "./dashboard.php";
			}
			});
			}
		
			//FLUTTERWAVE CHECKOUT GATEWAY
			function makePaymentFlutterwave(){
			FlutterwaveCheckout({
			public_key: document.getElementById("public-key").value.trim(),
			tx_ref: Number(document.getElementById("ref").value.trim()),
			amount: Number(document.getElementById("amount").value.replace("-","").trim()),
			currency: "NGN",
			payment_options: "card, banktransfer, ussd",
			redirect_url: "",
			meta: {
			consumer_id: "",
			consumer_mac: "",
			},
			customer: {
			email: document.getElementById("email").value.trim(),
			phone_number: document.getElementById("phone").value.trim(),
			name: document.getElementById("fullname").value.trim(),
			},
			customizations: {
			title: "",
			description: "",
			logo: "",
			},
			callback: function(payment) {
				window.location.href = "./dashboard.php";
			}
			});
			}
			
			
			//PAYSTACK CHECKOUT GATEWAY
			function makePaymentPaystack(){
			
			let handler = PaystackPop.setup({
			key: document.getElementById("public-key").value.trim(), // Replace with your public key
			email: document.getElementById("email").value.trim(),
			amount: Number(document.getElementById("amount").value.replace("-","").trim()) * 100,
			currency: 'NGN', // Use GHS for Ghana Cedis or USD for US Dollars
			ref: Number(document.getElementById("ref").value.trim()), // Replace with a reference you generated
			
			// label: "Optional string that replaces customer email"
			onClose: function() {
			alertPopUp("Transaction was not Successful, Window Closed");
			
			},
			callback: function(response){
			alertPopUp("Wallet Funded Successfully with N"+amountToFund);
			}
			});
			handler.openIframe();
			}

</script>

<?php include(__DIR__."/include/footer-html.php"); ?>
</body>
</html>