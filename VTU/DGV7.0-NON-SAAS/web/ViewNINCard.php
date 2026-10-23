<?php session_start();
include("../func/bc-config.php");

$ref = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["ref"] ?? "")));

if (empty($ref)) {
    header("Location: NINCardHistory.php");
    exit();
}

$record = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_nin_card_requests WHERE reference='$ref' AND vendor_id='".$get_logged_user_details["vendor_id"]."' AND user_id='".$get_logged_user_details["id"]."' LIMIT 1"));

if (!$record) {
    $_SESSION["product_purchase_response"] = "Record not found.";
    header("Location: NINCardHistory.php");
    exit();
}

$fullname = trim($record['firstname'] . ' ' . $record['middlename'] . ' ' . $record['lastname']);
$dob_formatted = '';
if (!empty($record['birthdate'])) {
    $ts = strtotime($record['birthdate']);
    $dob_formatted = $ts ? date('d-M-Y', $ts) : $record['birthdate'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>NIN Slip — <?php echo htmlspecialchars($fullname ?: $record['nin_input']); ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap');

        body { background: #f0f4f8; font-family: 'Roboto', Arial, Helvetica, sans-serif; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        .nin-card {
            width: 85.6mm;
            height: 53.98mm;
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            margin: 20px auto;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            background-size: 100% 100%;
            background-repeat: no-repeat;
            color: #000;
        }
        .nin-card.front { background-image: url('../asset/NIN-front.png'); background-color: #fff; }
        .nin-card.back { background-image: url('../asset/NIN-back.png'); background-color: #e8f5e9; }

        .card-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            padding: 10px 14px;
            box-sizing: border-box;
        }

        .header-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .coa-logo { width: 32px; height: auto; }
        .nimc-logo { width: 32px; height: auto; }
        .header-titles { text-align: center; line-height: 1.1; flex: 1; }
        .header-titles h1 { font-size: 8.5px; font-weight: 900; margin: 0; color: #1e4d2b; text-transform: uppercase; }
        .header-titles p { font-size: 6.5px; margin: 0; color: #333; font-weight: 700; }
        .header-titles .slip-type { font-size: 7.5px; color: #2e7d32; font-weight: 900; margin-top: 1px; letter-spacing: 0.5px; }

        .body-section { display: flex; margin-top: 6px; }
        .details-col { flex: 1; padding-right: 5px; }
        .photo-col { width: 62px; text-align: right; }

        .full-name { font-size: 10.5px; font-weight: 900; color: #000; margin-bottom: 6px; text-transform: uppercase; line-height: 1.2; }
        .info-row { font-size: 7.5px; margin-bottom: 2.5px; color: #444; font-weight: 600; display: flex; }
        .info-row .label { width: 55px; }
        .info-row .val { color: #000; font-weight: 800; text-transform: uppercase; }

        .id-photo {
            width: 60px; height: 72px;
            border-radius: 4px;
            border: 1px solid #1e4d2b;
            object-fit: cover;
            background: #fff;
        }
        .photo-placeholder {
            width: 60px; height: 72px;
            border-radius: 4px;
            border: 1px solid #ccc;
            background: #eee;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; color: #ccc;
        }

        .nin-section {
            position: absolute;
            bottom: 10px;
            left: 14px;
        }
        .nin-label { font-size: 8.5px; font-weight: 900; color: #1e4d2b; margin-bottom: -1px; }
        .nin-number { font-size: 17px; font-weight: 900; color: #1e4d2b; letter-spacing: 2.5px; font-family: 'Courier New', Courier, monospace; }

        /* Back styling - The background image has text, so we might not need much overlay unless we want to enhance it */
        .back-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            box-sizing: border-box;
            background-color: rgba(232, 245, 233, 0.4); /* Light green tint as requested */
        }

        /* Print area */
        .print-wrapper {
            max-width: 800px;
            margin: 30px auto;
            background: #fff;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .action-bar { text-align: center; margin-bottom: 30px; }

        @media print {
            body { background: none; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .print-wrapper { box-shadow: none; margin: 0; padding: 0; width: 100%; max-width: none; }
            .nin-card { box-shadow: none; margin: 5mm auto; break-inside: avoid; border: 0.2px solid #eee; }
            .nin-card.front { background-image: url('../asset/NIN-front.png') !important; }
            .nin-card.back { background-image: url('../asset/NIN-back.png') !important; }
        }
    </style>
</head>
<body>
    <div class="print-wrapper">
        <div class="action-bar no-print">
            <a href="NINCardHistory.php" class="btn btn-light border me-2 rounded-pill px-4">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <button onclick="window.print()" class="btn btn-success me-2 rounded-pill px-4">
                <i class="bi bi-printer me-1"></i> Print Slip
            </button>
            <a href="NINCard.php" class="btn btn-outline-secondary rounded-pill px-4">
                New Request
            </a>
        </div>

        <h6 class="text-center text-muted small mb-4 no-print">
            Reference: <strong><?php echo htmlspecialchars($record['reference']); ?></strong>
            &nbsp;|&nbsp; Generated: <?php echo date('d M Y, H:i', strtotime($record['date_created'])); ?>
        </h6>

        <!-- FRONT OF CARD -->
        <div class="nin-card front">
            <div class="card-overlay">
                <div class="header-section">
                    <img src="../asset/nigeria-coa.svg" class="coa-logo">
                    <div class="header-titles">
                        <h1>Federal Republic of Nigeria</h1>
                        <p>National Identity Management Commission</p>
                        <div class="slip-type">NATIONAL IDENTITY SLIP</div>
                    </div>
                    <img src="../asset/nimc-logo.png" class="nimc-logo">
                </div>

                <div class="body-section">
                    <div class="details-col">
                        <div class="full-name"><?php echo htmlspecialchars($fullname ?: '—'); ?></div>

                        <?php if (!empty($dob_formatted)): ?>
                        <div class="info-row">
                            <span class="label">Date of Birth:</span>
                            <span class="val"><?php echo htmlspecialchars($dob_formatted); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($record['gender'])): ?>
                        <div class="info-row">
                            <span class="label">Gender:</span>
                            <span class="val"><?php echo htmlspecialchars(strtoupper($record['gender'])); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <span class="label">Nationality:</span>
                            <span class="val">NIGERIAN</span>
                        </div>

                        <?php
                        $address = $record['address'] ?? '';
                        if (!empty($address) && stripos($address, 'not provided') === false):
                        ?>
                        <div class="info-row">
                            <span class="label">Address:</span>
                            <span class="val" style="font-size: 6.5px;"><?php echo htmlspecialchars(mb_substr($address, 0, 80)); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($record['phone'])): ?>
                        <div class="info-row">
                            <span class="label">Mobile No:</span>
                            <span class="val"><?php echo htmlspecialchars($record['phone']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="photo-col">
                        <?php
                        $display_photo = !empty($record['user_portrait']) ? $record['user_portrait'] : ($record['photo_data'] ?? '');
                        if (!empty($display_photo)):
                        ?>
                            <img class="id-photo"
                                 src="data:image/jpeg;base64,<?php echo htmlspecialchars($display_photo); ?>"
                                 alt="Photo">
                        <?php else: ?>
                            <div class="photo-placeholder">&#128100;</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="nin-section">
                    <div class="nin-label">NIN:</div>
                    <div class="nin-number"><?php echo chunk_split($record['nin_input'], 4, ' '); ?></div>
                </div>
            </div>
        </div>

        <!-- BACK OF CARD -->
        <div class="nin-card back">
            <div class="back-overlay">
                <!-- The text is already on the background image asset/NIN-back.png -->
            </div>
        </div>

        <div class="text-center text-muted small mt-5 no-print">
            <p><i class="bi bi-info-circle"></i> This is a premium digital NIN slip. You can print it on a PVC card for better durability.</p>
        </div>
    </div>
</body>
</html>
