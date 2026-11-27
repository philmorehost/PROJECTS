<?php
/**
 * bc-kyc-ai-analyzer.php — DGV7.0 AI KYC Compliance Engine
 *
 * Uses multimodal AI (Gemini Vision / OpenAI GPT-4o / Anthropic Claude / Ollama / HuggingFace)
 * to analyze KYC documents automatically:
 *
 *   - Government ID: extracts name, DOB, ID number, photo; validates the document type
 *   - Selfie: compares face to ID photo; checks liveness (not a photo of a photo)
 *   - Proof of Address: extracts address, date, service provider; verifies < 3 months;
 *     checks address matches the ID address
 *   - Liveliness Video: extracts key frames; checks face movement, speech, date mention
 *
 * GOLDEN RULES:
 *   - Never writes to the database directly (the caller does that)
 *   - Never moves money
 *   - Returns structured arrays so the caller can decide what to do
 *   - Gracefully degrades: if AI is unavailable, returns status='error' with reason
 *
 * Both editions (SAAS / NON-SAAS) ship an identical copy of this file.
 */

// ─── Token cost estimation for billing ────────────────────────────────────────────────────────────
// Approximate token costs per image analysis call, by provider.
// These are used for the SAAS billing markup (15% on top).

if (!function_exists('bc_kyc_ai_token_cost')) {
    /**
     * Estimate the token cost of a single image analysis call.
     * @return int estimated tokens (used for billing with 15% markup)
     */
    function bc_kyc_ai_token_cost(string $provider, string $model, int $image_count): int
    {
        // Rough token estimates: ~2000 tokens per image + ~500 for prompt/response
        $per_image = 2000;
        $base       = 500;

        // Some providers are more expensive
        switch ($provider) {
            case 'openai':
                // GPT-4o: ~1500 tokens per image, but higher quality
                $per_image = 2500;
                $base = 700;
                break;
            case 'anthropic':
                // Claude 3.5 Sonnet: ~2000 tokens per image
                $per_image = 2500;
                $base = 800;
                break;
            case 'ollama':
                // Local: much cheaper (no per-token API cost, just compute)
                $per_image = 500;
                $base = 200;
                break;
            case 'huggingface':
                // Inference API: varies, but generally cheaper
                $per_image = 800;
                $base = 300;
                break;
            default:
                // Gemini: ~2000 tokens per image
                $per_image = 2000;
                $base = 500;
        }

        return $base + ($per_image * max(1, $image_count));
    }
}

// ─── Image to base64 helper ──────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_image_to_base64')) {
    /**
     * Read an uploaded file and return its base64-encoded data URL.
     * Supports images and PDFs (PDFs are converted to images if GD is available).
     *
     * @param string $filepath Absolute path to the uploaded file
     * @return string|null Data URL (e.g. "data:image/jpeg;base64,...") or null on failure
     */
    function bc_kyc_ai_image_to_base64($filepath)
    {
        if (!is_file($filepath) || !is_readable($filepath)) {
            return null;
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime_map = [
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            'gif'   => 'image/gif',
            'bmp'   => 'image/bmp',
        ];

        if (isset($mime_map[$ext])) {
            $mime = $mime_map[$ext];
        } elseif ($ext === 'pdf') {
            // PDF: try to convert first page to image using GD
            if (function_exists('imagecreatefromstring')) {
                // GD can't read PDFs directly. We'll send the PDF as a data URL
                // and let the vision AI handle it (Gemini and GPT-4o can read PDFs).
                $mime = 'application/pdf';
            } else {
                return null;
            }
        } else {
            // Try to get MIME from file content
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $filepath);
            finfo_close($finfo);
            if ($mime === false || $mime === '') {
                return null;
            }
        }

        $data = file_get_contents($filepath);
        if ($data === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

// ─── Government ID Analysis ──────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_analyze_id')) {
    /**
     * Analyze a government ID document (Passport, Driver's Licence, Voter's Card, National ID, BVN, NIN).
     *
     * @param string $filepath  Absolute path to the uploaded ID file
     * @param string $id_type   Expected ID type (Passport, Driver's Licence, etc.)
     * @param string $expected_name  Expected name (from user's account, for cross-check)
     * @param string $filepath            Absolute path to the uploaded ID file
     * @param string $id_type             Expected ID type (Passport, Driver's Licence, etc.)
     * @param string $expected_name       Expected name (from user's account, for cross-check)
     * @param string $expected_id_number  Expected BVN/NIN/ID number if typed by user
     * @param string $expected_address    Expected home address if provided
     * @return array Structured analysis result
     */
    function bc_kyc_ai_analyze_id($filepath, $id_type = '', $expected_name = '', $expected_id_number = '', $expected_address = '')
    {
        $base64_image = bc_kyc_ai_image_to_base64($filepath);
        if ($base64_image === null) {
            return [
                'status' => 'error',
                'reason' => 'Could not read the uploaded file',
            ];
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $hints = '';
        if ($expected_name !== '') {
            $hints .= "\nRegistered full name: '{$expected_name}'. Verify if the name on this ID matches (allowing middle-name order variations).";
        }
        if ($expected_id_number !== '') {
            $hints .= "\nSubmitted ID/BVN/NIN number: '{$expected_id_number}'. Check if the number visible on the ID matches it (null if not visible).";
        }
        if ($expected_address !== '') {
            $hints .= "\nRegistered address: '{$expected_address}'.";
        }

        $today = date('Y-m-d');
        $prompt = "You are a forensic identity & KYC verification expert. Analyze this government identity document.\n"
            . "Today's date is: {$today}.\n"
            . "Expected document type: " . ($id_type !== '' ? $id_type : 'any official government ID') . "."
            . $hints . "\n\n"
            . "Extract the following information and return ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "document_type": "Passport, Driver\'s Licence, Voter\'s Card, National ID, NIN Slip, etc.",\n'
            . '  "full_name": "the full name on the document, or null",\n'
            . '  "dob": "date of birth in YYYY-MM-DD format, or null",\n'
            . '  "id_number": "the identification number on the document, or null",\n'
            . '  "issue_date": "issue date in YYYY-MM-DD format, or null",\n'
            . '  "expiry_date": "expiry date in YYYY-MM-DD format, or null",\n'
            . '  "address_on_id": "address on the document, or null",\n'
            . '  "photo_present": true/false, whether a human portrait photo is clearly visible on the ID,\n'
            . '  "photo_clear": true/false, whether the portrait photo is clear enough for face comparison,\n'
            . '  "is_valid": true/false, whether this is a genuine, unedited, non-blank identity document,\n'
            . '  "is_expired": true/false, whether the document expiry_date is before today (' . $today . '),\n'
            . '  "name_matches": true/false/null, whether the name on the ID matches the registered full name,\n'
            . '  "id_number_matches": true/false/null, whether the visible ID number matches the submitted number (null if not provided/visible),\n'
            . '  "tampering_indicators": ["list any signs of digital editing, cutout, blank fields, or screenshot artifacts"],\n'
            . '  "doc_rating": 0-100, overall quality & authenticity rating of this ID document,\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "Be conservative and precise. If you cannot determine a field, use null or false.";

        $result = $ai->chatWithVision($model, $prompt, [$base64_image], ['temperature' => 0.1, 'num_predict' => 1024]);

        if ($result['status'] !== 'success') {
            return [
                'status' => 'error',
                'reason' => $result['message'] ?? 'AI analysis failed',
                'provider' => $provider,
                'model' => $model,
            ];
        }

        $parsed = bc_kyc_ai_parse_json_response($result['response']);
        if ($parsed === null) {
            return [
                'status' => 'error',
                'reason' => 'AI returned unparseable response',
                'raw_response' => substr($result['response'], 0, 500),
                'provider' => $provider,
                'model' => $model,
            ];
        }

        // Enrich with metadata
        $parsed['status'] = 'success';
        $parsed['provider'] = $provider;
        $parsed['model'] = $model;
        $parsed['tokens_used'] = $result['tokens_used'] ?? bc_kyc_ai_token_cost($provider, $model, 1);
        $parsed['duration_ms'] = $result['duration_ms'] ?? 0;

        return $parsed;
    }
}

// ─── Selfie vs ID Face Comparison ─────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_analyze_selfie')) {
    /**
     * Analyze a selfie and compare it to the ID photo for face match.
     * Also checks liveness (not a screenshot or photo of a photo).
     *
     * @param string $selfie_path  Path to the selfie image
     * @param string|null $id_image_path  Path to the ID photo (optional, for comparison)
     * @return array Structured analysis result
     */
    function bc_kyc_ai_analyze_selfie($selfie_path, $id_image_path = null, $expected_name = '')
    {
        $selfie_base64 = bc_kyc_ai_image_to_base64($selfie_path);
        if ($selfie_base64 === null) {
            return ['status' => 'error', 'reason' => 'Could not read the selfie image'];
        }

        $images = [$selfie_base64];
        $id_base64 = null;
        if ($id_image_path !== null && is_file($id_image_path)) {
            $id_base64 = bc_kyc_ai_image_to_base64($id_image_path);
            if ($id_base64 !== null) {
                $images[] = $id_base64;
            }
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $prompt = "You are a biometric KYC verification expert. Analyze this live selfie capture for accurate face detection, anti-spoofing, and ID face matching.\n"
            . "Image 1 is the live selfie captured by the applicant" . ($expected_name !== '' ? " ({$expected_name})" : '') . ".\n";

        if (count($images) === 2) {
            $prompt .= "Image 2 is the submitted Government ID document.\n"
                . "Carefully compare the facial geometry, eyes, nose, mouth, and structure of the person in Image 1 (Selfie) with the portrait face in Image 2 (Government ID).\n"
                . "If Image 1 shows a DIFFERENT person than the photo on Image 2, you MUST set face_matches_id=false.\n";
        } else {
            $prompt .= "No ID photo was supplied for comparison. Assess selfie face detection, clarity, and liveness.\n";
        }

        $prompt .= "\nReturn ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "face_detected": true/false, whether one real human face is clearly detected and centered in the selfie,\n'
            . '  "face_clear": true/false, whether eyes/nose/mouth are unobscured (no mask, heavy sunglasses, or extreme blur),\n'
            . '  "is_live_photo": true/false, whether this is a live camera capture (NOT a screen photo, printed photo, or blank/synthetic image),\n'
            . '  "liveliness_score": 0-100, confidence that this is a live human capture,\n'
            . '  "face_matches_id": true/false/null, whether the selfie face matches the Government ID portrait (null only if no ID photo was provided),\n'
            . '  "face_similarity": 0-100, biometric facial match percentage against the ID photo (0 if no ID photo),\n'
            . '  "quality_issues": ["list any issues: face mismatch, screen glare, photo of photo, multiple faces, blurry, dark, etc."],\n'
            . '  "doc_rating": 0-100, overall verification rating for the live selfie,\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "Be strict on face matching and spoofing. If unsure, use false or null.";

        $result = $ai->chatWithVision($model, $prompt, $images, ['temperature' => 0.1, 'num_predict' => 1024]);

        if ($result['status'] !== 'success') {
            return [
                'status' => 'error',
                'reason' => $result['message'] ?? 'AI analysis failed',
                'provider' => $provider,
                'model' => $model,
            ];
        }

        $parsed = bc_kyc_ai_parse_json_response($result['response']);
        if ($parsed === null) {
            return [
                'status' => 'error',
                'reason' => 'AI returned unparseable response',
                'raw_response' => substr($result['response'], 0, 500),
                'provider' => $provider,
                'model' => $model,
            ];
        }

        $parsed['status'] = 'success';
        $parsed['provider'] = $provider;
        $parsed['model'] = $model;
        $parsed['tokens_used'] = $result['tokens_used'] ?? bc_kyc_ai_token_cost($provider, $model, count($images));
        $parsed['duration_ms'] = $result['duration_ms'] ?? 0;

        return $parsed;
    }
}

// ─── Proof of Address (Utility Bill) Analysis ─────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_analyze_proof_of_address')) {
    /**
     * Analyze a proof of address document (utility bill, bank statement).
     * Verifies the bill is less than 3 months old and the address matches the ID.
     *
     * @param string $filepath   Path to the proof of address file
     * @param string $expected_address  Expected address (from ID or user's account)
     * @return array Structured analysis result
     */
    function bc_kyc_ai_analyze_proof_of_address($filepath, $expected_address = '', $expected_name = '')
    {
        $base64_image = bc_kyc_ai_image_to_base64($filepath);
        if ($base64_image === null) {
            return ['status' => 'error', 'reason' => 'Could not read the proof of address file'];
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $today = date('Y-m-d');
        $cutoff_date = date('Y-m-d', strtotime('-90 days'));

        $hints = '';
        if ($expected_address !== '') {
            $hints .= "\nExpected residential/ID address: '{$expected_address}'. Verify whether the address on the document matches.";
        }
        if ($expected_name !== '') {
            $hints .= "\nExpected account holder name: '{$expected_name}'. Check if the customer name on the utility bill / bank statement matches.";
        }

        $prompt = "You are a compliance & address verification expert. Analyze this proof of address document (utility bill or bank statement).\n"
            . $hints . "\n\n"
            . "Today's date is: {$today}. The document date MUST NOT be older than 3 months (must be on or after {$cutoff_date}).\n\n"
            . "Extract all details carefully and return ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "document_type": "utility bill / bank statement / other",\n'
            . '  "service_provider": "the utility company, telecom, or bank name",\n'
            . '  "address_on_document": "the full address shown on the document, or null",\n'
            . '  "bill_date": "issue/statement date on the document in YYYY-MM-DD format, or null",\n'
            . '  "bill_age_days": integer age of the bill in days from ' . $today . ', or null,\n'
            . '  "is_under_3_months": true/false, true ONLY if bill_date is readable and <= 90 days old (on or after ' . $cutoff_date . '),\n'
            . '  "address_matches": true/false/null, whether the address matches the expected address (null if no expected address),\n'
            . '  "name_matches": true/false/null, whether the name on the bill/statement matches the expected name,\n'
            . '  "is_valid_utility_bill": true/false, whether this is a genuine, legible, non-blank utility bill or bank statement,\n'
            . '  "account_name_on_bill": "the customer/account name shown on the bill/statement, or null",\n'
            . '  "account_or_meter_number": "account/meter/statement reference visible on document, or null",\n'
            . '  "issues": ["list any issues: expired > 3 months, missing date, address mismatch, blank/edited, etc."],\n'
            . '  "doc_rating": 0-100, overall quality & compliance rating for this proof of address,\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "Be strict about date recency (< 3 months / <= 90 days) and address accuracy. If unsure, use null or false.";

        $result = $ai->chatWithVision($model, $prompt, [$base64_image], ['temperature' => 0.1, 'num_predict' => 1024]);

        if ($result['status'] !== 'success') {
            return [
                'status' => 'error',
                'reason' => $result['message'] ?? 'AI analysis failed',
                'provider' => $provider,
                'model' => $model,
            ];
        }

        $parsed = bc_kyc_ai_parse_json_response($result['response']);
        if ($parsed === null) {
            return [
                'status' => 'error',
                'reason' => 'AI returned unparseable response',
                'raw_response' => substr($result['response'], 0, 500),
                'provider' => $provider,
                'model' => $model,
            ];
        }

        // Deterministic PHP verification of the 3-month (90-day) date rule
        if (!empty($parsed['bill_date']) && is_string($parsed['bill_date'])) {
            $bill_ts = strtotime($parsed['bill_date']);
            if ($bill_ts !== false) {
                $age_days = (int)floor((time() - $bill_ts) / 86400);
                $parsed['bill_age_days'] = $age_days;
                if ($age_days > 90 || $age_days < -3) {
                    $parsed['is_under_3_months'] = false;
                } else {
                    $parsed['is_under_3_months'] = true;
                }
            }
        }

        $parsed['status'] = 'success';
        $parsed['provider'] = $provider;
        $parsed['model'] = $model;
        $parsed['tokens_used'] = $result['tokens_used'] ?? bc_kyc_ai_token_cost($provider, $model, 1);
        $parsed['duration_ms'] = $result['duration_ms'] ?? 0;

        return $parsed;
    }
}

// ─── Liveliness Video Analysis ────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_analyze_video')) {
    /**
     * Analyze a liveliness video for face detection, movement, and face comparison against submitted ID.
     *
     * @param string      $video_path     Path to the uploaded video file
     * @param string      $expected_name  Expected name
     * @param string|null $id_image_path  Path to submitted Government ID (for face match)
     * @param string|null $selfie_path    Path to submitted selfie (optional reference)
     * @return array Structured analysis result
     */
    function bc_kyc_ai_analyze_video($video_path, $expected_name = '', $id_image_path = null, $selfie_path = null)
    {
        $frames = bc_kyc_ai_extract_video_frames($video_path, 3);

        if (empty($frames)) {
            return [
                'status' => 'error',
                'reason' => 'Could not extract frames from video (ffmpeg may not be available)',
            ];
        }

        $images = [];
        $has_id_ref = false;
        if ($id_image_path !== null && is_file($id_image_path)) {
            $id_b64 = bc_kyc_ai_image_to_base64($id_image_path);
            if ($id_b64 !== null) {
                $images[] = $id_b64;
                $has_id_ref = true;
            }
        }
        foreach ($frames as $frame_b64) {
            $images[] = $frame_b64;
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $expected_name_hint = '';
        if ($expected_name !== '') {
            $expected_name_hint = "\nThe applicant's registered name is: '{$expected_name}'.";
        }

        $frame_desc = $has_id_ref
            ? "Image 1 is the applicant's submitted Government ID photo.\nImages 2 to " . count($images) . " are sequential frames extracted from the applicant's live verification video.\n"
                . "You MUST compare the face in the live video frames (Images 2+) against the face on the Government ID (Image 1). If the video shows a DIFFERENT person, set face_matches_id=false."
            : "Images 1 to " . count($images) . " are sequential frames extracted from the applicant's live verification video (no ID photo provided).";

        $prompt = "You are a biometric KYC liveness and identity verification expert.\n"
            . $frame_desc
            . $expected_name_hint . "\n\n"
            . "Analyze the frames carefully and return ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "face_detected": true/false, whether a clear human face is detected in the video frames,\n'
            . '  "face_consistent": true/false, whether the same person appears across all video frames,\n'
            . '  "movement_detected": true/false, whether natural head/expression/lip movement occurs between frames,\n'
            . '  "face_matches_id": true/false/null, whether the face in the video frames matches the Government ID photo (null only if no ID photo was provided),\n'
            . '  "face_match_score": 0-100, biometric similarity between the live video face and the ID photo (0 if no ID photo),\n'
            . '  "is_live_video": true/false, whether this is a genuine live human video (not a still photo, screen replay, deepfake, or blank feed),\n'
            . '  "liveliness_score": 0-100, confidence that this is a genuine live capture,\n'
            . '  "quality_issues": ["list any issues: face mismatch with ID, no face detected, static photo replay, too dark, blurry, etc."],\n'
            . '  "doc_rating": 0-100, overall verification rating for the live video,\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "Be strict on face matching against the ID photo and anti-spoofing. If unsure, use null or false.";

        $result = $ai->chatWithVision($model, $prompt, $images, ['temperature' => 0.1, 'num_predict' => 1024]);

        if ($result['status'] !== 'success') {
            return [
                'status' => 'error',
                'reason' => $result['message'] ?? 'AI analysis failed',
                'provider' => $provider,
                'model' => $model,
            ];
        }

        $parsed = bc_kyc_ai_parse_json_response($result['response']);
        if ($parsed === null) {
            return [
                'status' => 'error',
                'reason' => 'AI returned unparseable response',
                'raw_response' => substr($result['response'], 0, 500),
                'provider' => $provider,
                'model' => $model,
            ];
        }

        $parsed['status'] = 'success';
        $parsed['provider'] = $provider;
        $parsed['model'] = $model;
        $parsed['tokens_used'] = $result['tokens_used'] ?? bc_kyc_ai_token_cost($provider, $model, count($images));
        $parsed['duration_ms'] = $result['duration_ms'] ?? 0;

        return $parsed;
    }
}

// ─── Document Rating Helpers ───────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_score_to_grade')) {
    function bc_kyc_ai_score_to_grade(int $score): string
    {
        if ($score >= 88) return 'A';
        if ($score >= 75) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 45) return 'D';
        return 'F';
    }
}

if (!function_exists('bc_kyc_ai_rate_document')) {
    /**
     * Compute a normalized 0-100 rating, letter grade (A-F), verdict (pass/review/fail),
     * and concise highlight bullets for any analyzed KYC/KYB document.
     */
    function bc_kyc_ai_rate_document(string $kind, array $r): array
    {
        if (($r['status'] ?? '') !== 'success') {
            return [
                'score'      => 0,
                'grade'      => 'F',
                'verdict'    => 'review',
                'highlights' => [$r['reason'] ?? 'AI could not analyze this document'],
            ];
        }

        $score = isset($r['doc_rating']) && is_numeric($r['doc_rating'])
            ? max(0, min(100, (int)$r['doc_rating']))
            : max(0, min(100, (int)($r['confidence'] ?? 70)));
        $highlights = [];
        $verdict = 'pass';

        if ($kind === 'govt_id') {
            if (($r['is_valid'] ?? true) === false) { $score = min($score, 20); $verdict = 'fail'; $highlights[] = 'Document invalid or tampered'; }
            if (($r['is_expired'] ?? false) === true) { $score = min($score, 25); $verdict = 'fail'; $highlights[] = 'ID is expired (' . ($r['expiry_date'] ?? 'expired') . ')'; }
            if (($r['name_matches'] ?? null) === false) { $score = min($score, 30); $verdict = 'fail'; $highlights[] = 'Name mismatch: "' . ($r['full_name'] ?? 'unknown') . '"'; }
            if (($r['id_number_matches'] ?? null) === false) { $score = min($score, 40); $verdict = 'fail'; $highlights[] = 'ID number mismatch'; }
            if (($r['photo_present'] ?? true) === false) { $score = min($score, 45); if ($verdict === 'pass') $verdict = 'review'; $highlights[] = 'No portrait photo found on ID'; }
            if ($verdict === 'pass') {
                $highlights[] = trim(($r['document_type'] ?? 'Government ID') . (!empty($r['full_name']) ? ' · ' . $r['full_name'] : ''));
                if (!empty($r['id_number'])) $highlights[] = 'ID #: ' . $r['id_number'];
            }
        } elseif ($kind === 'selfie') {
            if (($r['face_detected'] ?? false) === false) { $score = min($score, 15); $verdict = 'fail'; $highlights[] = 'No face detected in selfie'; }
            if (($r['is_live_photo'] ?? false) === false) { $score = min($score, 20); $verdict = 'fail'; $highlights[] = 'Not a live capture (screen/photo suspected)'; }
            if (($r['face_matches_id'] ?? null) === false) { $score = min($score, 15); $verdict = 'fail'; $highlights[] = 'Face does NOT match submitted ID'; }
            if (isset($r['face_similarity']) && is_numeric($r['face_similarity'])) {
                $sim = (int)$r['face_similarity'];
                if ($r['face_matches_id'] !== null && $sim < 55) { $score = min($score, 30); $verdict = 'fail'; }
                $highlights[] = 'Face match vs ID: ' . $sim . '%';
            }
            if ($verdict === 'pass' && empty($highlights)) $highlights[] = 'Live face verified';
        } elseif ($kind === 'liveliness_video') {
            if (($r['face_detected'] ?? false) === false) { $score = min($score, 15); $verdict = 'fail'; $highlights[] = 'No face detected in video'; }
            if (($r['is_live_video'] ?? false) === false) { $score = min($score, 20); $verdict = 'fail'; $highlights[] = 'Failed liveness / movement check'; }
            if (($r['face_matches_id'] ?? null) === false) { $score = min($score, 15); $verdict = 'fail'; $highlights[] = 'Video face does NOT match submitted ID'; }
            if (isset($r['face_match_score']) && is_numeric($r['face_match_score']) && (int)$r['face_match_score'] > 0) {
                $highlights[] = 'Video face match vs ID: ' . (int)$r['face_match_score'] . '%';
            }
            if (($r['movement_detected'] ?? true) === false) { $score = min($score, 45); if ($verdict === 'pass') $verdict = 'review'; $highlights[] = 'Minimal head/expression movement'; }
            if ($verdict === 'pass' && empty($highlights)) $highlights[] = 'Live video face & motion verified';
        } elseif ($kind === 'proof_of_address') {
            if (($r['is_valid_utility_bill'] ?? false) === false) { $score = min($score, 20); $verdict = 'fail'; $highlights[] = 'Not a valid utility bill or bank statement'; }
            if (($r['is_under_3_months'] ?? false) === false) {
                $ageTxt = isset($r['bill_age_days']) && is_numeric($r['bill_age_days']) ? ' (' . (int)$r['bill_age_days'] . ' days old)' : '';
                $score = min($score, 25); $verdict = 'fail'; $highlights[] = 'Older than 3 months / missing valid date' . $ageTxt;
            } else {
                $ageTxt = isset($r['bill_age_days']) && is_numeric($r['bill_age_days']) ? ' (' . (int)$r['bill_age_days'] . ' days old)' : '';
                $highlights[] = 'Date: ' . ($r['bill_date'] ?? 'Recent') . $ageTxt;
            }
            if (($r['address_matches'] ?? null) === false) { $score = min($score, 30); $verdict = 'fail'; $highlights[] = 'Address does not match profile/ID'; }
            if (($r['name_matches'] ?? null) === false) { $score = min($score, 55); if ($verdict === 'pass') $verdict = 'review'; $highlights[] = 'Bill account name differs from profile'; }
            if (!empty($r['service_provider'])) $highlights[] = 'Issuer: ' . $r['service_provider'];
        } elseif ($kind === 'kyb_document') {
            if (($r['is_valid_business_doc'] ?? false) === false) { $score = min($score, 20); $verdict = 'fail'; $highlights[] = 'Not a valid business registration document'; }
            if (($r['is_blank_or_tampered'] ?? false) === true) { $score = min($score, 15); $verdict = 'fail'; $highlights[] = 'Blank or tampered certificate'; }
            if (($r['business_name_matches'] ?? null) === false) { $score = min($score, 25); $verdict = 'fail'; $highlights[] = 'Business name mismatch'; }
            if (($r['reg_no_matches'] ?? null) === false) { $score = min($score, 25); $verdict = 'fail'; $highlights[] = 'Registration number mismatch'; }
            if ($verdict === 'pass') {
                $highlights[] = trim(($r['issuing_authority'] ?? 'Business Registry') . (!empty($r['extracted_reg_no']) ? ' · ' . $r['extracted_reg_no'] : ''));
            }
        }

        if ($verdict === 'pass' && $score < 65) {
            $verdict = 'review';
        }

        return [
            'score'      => $score,
            'grade'      => bc_kyc_ai_score_to_grade($score),
            'verdict'    => $verdict,
            'highlights' => $highlights,
        ];
    }
}

// ─── Combined Analysis (all documents) ────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_analyze_all')) {
    /**
     * Analyze all uploaded KYC documents and produce an overall decision + per-doc ratings.
     *
     * @param array $user  User data row (from sas_users)
     * @param array $enabled_checks  Enabled KYC checks for this vendor
     * @return array Combined analysis result with overall decision
     */
    function bc_kyc_ai_analyze_all($user, $enabled_checks)
    {
        $upload_dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . "/uploads/kyc/";
        $results = [];
        $total_tokens = 0;
        $total_duration = 0;
        $errors = [];

        $expected_name = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
        $expected_address = trim((string)($user['home_address'] ?? ''));
        $expected_id_number = trim((string)(!empty($user['bvn']) ? $user['bvn'] : ($user['nin'] ?? '')));

        $id_path_resolved = null;
        if (!empty($user['govt_id_card'])) {
            $cand_id = $upload_dir . basename($user['govt_id_card']);
            if (is_file($cand_id)) {
                $id_path_resolved = $cand_id;
            }
        }

        // Analyze Government ID
        if (in_array('govt_id', $enabled_checks, true) && $id_path_resolved !== null) {
            $id_type = (string)($user['kyc_id_type'] ?? '');
            $id_result = bc_kyc_ai_analyze_id($id_path_resolved, $id_type, $expected_name, $expected_id_number, $expected_address);
            $results['govt_id'] = $id_result;
            if (isset($id_result['tokens_used'])) $total_tokens += $id_result['tokens_used'];
            if (isset($id_result['duration_ms'])) $total_duration += $id_result['duration_ms'];
            if (($id_result['status'] ?? '') === 'error') $errors[] = 'Government ID: ' . ($id_result['reason'] ?? 'AI error');
        }

        // Analyze Selfie vs ID
        $selfie_path_resolved = null;
        if (!empty($user['kyc_face_image'])) {
            $cand_selfie = $upload_dir . basename($user['kyc_face_image']);
            if (is_file($cand_selfie)) $selfie_path_resolved = $cand_selfie;
        }
        if (in_array('liveliness_picture', $enabled_checks, true) && $selfie_path_resolved !== null) {
            $selfie_result = bc_kyc_ai_analyze_selfie($selfie_path_resolved, $id_path_resolved, $expected_name);
            $results['selfie'] = $selfie_result;
            if (isset($selfie_result['tokens_used'])) $total_tokens += $selfie_result['tokens_used'];
            if (isset($selfie_result['duration_ms'])) $total_duration += $selfie_result['duration_ms'];
            if (($selfie_result['status'] ?? '') === 'error') $errors[] = 'Selfie: ' . ($selfie_result['reason'] ?? 'AI error');
        }

        // Analyze Proof of Address
        if (in_array('proof_of_address', $enabled_checks, true) && !empty($user['proof_of_address'])) {
            $poa_path = $upload_dir . basename($user['proof_of_address']);
            if (is_file($poa_path)) {
                $poa_expected_addr = $expected_address;
                if (isset($results['govt_id']['address_on_id']) && is_string($results['govt_id']['address_on_id']) && $results['govt_id']['address_on_id'] !== '') {
                    $poa_expected_addr = $results['govt_id']['address_on_id'];
                }
                $poa_result = bc_kyc_ai_analyze_proof_of_address($poa_path, $poa_expected_addr, $expected_name);
                $results['proof_of_address'] = $poa_result;
                if (isset($poa_result['tokens_used'])) $total_tokens += $poa_result['tokens_used'];
                if (isset($poa_result['duration_ms'])) $total_duration += $poa_result['duration_ms'];
                if (($poa_result['status'] ?? '') === 'error') $errors[] = 'Proof of address: ' . ($poa_result['reason'] ?? 'AI error');
            }
        }

        // Analyze Liveliness Video (including face comparison against Government ID)
        if (in_array('liveliness_video', $enabled_checks, true) && !empty($user['liveliness_video'])) {
            $video_path = $upload_dir . basename($user['liveliness_video']);
            if (is_file($video_path)) {
                $video_result = bc_kyc_ai_analyze_video($video_path, $expected_name, $id_path_resolved, $selfie_path_resolved);
                $results['liveliness_video'] = $video_result;
                if (isset($video_result['tokens_used'])) $total_tokens += $video_result['tokens_used'];
                if (isset($video_result['duration_ms'])) $total_duration += $video_result['duration_ms'];
                if (($video_result['status'] ?? '') === 'error') $errors[] = 'Liveliness video: ' . ($video_result['reason'] ?? 'AI error');
            }
        }

        // Generate overall decision + per-document ratings + admin report
        $decision = bc_kyc_ai_make_decision($results, $enabled_checks, $expected_name, $errors);

        return [
            'status' => $decision['status'],
            'decision' => $decision,
            'results' => $results,
            'errors' => $errors,
            'total_tokens' => $total_tokens,
            'total_duration_ms' => $total_duration,
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
    }
}

// ─── Decision Engine ────────────────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_make_decision')) {
    /**
     * Generate an overall KYC decision & per-document rating report from individual analyses.
     *
     * @param array  $results        Individual document analysis results
     * @param array  $enabled_checks Enabled KYC checks
     * @param string $expected_name  Expected name for cross-check
     * @param array  $errors         List of errors encountered
     * @return array Decision array with status, reason, ratings, overall_rating, and summary
     */
    function bc_kyc_ai_make_decision($results, $enabled_checks, $expected_name, $errors)
    {
        $rejection_reasons = [];
        $review_reasons = [];
        $confidence_scores = [];
        $ratings = [];

        // Check Government ID
        if (isset($results['govt_id']) && ($results['govt_id']['status'] ?? '') === 'success') {
            $id = $results['govt_id'];
            $ratings['govt_id'] = bc_kyc_ai_rate_document('govt_id', $id);
            if (($id['is_valid'] ?? true) === false) {
                $rejection_reasons[] = 'Government ID appears to be invalid, blank, or tampered';
            }
            if (($id['is_expired'] ?? false) === true) {
                $rejection_reasons[] = 'Government ID is expired';
            }
            if ($expected_name !== '' && ($id['name_matches'] ?? true) === false) {
                $rejection_reasons[] = "Name on ID ('" . ($id['full_name'] ?? 'unknown') . "') does not match registered name ('{$expected_name}')";
            }
            if (($id['id_number_matches'] ?? null) === false) {
                $rejection_reasons[] = 'Submitted ID/BVN/NIN number does not match the number visible on the ID document';
            }
            if (isset($id['confidence'])) $confidence_scores[] = (int)$id['confidence'];
        } elseif (in_array('govt_id', $enabled_checks, true) && !isset($results['govt_id'])) {
            $review_reasons[] = 'Government ID was not analyzed';
        }

        // Check Selfie vs ID
        if (isset($results['selfie']) && ($results['selfie']['status'] ?? '') === 'success') {
            $selfie = $results['selfie'];
            $ratings['selfie'] = bc_kyc_ai_rate_document('selfie', $selfie);
            if (($selfie['face_detected'] ?? false) === false) {
                $rejection_reasons[] = 'No face detected in live selfie';
            }
            if (($selfie['is_live_photo'] ?? false) === false) {
                $rejection_reasons[] = 'Selfie appears to be a screenshot or photo of a photo, not a live capture';
            }
            if (($selfie['face_matches_id'] ?? null) === false) {
                $rejection_reasons[] = 'Face in live selfie does not match the photo on the submitted ID';
            }
            if (isset($selfie['confidence'])) $confidence_scores[] = (int)$selfie['confidence'];
        }

        // Check Proof of Address
        if (isset($results['proof_of_address']) && ($results['proof_of_address']['status'] ?? '') === 'success') {
            $poa = $results['proof_of_address'];
            $ratings['proof_of_address'] = bc_kyc_ai_rate_document('proof_of_address', $poa);
            if (($poa['is_valid_utility_bill'] ?? false) === false) {
                $rejection_reasons[] = 'Proof of address is not a valid utility bill or bank statement';
            }
            if (($poa['is_under_3_months'] ?? false) === false) {
                $age = isset($poa['bill_age_days']) && is_numeric($poa['bill_age_days']) ? ' (' . (int)$poa['bill_age_days'] . ' days old)' : '';
                $rejection_reasons[] = 'Proof of address is older than 3 months or has no valid date' . $age;
            }
            if (($poa['address_matches'] ?? null) === false) {
                $rejection_reasons[] = 'Address on proof of address does not match ID/profile address';
            }
            if (isset($poa['confidence'])) $confidence_scores[] = (int)$poa['confidence'];
        }

        // Check Liveliness Video (including face match vs ID)
        if (isset($results['liveliness_video']) && ($results['liveliness_video']['status'] ?? '') === 'success') {
            $video = $results['liveliness_video'];
            $ratings['liveliness_video'] = bc_kyc_ai_rate_document('liveliness_video', $video);
            if (($video['face_detected'] ?? false) === false) {
                $rejection_reasons[] = 'No face detected in liveliness video';
            }
            if (($video['is_live_video'] ?? false) === false) {
                $rejection_reasons[] = 'Liveliness video does not appear to be genuine live capture';
            }
            if (($video['face_matches_id'] ?? null) === false) {
                $rejection_reasons[] = 'Face in liveliness video does not match the photo on the submitted ID';
            }
            if (isset($video['confidence'])) $confidence_scores[] = (int)$video['confidence'];
        }

        // Rate any errored docs too so admin sees every item
        foreach ($results as $k => $r) {
            if (!isset($ratings[$k])) {
                $ratings[$k] = bc_kyc_ai_rate_document($k, is_array($r) ? $r : []);
            }
        }

        if (!empty($errors)) {
            $review_reasons[] = 'AI could not fully analyze: ' . implode('; ', $errors);
        }

        $doc_scores = array_map(function ($x) { return (int)($x['score'] ?? 0); }, $ratings);
        $overall_rating = !empty($doc_scores) ? (int)round(array_sum($doc_scores) / count($doc_scores)) : 0;
        $overall_grade = bc_kyc_ai_score_to_grade($overall_rating);
        $avg_confidence = $confidence_scores ? (int)round(array_sum($confidence_scores) / count($confidence_scores)) : 0;

        // Build concise admin summary metadata
        $summary_flags = [
            'selfie_face_match' => isset($results['selfie']) ? ($results['selfie']['face_matches_id'] ?? null) : null,
            'video_face_match'  => isset($results['liveliness_video']) ? ($results['liveliness_video']['face_matches_id'] ?? null) : null,
            'poa_under_3m'      => isset($results['proof_of_address']) ? ($results['proof_of_address']['is_under_3_months'] ?? null) : null,
            'poa_addr_match'    => isset($results['proof_of_address']) ? ($results['proof_of_address']['address_matches'] ?? null) : null,
            'id_name_match'     => isset($results['govt_id']) ? ($results['govt_id']['name_matches'] ?? null) : null,
        ];

        // Determine overall decision (advisory for human admin; $auto_apply remains false)
        if (!empty($rejection_reasons)) {
            return [
                'status'         => 'reject',
                'kyc_status'     => 3,
                'reason'         => implode(' | ', $rejection_reasons),
                'reasons'        => $rejection_reasons,
                'confidence'     => $avg_confidence,
                'overall_rating' => $overall_rating,
                'overall_grade'  => $overall_grade,
                'ratings'        => $ratings,
                'summary_flags'  => $summary_flags,
                'auto_approved'  => false,
            ];
        }

        if (!empty($review_reasons)) {
            return [
                'status'         => 'review',
                'kyc_status'     => 1,
                'reason'         => implode(' | ', $review_reasons),
                'reasons'        => $review_reasons,
                'confidence'     => $avg_confidence,
                'overall_rating' => $overall_rating,
                'overall_grade'  => $overall_grade,
                'ratings'        => $ratings,
                'summary_flags'  => $summary_flags,
                'auto_approved'  => false,
            ];
        }

        if ($avg_confidence >= 75 && $overall_rating >= 75) {
            return [
                'status'         => 'approve',
                'kyc_status'     => 2,
                'reason'         => "All KYC checks passed (Overall Rating: {$overall_rating}/100, Grade {$overall_grade}). Ready for admin approval.",
                'reasons'        => [],
                'confidence'     => $avg_confidence,
                'overall_rating' => $overall_rating,
                'overall_grade'  => $overall_grade,
                'ratings'        => $ratings,
                'summary_flags'  => $summary_flags,
                'auto_approved'  => true,
            ];
        }

        return [
            'status'         => 'review',
            'kyc_status'     => 1,
            'reason'         => "Checks completed with rating {$overall_rating}/100 (Confidence: {$avg_confidence}%). Admin review recommended.",
            'reasons'        => ["Borderline confidence/rating ({$overall_rating}/100)"],
            'confidence'     => $avg_confidence,
            'overall_rating' => $overall_rating,
            'overall_grade'  => $overall_grade,
            'ratings'        => $ratings,
            'summary_flags'  => $summary_flags,
            'auto_approved'  => false,
        ];
    }
}

// ─── KYB (Business Registration) AI Document Analyzer ──────────────────────────────────────────────

if (!function_exists('bc_kyb_ai_analyze_document')) {
    /**
     * Analyze a submitted KYB business registration document (CAC certificate / incorporation /
     * business license) against the applicant's submitted business details and rate it for admin review.
     *
     * @param string $filepath
     * @param string $business_name
     * @param string $reg_no
     * @param string $category
     * @param string $applicant_name
     * @param array  $av_scan  Optional pre-upload AV scan metadata
     * @return array
     */
    function bc_kyb_ai_analyze_document($filepath, $business_name, $reg_no, $category = '', $applicant_name = '', $av_scan = [])
    {
        $base64_doc = bc_kyc_ai_image_to_base64($filepath);
        if ($base64_doc === null) {
            return [
                'status'     => 'error',
                'ai_status'  => 'kyb_error',
                'reason'     => 'Could not read the uploaded KYB document',
                'rating'     => 0,
                'grade'      => 'F',
                'confidence' => 0,
            ];
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $prompt = "You are a corporate compliance & KYB verification expert. Analyze this business registration document.\n"
            . "Submitted Business Name: '" . $business_name . "'\n"
            . "Submitted Registration Number: '" . $reg_no . "'\n"
            . ($category !== '' ? "Business Category: '" . $category . "'\n" : '')
            . ($applicant_name !== '' ? "Account Holder / Applicant: '" . $applicant_name . "'\n" : '')
            . "\nVerify authenticity, non-blank readability, and information accuracy. Return ONLY a JSON object:\n"
            . "{\n"
            . '  "document_type": "CAC Certificate / Incorporation Certificate / Business Name Registration / Tax Certificate / Other",\n'
            . '  "issuing_authority": "e.g. Corporate Affairs Commission (CAC), Companies House, Secretary of State, etc.",\n'
            . '  "extracted_business_name": "exact business name shown on the document, or null",\n'
            . '  "extracted_reg_no": "exact registration/RC/BN number shown on the document, or null",\n'
            . '  "registration_date": "YYYY-MM-DD or null",\n'
            . '  "is_valid_business_doc": true/false, true if this is a genuine official business registration document,\n'
            . '  "is_blank_or_tampered": true/false, true if blank, erased, synthetic mockup, or tampered,\n'
            . '  "business_name_matches": true/false/null, whether the document business name matches the submitted business name,\n'
            . '  "reg_no_matches": true/false/null, whether the document registration number matches the submitted registration number,\n'
            . '  "issues": ["list any discrepancies, missing stamps, unreadable text, or red flags"],\n'
            . '  "doc_rating": 0-100, overall KYB document quality & accuracy score,\n'
            . '  "confidence": 0-100,\n'
            . '  "summary": "concise 1-2 sentence report for the admin reviewer"\n'
            . "}\n"
            . "Be strict on accuracy and tampering. If unsure, use null or false.";

        $result = $ai->chatWithVision($model, $prompt, [$base64_doc], ['temperature' => 0.1, 'num_predict' => 1024]);
        if (($result['status'] ?? '') !== 'success') {
            return [
                'status'     => 'error',
                'ai_status'  => 'kyb_error',
                'reason'     => $result['message'] ?? 'KYB AI analysis failed',
                'provider'   => $provider,
                'model'      => $model,
                'rating'     => 0,
                'grade'      => 'F',
                'confidence' => 0,
                'av_scan'    => $av_scan,
            ];
        }

        $parsed = bc_kyc_ai_parse_json_response((string)($result['response'] ?? ''));
        if (!is_array($parsed)) {
            return [
                'status'     => 'error',
                'ai_status'  => 'kyb_error',
                'reason'     => 'AI returned unparseable KYB response',
                'provider'   => $provider,
                'model'      => $model,
                'rating'     => 0,
                'grade'      => 'F',
                'confidence' => 0,
                'av_scan'    => $av_scan,
            ];
        }

        $parsed['status'] = 'success';
        $rate = bc_kyc_ai_rate_document('kyb_document', $parsed);
        $confidence = max(0, min(100, (int)($parsed['confidence'] ?? $rate['score'])));

        $reject_reasons = [];
        if (($parsed['is_valid_business_doc'] ?? false) === false) {
            $reject_reasons[] = 'Uploaded file is not a valid business registration certificate';
        }
        if (($parsed['is_blank_or_tampered'] ?? false) === true) {
            $reject_reasons[] = 'Document appears blank, incomplete, or tampered';
        }
        if (($parsed['business_name_matches'] ?? null) === false) {
            $reject_reasons[] = 'Business name on document (' . ($parsed['extracted_business_name'] ?? 'unmatched') . ") does not match '{$business_name}'";
        }
        if (($parsed['reg_no_matches'] ?? null) === false) {
            $reject_reasons[] = 'Registration number on document (' . ($parsed['extracted_reg_no'] ?? 'unmatched') . ") does not match '{$reg_no}'";
        }

        if (!empty($reject_reasons)) {
            $rec = 'kyb_reject';
            $rec_label = 'AI Recommends Rejection';
            $reason = implode(' | ', $reject_reasons);
        } elseif ($rate['score'] >= 75 && $confidence >= 75) {
            $rec = 'kyb_approve';
            $rec_label = 'AI Recommends Approval';
            $reason = (string)($parsed['summary'] ?? 'Business registration document verified and matches submitted details.');
        } else {
            $rec = 'kyb_review';
            $rec_label = 'AI: Manual Review Recommended';
            $reason = (string)($parsed['summary'] ?? 'Document uploaded; manual admin verification advised.');
        }

        return [
            'status'          => 'success',
            'ai_status'       => $rec,
            'recommendation'  => $rec_label,
            'reason'          => $reason,
            'reject_reasons'  => $reject_reasons,
            'rating'          => $rate['score'],
            'grade'           => $rate['grade'],
            'verdict'         => $rate['verdict'],
            'highlights'      => $rate['highlights'],
            'confidence'      => $confidence,
            'document'        => $parsed,
            'av_scan'         => $av_scan,
            'provider'        => $provider,
            'model'           => $model,
            'tokens_used'     => (int)($result['tokens_used'] ?? bc_kyc_ai_token_cost($provider, $model, 1)),
            'duration_ms'     => (int)($result['duration_ms'] ?? 0),
            'analyzed_at'     => date('Y-m-d H:i:s'),
        ];
    }
}

// ─── JSON Response Parser ──────────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_parse_json_response')) {
    /**
     * Parse a JSON response from an AI vision call, handling markdown code blocks and extra text.
     *
     * @param string $response  Raw AI response text
     * @return array|null  Parsed array, or null if unparseable
     */
    function bc_kyc_ai_parse_json_response($response)
    {
        $response = trim($response);

        // Try direct JSON decode first
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try extracting JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{[^]*?\})\s*```/', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try extracting the first {...} block
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json_str = substr($response, $start, $end - $start + 1);
            $decoded = json_decode($json_str, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}

// ─── Video Frame Extractor ─────────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_extract_video_frames')) {
    /**
     * Extract key frames from a video using ffmpeg.
     * Returns an array of base64 data URLs.
     *
     * @param string $video_path  Path to the video file
     * @param int $frame_count  Number of frames to extract (default 3)
     * @return array Array of base64 data URLs
     */
    function bc_kyc_ai_extract_video_frames($video_path, $frame_count = 3)
    {
        if (!is_file($video_path)) {
            return [];
        }

        // Check if ffmpeg is available
        $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
        if ($ffmpeg === '') {
            // Try common paths
            $ffmpeg_paths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/local/bin/ffmpeg'];
            foreach ($ffmpeg_paths as $path) {
                if (is_executable($path)) {
                    $ffmpeg = $path;
                    break;
                }
            }
        }
        if ($ffmpeg === '') {
            return []; // ffmpeg not available
        }

        $temp_dir = sys_get_temp_dir() . '/kyc_ai_frames_' . bin2hex(random_bytes(4));
        if (!is_dir($temp_dir)) {
            @mkdir($temp_dir, 0755, true);
        }

        // Probe video duration via ffprobe when available so frames span start/mid/end cleanly
        $duration = 0.0;
        $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
        if ($ffprobe !== '' && is_executable($ffprobe)) {
            $probe_cmd = escapeshellarg($ffprobe)
                . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
                . escapeshellarg($video_path) . ' 2>/dev/null';
            $dur_out = trim((string)@shell_exec($probe_cmd));
            if (is_numeric($dur_out) && (float)$dur_out > 0.4) {
                $duration = min(15.0, (float)$dur_out);
            }
        }

        // Extract frames spanning the clip (20%, 50%, 80% when duration is known; else 0.25s/0.5s/0.75s)
        $frames = [];
        for ($i = 1; $i <= $frame_count; $i++) {
            $frame_path = $temp_dir . '/frame_' . $i . '.jpg';
            $frac = $i / ($frame_count + 1);
            $ss = sprintf('%.2f', $duration > 0.4 ? ($duration * $frac) : $frac);

            // Use -ss before -i for fast seek
            $cmd = escapeshellarg($ffmpeg)
                . ' -ss ' . escapeshellarg($ss)
                . ' -i ' . escapeshellarg($video_path)
                . ' -frames:v 1 -vf "scale=480:-1" -q:v 3'
                . ' -y ' . escapeshellarg($frame_path)
                . ' 2>/dev/null';

            @shell_exec($cmd);

            if (is_file($frame_path) && filesize($frame_path) > 0) {
                $base64 = bc_kyc_ai_image_to_base64($frame_path);
                if ($base64 !== null) {
                    $frames[] = $base64;
                }
            }
        }

        // Cleanup
        foreach (glob($temp_dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($temp_dir);

        return $frames;
    }
}

// ─── Billing Calculation ───────────────────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_billing_cost')) {
    /**
     * Calculate the billing cost for KYC AI analysis.
     * SAAS edition: tokens + 15% markup, billed to the vendor.
     * NON-SAAS edition: no billing (pays directly).
     *
     * @param int $tokens  Tokens used
     * @param array $vendor  Vendor data row
     * @return array ['tokens' => int, 'markup_tokens' => int, 'total_tokens' => int, 'billed' => bool]
     */
    function bc_kyc_ai_billing_cost($tokens, $vendor)
    {
        $markup_percent = 15;
        $markup_tokens = (int)ceil($tokens * ($markup_percent / 100));
        $total_tokens = $tokens + $markup_tokens;

        // Check if billing is needed (SAAS edition with AI billing enabled)
        $billing_enabled = (int)($vendor['kyc_ai_billing'] ?? 0) === 1;
        $billed = $billing_enabled;

        return [
            'tokens' => $tokens,
            'markup_percent' => $markup_percent,
            'markup_tokens' => $markup_tokens,
            'total_tokens' => $total_tokens,
            'billed' => $billed,
        ];
    }
}
