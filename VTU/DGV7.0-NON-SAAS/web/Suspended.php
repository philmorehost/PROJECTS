<?php session_start();
    include("../func/bc-connect.php");
    //Select Vendor Table
    $vendor_id = resolveVendorID();
	$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
	if($select_vendor_table){
		$get_vendor_details = $select_vendor_table;
    }else{
        $get_vendor_details = "";
    }

    if($get_vendor_details == ""){
        header("Location: /Error.php");
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Suspended</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            text-align: center;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #ff6347;
        }

        p {
            color: #333;
        }

        .contact {
            margin-top: 20px;
        }

        .contact a {
            color: #007bff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Website Suspended</h1>
        <p>We're sorry, but this website is currently suspended.</p>
        <p>Please contact the site administrator for more information.</p>
        <div class="contact">
            <p>You can contact us at <a href="mailto:<?php echo $get_vendor_details['email']; ?>"><?php echo $get_vendor_details["email"]; ?></a></p>
        </div>
    </div>
</body>
</html>