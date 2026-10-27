<?php
session_start();
include("../func/bc-config.php");

$ref = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["ref"] ?? "")));

if ($ref === '') {
    header("Location: NINCardHistory.php");
    exit();
}

$record_query = mysqli_query($connection_server, "SELECT * FROM sas_nin_card_requests WHERE reference='$ref' AND vendor_id='".(int)$get_logged_user_details["vendor_id"]."' AND user_id='".(int)$get_logged_user_details["id"]."' LIMIT 1");
$record = $record_query ? mysqli_fetch_assoc($record_query) : null;

if (!$record) {
    $_SESSION["product_purchase_response"] = "Record not found.";
    header("Location: NINCardHistory.php");
    exit();
}

$name_parts = array_filter([
    trim((string)($record['firstname'] ?? '')),
    trim((string)($record['middlename'] ?? '')),
    trim((string)($record['lastname'] ?? '')),
], function ($part) { return $part !== ''; });
$fullname = trim(implode(' ', $name_parts));
$dob_formatted = '';
if (!empty($record['birthdate'])) {
    $ts = strtotime($record['birthdate']);
    $dob_formatted = $ts ? date('d-M-Y', $ts) : (string)$record['birthdate'];
}
$nin_number = preg_replace('/\D+/', '', (string)($record['nin_input'] ?? ''));

/** Normalize the supported provider photo formats to a safe browser image source. */
function nin_card_image_src($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    $url = parse_url($value);
    if (is_array($url) && strtolower($url['scheme'] ?? '') === 'https' && !empty($url['host'])) {
        return $value;
    }

    $payload = $value;
    if (preg_match('~^data:image/(?:jpeg|jpg|png|gif|webp)(?:;[^,]*)?;base64,(.*)$~is', $value, $matches)) {
        $payload = $matches[1];
    } elseif (stripos($value, 'base64,') === 0) {
        $payload = substr($value, 7);
    }

    $payload = preg_replace('/\s+/', '', $payload);
    $payload = strtr($payload, '-_', '+/');
    if ($payload === '' || strlen($payload) > 8000000) return '';
    $padding = strlen($payload) % 4;
    if ($padding) $payload .= str_repeat('=', 4 - $padding);

    $image_bytes = base64_decode($payload, true);
    if ($image_bytes === false || $image_bytes === '') return '';

    if (strncmp($image_bytes, hex2bin('89504e470d0a1a0a'), 8) === 0) {
        $mime = 'image/png';
    } elseif (strncmp($image_bytes, hex2bin('ffd8ff'), 3) === 0) {
        $mime = 'image/jpeg';
    } elseif (strncmp($image_bytes, 'GIF8', 4) === 0) {
        $mime = 'image/gif';
    } elseif (substr($image_bytes, 0, 4) === 'RIFF' && substr($image_bytes, 8, 4) === 'WEBP') {
        $mime = 'image/webp';
    } else {
        return '';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($image_bytes);
}

// Prefer a valid user-uploaded portrait, then fall back to the selected provider's photo.
$display_photo = nin_card_image_src($record['user_portrait'] ?? '');
if ($display_photo === '') $display_photo = nin_card_image_src($record['photo_data'] ?? '');

// These high-resolution artwork files are shipped in both editions.
$front_art = '../asset/nin-premium-bg.png';
$back_art = '../asset/nin-disclaimer-back.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>NIN Slip — <?php echo htmlspecialchars($fullname ?: $nin_number, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        html, body { min-width: 0; width: 100%; margin: 0; }
        body {
            background: #f0f4f8;
            color: #17251b;
            font-family: Arial, Helvetica, sans-serif;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            overflow-x: hidden;
        }
        .print-wrapper {
            width: 100%;
            max-width: 920px;
            margin: clamp(12px, 4vh, 32px) auto;
            padding: clamp(12px, 4vw, 40px);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
        }
        .action-bar {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .reference-line { text-align: center; overflow-wrap: anywhere; }
        .nin-card {
            position: relative;
            width: 85.6mm;
            max-width: 100%;
            height: auto;
            aspect-ratio: 85.6 / 53.98;
            margin: 22px auto;
            overflow: hidden;
            border: 1px solid rgba(24, 84, 43, .28);
            border-radius: 3mm;
            background: #eaf5ed;
            box-shadow: 0 8px 24px rgba(0,0,0,.15);
            color: #102a18;
            isolation: isolate;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .card-background {
            position: absolute;
            z-index: 0;
            inset: 0;
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .card-overlay {
            position: absolute;
            z-index: 1;
            inset: 0;
            padding: 4.5% 5%;
            overflow: hidden;
        }
        .header-section {
            position: relative;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            min-height: 23%;
        }
        .header-titles {
            width: 100%;
            padding: 0 13% 0 1%;
            text-align: center;
            line-height: 1.12;
        }
        .header-titles h1 {
            margin: 0;
            color: #184c2a;
            font-size: clamp(7px, 2.4vw, 9px);
            font-weight: 900;
            text-transform: uppercase;
        }
        .header-titles p {
            margin: 1px 0 0;
            color: #243b2a;
            font-size: clamp(6px, 1.9vw, 7px);
            font-weight: 700;
        }
        .header-titles .slip-type {
            margin-top: 2px;
            color: #27683c;
            font-size: clamp(6px, 2vw, 7.5px);
            font-weight: 900;
            letter-spacing: .35px;
        }
        .nimc-logo {
            position: absolute;
            top: 0;
            right: 0;
            width: 11%;
            max-width: 30px;
            max-height: 95%;
            object-fit: contain;
        }
        .body-section {
            display: flex;
            align-items: flex-start;
            gap: 3%;
            height: 58%;
            margin-top: 1%;
        }
        .details-col { flex: 1 1 auto; min-width: 0; }
        .photo-col { flex: 0 0 21%; min-width: 0; text-align: right; }
        .full-name {
            max-height: 2.4em;
            overflow: hidden;
            margin: 0 0 4px;
            color: #0c2112;
            font-size: clamp(9px, 2.8vw, 11px);
            font-weight: 900;
            line-height: 1.15;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }
        .info-row {
            display: grid;
            grid-template-columns: minmax(48px, 27%) minmax(0, 1fr);
            gap: 3px;
            margin-bottom: 2px;
            color: #314137;
            font-size: clamp(6px, 1.9vw, 7.5px);
            font-weight: 600;
            line-height: 1.16;
        }
        .info-row .label { min-width: 0; }
        .info-row .val {
            min-width: 0;
            color: #0c2112;
            font-weight: 800;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }
        .id-photo, .photo-placeholder {
            display: block;
            width: 100%;
            max-height: 76px;
            aspect-ratio: 5 / 6;
            border: 1px solid #255a35;
            border-radius: 3px;
            object-fit: cover;
            background: #fff;
        }
        .photo-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            border-color: rgba(37,90,53,.35);
            color: rgba(37,90,53,.45);
            font-size: clamp(18px, 6vw, 28px);
        }
        .nin-section {
            position: absolute;
            bottom: 4%;
            left: 5%;
            max-width: 90%;
        }
        .nin-label {
            margin: 0 0 1px;
            color: #174d2a;
            font-size: clamp(7px, 2.2vw, 8.5px);
            font-weight: 900;
            line-height: 1;
        }
        .nin-number {
            color: #153f24;
            font-family: 'Courier New', Courier, monospace;
            font-size: clamp(12px, 4.2vw, 17px);
            font-weight: 900;
            letter-spacing: clamp(1px, .6vw, 2.3px);
            line-height: 1.05;
            white-space: nowrap;
        }
        .back-overlay { display: none; }
        .slip-note { margin: 20px 0 0; text-align: center; }

        @media (max-width: 420px) {
            .print-wrapper { border-radius: 10px; }
            .action-bar .btn { padding-left: 14px !important; padding-right: 14px !important; }
        }
        @page { size: A4 portrait; margin: 10mm; }
        @media print {
            html, body { width: auto; min-width: 0; background: #fff !important; margin: 0; padding: 0; overflow: visible; }
            .no-print { display: none !important; }
            .print-wrapper { width: auto; max-width: none; margin: 0; padding: 0; border-radius: 0; box-shadow: none; }
            .nin-card {
                width: 85.6mm !important;
                max-width: none !important;
                height: 53.98mm !important;
                aspect-ratio: auto !important;
                margin: 5mm auto;
                border: .25mm solid rgba(24,84,43,.25);
                border-radius: 2mm;
                box-shadow: none;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .card-background { display: block !important; }
            .slip-note { display: none !important; }
        }
    </style>
</head>
<body>
    <main class="print-wrapper">
        <div class="action-bar no-print">
            <a href="NINCardHistory.php" class="btn btn-light border rounded-pill px-4">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <button onclick="window.print()" class="btn btn-success rounded-pill px-4" type="button">
                <i class="bi bi-printer me-1"></i>Print Slip
            </button>
            <a href="NINCard.php" class="btn btn-outline-secondary rounded-pill px-4">New Request</a>
        </div>

        <p class="reference-line text-center text-muted small mb-3 no-print">
            Reference: <strong><?php echo htmlspecialchars((string)$record['reference'], ENT_QUOTES, 'UTF-8'); ?></strong>
            &nbsp;|&nbsp; Generated: <?php echo !empty($record['date_created']) ? htmlspecialchars(date('d M Y, H:i', strtotime($record['date_created'])), ENT_QUOTES, 'UTF-8') : '—'; ?>
        </p>

        <section class="nin-card front" aria-label="Front of NIN identity slip">
            <img class="card-background" src="<?php echo htmlspecialchars($front_art, ENT_QUOTES, 'UTF-8'); ?>" alt="" aria-hidden="true">
            <div class="card-overlay">
                <div class="header-section">
                    <div class="header-titles">
                        <h1>Federal Republic of Nigeria</h1>
                        <p>National Identity Management Commission</p>
                        <div class="slip-type">NATIONAL IDENTITY SLIP</div>
                    </div>
                    <img src="../asset/nimc-logo.png" class="nimc-logo" alt="NIMC" onerror="this.style.display='none'">
                </div>

                <div class="body-section">
                    <div class="details-col">
                        <div class="full-name"><?php echo htmlspecialchars($fullname ?: 'NAME NOT PROVIDED', ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($dob_formatted !== ''): ?>
                            <div class="info-row"><span class="label">Date of Birth</span><span class="val"><?php echo htmlspecialchars($dob_formatted, ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($record['gender'])): ?>
                            <div class="info-row"><span class="label">Gender</span><span class="val"><?php echo htmlspecialchars(strtoupper((string)$record['gender']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <?php endif; ?>
                        <div class="info-row"><span class="label">Nationality</span><span class="val">NIGERIAN</span></div>
                        <?php
                        $address = trim((string)($record['address'] ?? ''));
                        if ($address !== '' && stripos($address, 'not provided') === false):
                            $address = function_exists('mb_substr') ? mb_substr($address, 0, 72) : substr($address, 0, 72);
                        ?>
                            <div class="info-row"><span class="label">Address</span><span class="val"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($record['phone'])): ?>
                            <div class="info-row"><span class="label">Mobile No.</span><span class="val"><?php echo htmlspecialchars((string)$record['phone'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="photo-col">
                        <?php if ($display_photo !== ''): ?>
                            <img class="id-photo" src="<?php echo htmlspecialchars($display_photo, ENT_QUOTES, 'UTF-8'); ?>" alt="Identity portrait" onerror="this.replaceWith(Object.assign(document.createElement('div'), {className:'photo-placeholder', innerHTML:'&#128100;'}))">
                        <?php else: ?>
                            <div class="photo-placeholder" aria-label="No portrait available"><i class="bi bi-person-fill"></i></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="nin-section">
                    <div class="nin-label">NIN</div>
                    <div class="nin-number"><?php echo htmlspecialchars($nin_number !== '' ? trim(chunk_split($nin_number, 4, ' ')) : '—', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </section>

        <section class="nin-card back" aria-label="Back of NIN identity slip">
            <img class="card-background" src="<?php echo htmlspecialchars($back_art, ENT_QUOTES, 'UTF-8'); ?>" alt="NIN slip verification disclaimer">
        </section>

        <p class="slip-note text-center text-muted small no-print"><i class="bi bi-info-circle"></i> Print at 100% scale for PVC-card dimensions. If printing on paper, choose “Actual size” in your print settings.</p>
    </main>
</body>
</html>
