<?php session_start();
    include("../func/bc-config.php");
?>
<!DOCTYPE html>
<head>
    <title>Number Filter | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link
        href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
        rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
	<?php include("../func/bc-header.php"); ?>

	<div class="pagetitle">
      <h1>Number Filter</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Number Filter</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
        <div class="card info-card px-5 py-5">
            <div class="card-body">
                <h5 class="card-title">Paste Phone Numbers</h5>
                <textarea id="phone-numbers-input" class="form-control" rows="10" placeholder="Paste phone numbers here, one per line or separated by commas."></textarea>
                <button id="filter-btn" class="btn btn-primary mt-3">Filter Numbers</button>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">MTN <span id="mtn-count" class="badge bg-secondary">0</span></h5>
                        <textarea id="mtn-numbers" class="form-control" rows="5" readonly></textarea>
                        <button class="btn btn-secondary mt-2 copy-btn" data-target="mtn-numbers">Copy</button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Glo <span id="glo-count" class="badge bg-secondary">0</span></h5>
                        <textarea id="glo-numbers" class="form-control" rows="5" readonly></textarea>
                        <button class="btn btn-secondary mt-2 copy-btn" data-target="glo-numbers">Copy</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Airtel <span id="airtel-count" class="badge bg-secondary">0</span></h5>
                        <textarea id="airtel-numbers" class="form-control" rows="5" readonly></textarea>
                        <button class="btn btn-secondary mt-2 copy-btn" data-target="airtel-numbers">Copy</button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">9mobile <span id="9mobile-count" class="badge bg-secondary">0</span></h5>
                        <textarea id="9mobile-numbers" class="form-control" rows="5" readonly></textarea>
                        <button class="btn btn-secondary mt-2 copy-btn" data-target="9mobile-numbers">Copy</button>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>

	<?php include("../func/bc-footer.php"); ?>

    <script>
        document.getElementById('filter-btn').addEventListener('click', function() {
            const mtn_prefixes = ["0803", "0702", "0703", "0704", "0707", "0903", "0806", "0706", "0813", "0810", "0814", "0816", "0906", "0916", "0913", "0903"];
            const glo_prefixes = ["0805", "0705", "0905", "0807", "0815", "0811", "0915"];
            const airtel_prefixes = ["0701", "0708", "0802", "0808", "0812", "0901", "0902", "0904", "0907", "0911", "0912"];
            const mobile_prefixes = ["0809", "0817", "0818", "0908", "0909"];

            const phoneNumbersInput = document.getElementById('phone-numbers-input').value;
            const phoneNumbers = phoneNumbersInput.split(/[\s,]+/);

            let mtnNumbers = [];
            let gloNumbers = [];
            let airtelNumbers = [];
            let mobileNumbers = [];

            phoneNumbers.forEach(function(number) {
                if (number.length >= 4) {
                    let prefix = number.substring(0, 4);
                    if (mtn_prefixes.includes(prefix)) {
                        mtnNumbers.push(number);
                    } else if (glo_prefixes.includes(prefix)) {
                        gloNumbers.push(number);
                    } else if (airtel_prefixes.includes(prefix)) {
                        airtelNumbers.push(number);
                    } else if (mobile_prefixes.includes(prefix)) {
                        mobileNumbers.push(number);
                    }
                }
            });

            document.getElementById('mtn-numbers').value = mtnNumbers.join('\n');
            document.getElementById('glo-numbers').value = gloNumbers.join('\n');
            document.getElementById('airtel-numbers').value = airtelNumbers.join('\n');
            document.getElementById('9mobile-numbers').value = mobileNumbers.join('\n');

            document.getElementById('mtn-count').textContent = mtnNumbers.length;
            document.getElementById('glo-count').textContent = gloNumbers.length;
            document.getElementById('airtel-count').textContent = airtelNumbers.length;
            document.getElementById('9mobile-count').textContent = mobileNumbers.length;
        });

        document.querySelectorAll('.copy-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const targetTextarea = document.getElementById(targetId);
                targetTextarea.select();
                document.execCommand('copy');
            });
        });
    </script>
</body>
</html>