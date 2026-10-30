<?php
// AI Helper to connect to DeepSeek API with mock fallback

class AIHelper {
    public static function generateContent($prompt, $systemInstruction = '') {
        $errors = [];

        // Formatting & Humanizing injection rules (No Markdown headers/bold stars, proper paragraphing, 100% human style, advanced spacing)
        $curriculumRule = " Align all educational content, lesson plans, quiz questions, and report remarks strictly with the official Nigerian educational system, utilizing the NERDC curriculum guidelines and conforming to WAEC/NECO examination standards. Use localized terms, names, and Nigerian examples where applicable. Do not guess, hallucinate, or approximate information; all mathematical equations, subject formulas, curriculum terms, and academic criteria must be 100% accurate and aligned with verified textbook standards.";
        $formattingRule = "\n\nFormat the response as plain, advanced text with clean double-spacing. Every paragraph, question, list item, or concept must be separated by double line breaks. Do NOT pack multiple questions or concepts into a single line or sentence. Do NOT use markdown symbols such as headers (e.g., ###), bullet lists with asterisks, or bold tags (e.g., **). Write in a completely natural, humanized tone with clarity, just as if you are drafting a document in Microsoft Word." . " " . $curriculumRule;
        $prompt .= $formattingRule;
        if (!empty($systemInstruction)) {
            $systemInstruction .= " " . $curriculumRule . " Always respond in plain humanized text with proper paragraph layout and absolutely no markdown formatting, headers, or bold markers. Ensure items and questions are clearly separated by double line breaks.";
        } else {
            $systemInstruction = "You are an AI educational copilot." . " " . $curriculumRule;
        }

        // 1. Try DeepSeek AI
        if (defined('DEEPSEEK_API_KEY') && !empty(DEEPSEEK_API_KEY)) {
            $response = self::tryDeepSeek(DEEPSEEK_API_KEY, $prompt, $systemInstruction, $errors);
            if ($response !== false) {
                return $response;
            }
        }

        // 2. Default Mock Response (if API fails or key is not configured)
        return self::getMockResponse($prompt, $errors);
    }

    private static function tryDeepSeek($apiKey, $prompt, $systemInstruction, &$errors) {
        $baseUrl = defined('DEEPSEEK_API_BASE_URL') && !empty(DEEPSEEK_API_BASE_URL) ? trim(DEEPSEEK_API_BASE_URL) : 'https://ai.schoolapp.ng';
        $baseUrl = rtrim($baseUrl, '/');
        
        // Auto-override if still pointing to the blocked workers.dev address
        if (str_contains($baseUrl, 'workers.dev')) {
            $baseUrl = 'https://ai.schoolapp.ng';
        }
        
        $url = $baseUrl . "/chat/completions";

        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = ["role" => "system", "content" => $systemInstruction];
        }
        $messages[] = ["role" => "user", "content" => $prompt];

        $payload = [
            "model" => "deepseek-chat",
            "messages" => $messages,
            "temperature" => 0.7,
            "stream" => false
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Bypass local Windows SSL cert issue if any
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Force IPv4 resolution to prevent timeouts

        $response = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errStr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo) {
            $errors[] = "cURL Error (" . $errNo . ") connecting to " . $url . ": " . $errStr;
            return false;
        }

        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['choices'][0]['message']['content'])) {
                return $result['choices'][0]['message']['content'];
            }
        }

        $errors[] = "HTTP Status Code " . $httpCode . " from " . $url . ". Raw response: " . substr($response, 0, 250);
        return false;
    }

    /**
     * Alias for generateContent() — allows AIHelper::generateText($prompt) calls.
     */
    public static function generateText($prompt, $systemInstruction = '') {
        return self::generateContent($prompt, $systemInstruction);
    }

    private static function getMockResponse($prompt, $errors = []) {
        $promptLower = strtolower($prompt);
        
        $notice = "";
        if (!defined('DEEPSEEK_API_KEY') || empty(DEEPSEEK_API_KEY)) {
            $notice = "\n\n(System Notification: Please configure your DeepSeek API Key in the Super Settings panel to activate live AI generation.)";
        } elseif (!empty($errors)) {
            $notice = "\n\n(System Warning: DeepSeek API connection failed. Technical details: " . implode(" -> ", $errors) . ")";
        }
        
        if (strpos($promptLower, 'lesson') !== false) {
            return "Introductory Lesson Plan\n\n" .
                   "Topic: Introductory Lesson plan derived from prompt.\n\n" .
                   "Duration: 45 Minutes\n\n" .
                   "Objectives: Define key terms, demonstrate practical models, and complete quiz reviews.\n\n" .
                   "Overview: Initial overview covering foundational concepts and interactive classroom discussions." . 
                   $notice;
        }
        
        if (strpos($promptLower, 'report') !== false || strpos($promptLower, 'comment') !== false) {
            return "Academic Report Remarks\n\n" .
                   "The student demonstrated consistent comprehension during tests, submitting homework assignments on schedule.\n\n" .
                   "Continued focus on attendance will reinforce academic performance." . 
                   $notice;
        }

        if (strpos($promptLower, 'quiz') !== false || strpos($promptLower, 'exam') !== false) {
            return "Generated Quiz Questions\n\n" .
                   "Question 1: What is the fundamental concept discussed in this curriculum? (Multiple Choice)\n\n" .
                   "A. Relational database structures\n\n" .
                   "B. Linear sequence algorithms\n\n" .
                   "C. Object-oriented abstraction\n\n" .
                   "D. Network isolation protocols\n\n" .
                   "Question 2: Explain the core differences between primary and secondary inputs. (Essay)\n\n" .
                   "Question 3: True or False: Prepared statements prevent SQL injections. (True/False)" . 
                   $notice;
        }

        return "AI Assistant Help\n\n" .
               "Prompt parsed successfully." . 
               $notice;
    }
}
?>
