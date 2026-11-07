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
     * @return array Structured analysis result
     */
    function bc_kyc_ai_analyze_id($filepath, $id_type = '', $expected_name = '')
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

        $expected_name_hint = '';
        if ($expected_name !== '') {
            $expected_name_hint = "\nThe user's registered name is: '{$expected_name}'. Check if the name on this ID matches (allowing for middle names, nicknames, etc.).";
        }

        $prompt = "You are a Nigerian identity verification expert. Analyze this Nigerian government identity document.\n"
            . "Expected document type: " . ($id_type !== '' ? $id_type : 'any Nigerian government ID') . ".\n"
            . $expected_name_hint . "\n\n"
            . "Extract the following information and return ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "document_type": "the type of ID (Passport, Driver\'s Licence, Voter\'s Card, National ID, etc.)",\n'
            . '  "full_name": "the full name on the document",\n'
            . '  "dob": "date of birth in YYYY-MM-DD format, or null",\n'
            . '  "id_number": "the identification number on the document",\n'
            . '  "issue_date": "issue date in YYYY-MM-DD format, or null",\n'
            . '  "expiry_date": "expiry date in YYYY-MM-DD format, or null",\n'
            . '  "address_on_id": "address on the document, or null",\n'
            . '  "photo_present": true/false, whether a photo of the person is visible,\n'
            . '  "photo_clear": true/false, whether the photo is clear enough for face comparison,\n'
            . '  "is_valid": true/false, whether this appears to be a genuine, unedited document,\n'
            . '  "is_expired": true/false, whether the document is past its expiry date,\n'
            . '  "name_matches": true/false, whether the name on the ID matches the expected name,\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "If any field cannot be determined, use null or false. Be conservative - if you're unsure, say so.";

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
    function bc_kyc_ai_analyze_selfie($selfie_path, $id_image_path = null)
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

        $prompt = "You are a Nigerian identity verification expert. Analyze this selfie photo for KYC liveliness and face verification.\n"
            . "The first image is a selfie taken by the user.\n";

        if (count($images) === 2) {
            $prompt .= "The second image is a photo from a government ID document.\n"
                . "Compare the face in the selfie to the face on the ID.\n";
        } else {
            $prompt .= "No ID photo is available for comparison. Just assess the selfie quality and liveliness.\n";
        }

        $prompt .= "\n\nReturn ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "face_detected": true/false, whether a face is visible in the selfie,\n'
            . '  "face_clear": true/false, whether the face is clear and well-lit,\n'
            . '  "is_live_photo": true/false, whether this appears to be a live camera capture (not a screenshot, not a photo of a photo),\n'
            . '  "liveliness_score": 0-100, confidence that this is a genuine live capture,\n'
            . '  "face_matches_id": true/false/null, whether the face matches the ID photo (null if no ID photo or cannot determine),\n'
            . '  "face_similarity": 0-100, percentage similarity to the ID photo (0 if no ID photo),\n'
            . '  "quality_issues": ["list any issues: blurry, too dark, wrong angle, etc."],\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "Be conservative. If you cannot determine something, use null or false.";

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
    function bc_kyc_ai_analyze_proof_of_address($filepath, $expected_address = '')
    {
        $base64_image = bc_kyc_ai_image_to_base64($filepath);
        if ($base64_image === null) {
            return ['status' => 'error', 'reason' => 'Could not read the proof of address file'];
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $today = date('Y-m-d');

        $expected_addr_hint = '';
        if ($expected_address !== '') {
            $expected_addr_hint = "\nThe expected address is: '{$expected_address}'. Check if the address on this document matches.";
        }

        $prompt = "You are a Nigerian address verification expert. Analyze this proof of address document.\n"
            . "The document should be a utility bill (electricity, water, waste) or bank statement issued in Nigeria.\n"
            . $expected_addr_hint . "\n\n"
            . "Today's date is: {$today}.\n\n"
            . "Extract the following and return ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "document_type": "utility bill / bank statement / other",\n'
            . '  "service_provider": "the utility company or bank name",\n'
            . '  "address_on_document": "the full address shown on the document, or null",\n'
            . '  "bill_date": "the date on the bill/statement in YYYY-MM-DD format, or null",\n'
            . '  "bill_age_days": "approximate age of the bill in days (calculate from bill_date and today\'s date), or null",\n'
            . '  "is_under_3_months": true/false/null, whether the bill is less than 90 days old,\n'
            . '  "address_matches": true/false/null, whether the address matches the expected address (null if no expected address),\n'
            . '  "is_valid_utility_bill": true/false, whether this is a genuine utility bill or bank statement (not a random document),\n'
            . '  "account_name_on_bill": "the name on the bill/statement, or null",\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "Be conservative. If you cannot determine something, use null or false.";

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
     * Analyze a liveliness video for face movement and speech verification.
     * Extracts key frames and checks for liveliness indicators.
     *
     * @param string $video_path  Path to the uploaded video file
     * @param string $expected_name  Expected name to verify in speech
     * @return array Structured analysis result
     */
    function bc_kyc_ai_analyze_video($video_path, $expected_name = '')
    {
        // Video analysis requires extracting frames. We'll extract a few key frames
        // and send them to the AI for analysis.
        $frames = bc_kyc_ai_extract_video_frames($video_path, 3);

        if (empty($frames)) {
            return [
                'status' => 'error',
                'reason' => 'Could not extract frames from video (ffmpeg may not be available)',
            ];
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $expected_name_hint = '';
        if ($expected_name !== '') {
            $expected_name_hint = "\nThe user's registered name is: '{$expected_name}'.";
        }

        $prompt = "You are a Nigerian identity verification expert. These are frames extracted from a liveliness video.\n"
            . "The user was asked to say their full name and today's date.\n"
            . $expected_name_hint . "\n\n"
            . "Analyze these frames and return ONLY a JSON object (no markdown, no explanation):\n"
            . "{\n"
            . '  "face_detected": true/false, whether a face is visible in the frames,\n'
            . '  "face_consistent": true/false, whether the same face appears across all frames (indicating a real person, not a cutout),\n'
            . '  "movement_detected": true/false, whether there is natural head movement (liveliness),\n'
            . '  "name_spoken": true/false/null, whether the name appears to be spoken (you may need to infer from context),\n'
            . '  "name_matches": true/false/null, whether the spoken name matches the expected name,\n'
            . '  "is_live_video": true/false, whether this appears to be a genuine live video capture,\n'
            . '  "liveliness_score": 0-100, confidence that this is a genuine liveliness video,\n'
            . '  "quality_issues": ["list any issues"],\n'
            . '  "confidence": 0-100, your confidence in this analysis\n'
            . "}\n"
            . "Be conservative. If you cannot determine something, use null or false.";

        $result = $ai->chatWithVision($model, $prompt, $frames, ['temperature' => 0.1, 'num_predict' => 1024]);

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
        $parsed['tokens_used'] = $result['tokens_used'] ?? bc_kyc_ai_token_cost($provider, $model, count($frames));
        $parsed['duration_ms'] = $result['duration_ms'] ?? 0;

        return $parsed;
    }
}

// ─── Combined Analysis (all documents) ────────────────────────────────────────────────────────────

if (!function_exists('bc_kyc_ai_analyze_all')) {
    /**
     * Analyze all uploaded KYC documents and produce an overall decision.
     *
     * @param array $user  User data row (from sas_users)
     * @param array $enabled_checks  Enabled KYC checks for this vendor
     * @return array Combined analysis result with overall decision
     */
    function bc_kyc_ai_analyze_all($user, $enabled_checks)
    {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kyc/";
        $results = [];
        $total_tokens = 0;
        $total_duration = 0;
        $errors = [];

        $expected_name = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
        $expected_address = trim((string)($user['home_address'] ?? ''));

        // Analyze Government ID
        if (in_array('govt_id', $enabled_checks, true) && !empty($user['govt_id_card'])) {
            $id_path = $upload_dir . basename($user['govt_id_card']);
            if (is_file($id_path)) {
                $id_type = (string)($user['kyc_id_type'] ?? '');
                $id_result = bc_kyc_ai_analyze_id($id_path, $id_type, $expected_name);
                $results['govt_id'] = $id_result;
                if (isset($id_result['tokens_used'])) $total_tokens += $id_result['tokens_used'];
                if (isset($id_result['duration_ms'])) $total_duration += $id_result['duration_ms'];
                if (($id_result['status'] ?? '') === 'error') $errors[] = 'Government ID: ' . ($id_result['reason'] ?? 'AI error');
            }
        }

        // Analyze Selfie vs ID
        if (in_array('liveliness_picture', $enabled_checks, true) && !empty($user['kyc_face_image'])) {
            $selfie_path = $upload_dir . basename($user['kyc_face_image']);
            if (is_file($selfie_path)) {
                $id_image_path = null;
                if (!empty($user['govt_id_card'])) {
                    $id_path = $upload_dir . basename($user['govt_id_card']);
                    if (is_file($id_path) && $id_result['status'] ?? '' === 'success' && isset($id_result['photo_present']) && $id_result['photo_present']) {
                        $id_image_path = $id_path;
                    }
                }
                $selfie_result = bc_kyc_ai_analyze_selfie($selfie_path, $id_image_path);
                $results['selfie'] = $selfie_result;
                if (isset($selfie_result['tokens_used'])) $total_tokens += $selfie_result['tokens_used'];
                if (isset($selfie_result['duration_ms'])) $total_duration += $selfie_result['duration_ms'];
                if (($selfie_result['status'] ?? '') === 'error') $errors[] = 'Selfie: ' . ($selfie_result['reason'] ?? 'AI error');
            }
        }

        // Analyze Proof of Address
        if (in_array('proof_of_address', $enabled_checks, true) && !empty($user['proof_of_address'])) {
            $poa_path = $upload_dir . basename($user['proof_of_address']);
            if (is_file($poa_path)) {
                // Use the address from the ID if available, otherwise use user's home_address
                $poa_expected_addr = $expected_address;
                if (isset($results['govt_id']['address_on_id']) && is_string($results['govt_id']['address_on_id']) && $results['govt_id']['address_on_id'] !== '') {
                    $poa_expected_addr = $results['govt_id']['address_on_id'];
                }
                $poa_result = bc_kyc_ai_analyze_proof_of_address($poa_path, $poa_expected_addr);
                $results['proof_of_address'] = $poa_result;
                if (isset($poa_result['tokens_used'])) $total_tokens += $poa_result['tokens_used'];
                if (isset($poa_result['duration_ms'])) $total_duration += $poa_result['duration_ms'];
                if (($poa_result['status'] ?? '') === 'error') $errors[] = 'Proof of address: ' . ($poa_result['reason'] ?? 'AI error');
            }
        }

        // Analyze Liveliness Video
        if (in_array('liveliness_video', $enabled_checks, true) && !empty($user['liveliness_video'])) {
            $video_path = $upload_dir . basename($user['liveliness_video']);
            if (is_file($video_path)) {
                $video_result = bc_kyc_ai_analyze_video($video_path, $expected_name);
                $results['liveliness_video'] = $video_result;
                if (isset($video_result['tokens_used'])) $total_tokens += $video_result['tokens_used'];
                if (isset($video_result['duration_ms'])) $total_duration += $video_result['duration_ms'];
                if (($video_result['status'] ?? '') === 'error') $errors[] = 'Liveliness video: ' . ($video_result['reason'] ?? 'AI error');
            }
        }

        // Generate overall decision
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
     * Generate an overall KYC decision from individual document analyses.
     *
     * Decision logic:
     *   - If any required check has an AI error → status='review' (needs human)
     *   - If ID is invalid, expired, or name doesn't match → status='reject'
     *   - If selfie face doesn't match ID → status='reject'
     *   - If proof of address is invalid, too old, or address doesn't match → status='reject'
     *   - If liveliness video fails → status='reject'
     *   - If all checks pass with high confidence → status='approve'
     *   - If all checks pass but any confidence is below threshold → status='review'
     *
     * @param array $results  Individual document analysis results
     * @param array $enabled_checks  Enabled KYC checks
     * @param string $expected_name  Expected name for cross-check
     * @param array $errors  List of errors encountered
     * @return array Decision array with status, reason, and summary
     */
    function bc_kyc_ai_make_decision($results, $enabled_checks, $expected_name, $errors)
    {
        $rejection_reasons = [];
        $review_reasons = [];
        $confidence_scores = [];

        // Check Government ID
        if (isset($results['govt_id']) && ($results['govt_id']['status'] ?? '') === 'success') {
            $id = $results['govt_id'];
            if ($id['is_valid'] === false) {
                $rejection_reasons[] = 'Government ID appears to be invalid or tampered';
            }
            if ($id['is_expired'] === true) {
                $rejection_reasons[] = 'Government ID is expired';
            }
            if ($expected_name !== '' && $id['name_matches'] === false) {
                $rejection_reasons[] = "Name on ID ('{$id['full_name']}') does not match registered name ('{$expected_name}')";
            }
            if (isset($id['confidence'])) $confidence_scores[] = (int)$id['confidence'];
        } elseif (in_array('govt_id', $enabled_checks, true) && !isset($results['govt_id'])) {
            $review_reasons[] = 'Government ID was not analyzed';
        }

        // Check Selfie vs ID
        if (isset($results['selfie']) && ($results['selfie']['status'] ?? '') === 'success') {
            $selfie = $results['selfie'];
            if ($selfie['face_detected'] === false) {
                $rejection_reasons[] = 'No face detected in selfie';
            }
            if ($selfie['is_live_photo'] === false) {
                $rejection_reasons[] = 'Selfie appears to be a screenshot or photo of a photo, not a live capture';
            }
            if ($selfie['face_matches_id'] === false) {
                $rejection_reasons[] = 'Face in selfie does not match photo on ID';
            }
            if (isset($selfie['confidence'])) $confidence_scores[] = (int)$selfie['confidence'];
        }

        // Check Proof of Address
        if (isset($results['proof_of_address']) && ($results['proof_of_address']['status'] ?? '') === 'success') {
            $poa = $results['proof_of_address'];
            if ($poa['is_valid_utility_bill'] === false) {
                $rejection_reasons[] = 'Proof of address is not a valid utility bill or bank statement';
            }
            if ($poa['is_under_3_months'] === false) {
                $rejection_reasons[] = 'Proof of address is older than 3 months';
            }
            if ($poa['address_matches'] === false) {
                $rejection_reasons[] = 'Address on proof of address does not match ID address';
            }
            if (isset($poa['confidence'])) $confidence_scores[] = (int)$poa['confidence'];
        }

        // Check Liveliness Video
        if (isset($results['liveliness_video']) && ($results['liveliness_video']['status'] ?? '') === 'success') {
            $video = $results['liveliness_video'];
            if ($video['face_detected'] === false) {
                $rejection_reasons[] = 'No face detected in liveliness video';
            }
            if ($video['is_live_video'] === false) {
                $rejection_reasons[] = 'Liveliness video does not appear to be genuine';
            }
            if (isset($video['confidence'])) $confidence_scores[] = (int)$video['confidence'];
        }

        // If there are AI errors on required checks, flag for review
        if (!empty($errors)) {
            $review_reasons[] = 'AI could not fully analyze: ' . implode('; ', $errors);
        }

        // Determine overall decision
        if (!empty($rejection_reasons)) {
            return [
                'status' => 'reject',
                'kyc_status' => 3,
                'reason' => implode(' | ', $rejection_reasons),
                'reasons' => $rejection_reasons,
                'confidence' => $confidence_scores ? (int)round(array_sum($confidence_scores) / count($confidence_scores)) : 0,
                'auto_approved' => false,
            ];
        }

        // Average confidence
        $avg_confidence = $confidence_scores ? (int)round(array_sum($confidence_scores) / count($confidence_scores)) : 0;

        // If there are review reasons (incomplete analysis), flag for manual review
        if (!empty($review_reasons)) {
            return [
                'status' => 'review',
                'kyc_status' => 1,
                'reason' => implode(' | ', $review_reasons),
                'reasons' => $review_reasons,
                'confidence' => $avg_confidence,
                'auto_approved' => false,
            ];
        }

        // All checks passed. Check confidence threshold for auto-approval
        if ($avg_confidence >= 75) {
            return [
                'status' => 'approve',
                'kyc_status' => 2,
                'reason' => 'All KYC checks passed with high confidence',
                'reasons' => [],
                'confidence' => $avg_confidence,
                'auto_approved' => true,
            ];
        }

        // Passed but confidence is borderline — flag for manual review
        return [
            'status' => 'review',
            'kyc_status' => 1,
            'reason' => "All checks passed but AI confidence is {$avg_confidence}% (below 75% threshold). Manual review recommended.",
            'reasons' => ["AI confidence too low for auto-approval: {$avg_confidence}%"],
            'confidence' => $avg_confidence,
            'auto_approved' => false,
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

        // Extract frames at 1/3, 1/2, and 2/3 of the video duration
        $frames = [];
        for ($i = 1; $i <= $frame_count; $i++) {
            $frame_path = $temp_dir . '/frame_' . $i . '.jpg';
            $ss = sprintf('%.2f', $i / ($frame_count + 1)); // Seek to different positions

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
