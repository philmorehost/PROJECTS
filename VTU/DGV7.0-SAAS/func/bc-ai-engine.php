<?php
/**
 * bc-ai-engine.php — DGV6.90 AI Edition (Cloud Focus)
 * Unified Cloud AI Engine Interface
 *
 * Provides a secure interface to Cloud AI providers:
 * - Google Gemini (Default)
 * - DeepSeek
 * - Groq
 * - OpenAI (GPT-4o Vision)
 * - Anthropic (Claude 3.5 Vision)
 * - Ollama (local/open-source)
 * - HuggingFace Inference API
 *
 * GOLDEN RULE: This class never writes to the database directly,
 * never moves money, and never executes transactions.
 */

if (class_exists('AIEngine')) return; // Guard against double-include

class AIEngine
{
    // ─── Configuration ────────────────────────────────────────
    private string $base_url;
    private string $api_key          = '';
    private string $provider         = 'gemini'; // Default to gemini
    private int    $timeout_generate = 60;   
    private bool   $debug            = false; 

    public function __construct()
    {
        // Load Global Configuration
        $this->provider = getSuperAdminOption('ai_provider', 'gemini');
        
        // Load provider-specific key with fallback to global
        $key_name = "ai_{$this->provider}_api_key";
        $this->api_key = getSuperAdminOption($key_name, '');
        if (empty($this->api_key)) $this->api_key = getSuperAdminOption('ai_api_key', '');

        switch ($this->provider) {
            case 'gemini':
                $this->base_url = 'https://generativelanguage.googleapis.com/v1beta';
                break;
            case 'deepseek':
                $this->base_url = 'https://api.deepseek.com/v1';
                break;
            case 'groq':
                $this->base_url = 'https://api.groq.com/openai/v1';
                break;
            case 'openai':
                $this->base_url = 'https://api.openai.com/v1';
                break;
            case 'anthropic':
                $this->base_url = 'https://api.anthropic.com/v1';
                break;
            case 'ollama':
                $this->base_url = rtrim(getSuperAdminOption('ai_ollama_url', 'http://localhost:11434'), '/');
                break;
            case 'huggingface':
                $this->base_url = 'https://api-inference.huggingface.co';
                break;
            default:
                // Fallback to gemini if unknown
                $this->provider = 'gemini';
                $this->base_url = 'https://generativelanguage.googleapis.com/v1beta';
                break;
        }
    }

    public function getBaseUrl(): string { return $this->base_url; }
    public function getApiKey(): string { return $this->api_key; }
    public function getProvider(): string { return $this->provider; }

    /**
     * Get the recommended default model for the current provider
     */
    public function getDefaultModel(): string {
        switch ($this->provider) {
            case 'deepseek': return 'deepseek-chat';
            case 'groq':     return 'llama3-70b-8192';
            case 'openai':   return 'gpt-4o-mini';
            case 'anthropic': return 'claude-3-5-sonnet-latest';
            case 'ollama':   return 'llava:7b';
            case 'huggingface': return 'Salesforce/blip-image-captioning';
            default:         return 'gemini-1.5-flash';
        }
    }

    /**
     * Check if a model name is compatible with the current provider
     */
    public function isModelCompatible(string $model): bool {
        if ($this->provider === 'gemini') return stripos($model, 'gemini') !== false;
        if ($this->provider === 'deepseek') return stripos($model, 'deepseek') !== false;
        if ($this->provider === 'groq') return (stripos($model, 'llama') !== false || stripos($model, 'mixtral') !== false);
        if ($this->provider === 'openai') return (stripos($model, 'gpt') !== false || stripos($model, 'o1') !== false || stripos($model, 'dall') !== false);
        if ($this->provider === 'anthropic') return (stripos($model, 'claude') !== false);
        if ($this->provider === 'ollama') return true; // Ollama accepts any local model name
        if ($this->provider === 'huggingface') return true;
        return false;
    }

    /**
     * Returns all providers that support vision/image input, with their default vision models.
     */
    public static function getVisionProviders(): array {
        return [
            'gemini'      => ['label' => 'Google Gemini Vision',     'default_model' => 'gemini-1.5-flash'],
            'openai'      => ['label' => 'OpenAI GPT-4o Vision',     'default_model' => 'gpt-4o-mini'],
            'anthropic'   => ['label' => 'Anthropic Claude Vision',  'default_model' => 'claude-3-5-sonnet-latest'],
            'ollama'      => ['label' => 'Ollama (Local/Open-source)','default_model' => 'llava:7b'],
            'huggingface' => ['label' => 'HuggingFace Inference',     'default_model' => 'Salesforce/blip-image-captioning'],
        ];
    }

    /**
     * Get the configured vision provider (separate from the text provider, so a vendor can
     * use a cheap text AI for chat and a vision AI for document analysis).
     */
    public function getVisionProvider(): string {
        $vision_provider = getSuperAdminOption('ai_vision_provider', '');
        if ($vision_provider !== '') return $vision_provider;
        return $this->provider; // Fall back to the main provider
    }

    /**
     * Get the default vision model for the vision provider.
     */
    public function getVisionModel(): string {
        $vp = $this->getVisionProvider();
        $providers = self::getVisionProviders();
        if (isset($providers[$vp])) {
            $configured = getSuperAdminOption('ai_vision_model', '');
            return $configured !== '' ? $configured : $providers[$vp]['default_model'];
        }
        return $this->getDefaultModel();
    }

    /**
     * Unified Chat/Generation Entry Point
     */
    public function chat(string $model, string $prompt, array $options = []): array
    {
        switch ($this->provider) {
            case 'gemini':   return $this->chatGemini($model, $prompt, $options);
            case 'deepseek': return $this->chatOpenAICompatible($this->base_url, $model, $prompt, $options);
            case 'groq':     return $this->chatOpenAICompatible($this->base_url, $model, $prompt, $options);
            case 'openai':   return $this->chatOpenAI($model, $prompt, $options);
            case 'anthropic': return $this->chatAnthropic($model, $prompt, $options);
            case 'ollama':   return $this->chatOllama($model, $prompt, $options);
            case 'huggingface': return $this->chatHuggingFace($model, $prompt, $options);
            default:         return $this->chatGemini($model, $prompt, $options);
        }
    }

    /**
     * Google Gemini API Handler
     */
    private function chatGemini(string $model, string $prompt, array $options, array $images = []): array
    {
        if (strpos($model, 'gemini') === false) $model = 'gemini-1.5-flash';
        
        $url = "{$this->base_url}/models/{$model}:generateContent?key={$this->api_key}";
        
        $contents_parts = [['text' => $prompt]];
        foreach ($images as $img_base64) {
            $clean_img = preg_replace('/^data:image\/[a-z]+;base64,/', '', $img_base64);
            $contents_parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $clean_img
                ]
            ];
        }

        $payload = json_encode([
            'contents' => [['parts' => $contents_parts]],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['num_predict'] ?? 1024,
            ]
        ]);

        $start = microtime(true);
        $raw = $this->curlPost($url, $payload, $this->timeout_generate);
        $duration_ms = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) return $this->errorResult('Gemini API unreachable', $model, $duration_ms);
        
        $data = json_decode($raw, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($text)) {
            $err = $data['error']['message'] ?? 'Unknown Gemini Error';
            return $this->errorResult($err, $model, $duration_ms);
        }

        return [
            'status' => 'success',
            'response' => $text,
            'model' => $model,
            'duration_ms' => $duration_ms,
            'provider' => 'gemini'
        ];
    }

    public function chatWithVision(string $model, string $prompt, array $images, array $options = []): array
    {
        $vision_provider = $this->getVisionProvider();
        switch ($vision_provider) {
            case 'gemini':     return $this->chatGemini($model, $prompt, $options, $images);
            case 'openai':     return $this->chatOpenAI($model, $prompt, $options, $images);
            case 'anthropic':  return $this->chatAnthropic($model, $prompt, $options, $images);
            case 'ollama':     return $this->chatOllama($model, $prompt, $options, $images);
            case 'huggingface': return $this->chatHuggingFace($model, $prompt, $options, $images);
            default:           return $this->errorResult('Vision not supported for ' . $vision_provider);
        }
    }

    /**
     * OpenAI Chat Completions Handler (supports vision via image_url content parts)
     */
    private function chatOpenAI(string $model, string $prompt, array $options, array $images = []): array
    {
        $url = "{$this->base_url}/chat/completions";

        // Build content array: text + images
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $img_base64) {
            $clean_img = preg_replace('/^data:image\/[a-z]+;base64,/', '', $img_base64);
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:image/jpeg;base64,{$clean_img}"]
            ];
        }

        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens' => $options['num_predict'] ?? 1024,
        ]);

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->api_key}"
        ];

        $start = microtime(true);
        $raw = $this->curlPost($url, $payload, $this->timeout_generate, $headers);
        $duration_ms = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) return $this->errorResult('OpenAI API unreachable', $model, $duration_ms);

        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content'] ?? '';

        if (empty($text)) {
            $err = $data['error']['message'] ?? 'Unknown OpenAI Error';
            return $this->errorResult($err, $model, $duration_ms);
        }

        // Extract token usage for billing
        $token_usage = $data['usage']['total_tokens'] ?? 0;

        return [
            'status' => 'success',
            'response' => $text,
            'model' => $model,
            'duration_ms' => $duration_ms,
            'provider' => 'openai',
            'tokens_used' => $token_usage,
        ];
    }

    /**
     * Anthropic Messages API Handler (supports vision via image content blocks)
     */
    private function chatAnthropic(string $model, string $prompt, array $options, array $images = []): array
    {
        $url = "{$this->base_url}/messages";

        // Build content array: text + image blocks
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $img_base64) {
            $clean_img = preg_replace('/^data:image\/[a-z]+;base64,/', '', $img_base64);
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/jpeg',
                    'data' => $clean_img
                ]
            ];
        }

        $payload = json_encode([
            'model' => $model,
            'max_tokens' => $options['num_predict'] ?? 1024,
            'temperature' => $options['temperature'] ?? 0.3,
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        $headers = [
            'Content-Type: application/json',
            "x-api-key: {$this->api_key}",
            'anthropic-version: 2023-06-01',
        ];

        $start = microtime(true);
        $raw = $this->curlPost($url, $payload, $this->timeout_generate, $headers);
        $duration_ms = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) return $this->errorResult('Anthropic API unreachable', $model, $duration_ms);

        $data = json_decode($raw, true);
        $text = '';
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'] ?? '';
                }
            }
        }

        if (empty($text)) {
            $err = $data['error']['message'] ?? 'Unknown Anthropic Error';
            return $this->errorResult($err, $model, $duration_ms);
        }

        // Extract token usage for billing
        $token_usage = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

        return [
            'status' => 'success',
            'response' => $text,
            'model' => $model,
            'duration_ms' => $duration_ms,
            'provider' => 'anthropic',
            'tokens_used' => $token_usage,
        ];
    }

    /**
     * Ollama Local API Handler (supports vision via images array in chat)
     */
    private function chatOllama(string $model, string $prompt, array $options, array $images = []): array
    {
        $url = "{$this->base_url}/api/chat";

        $payload_data = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? 0.3,
            ]
        ];

        // Add images as base64 (Ollama accepts raw base64 strings)
        foreach ($images as $img_base64) {
            $clean_img = preg_replace('/^data:image\/[a-z]+;base64,/', '', $img_base64);
            $payload_data['images'][] = $clean_img;
        }

        $payload = json_encode($payload_data);

        $start = microtime(true);
        $raw = $this->curlPost($url, $payload, $this->timeout_generate, ['Content-Type: application/json']);
        $duration_ms = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) return $this->errorResult('Ollama API unreachable (is it running?)', $model, $duration_ms);

        $data = json_decode($raw, true);
        $text = $data['message']['content'] ?? '';

        if (empty($text)) {
            $err = $data['error'] ?? 'Unknown Ollama Error';
            return $this->errorResult($err, $model, $duration_ms);
        }

        // Ollama doesn't always return token counts, estimate from response length
        $token_usage = max(1, (int)ceil(strlen($text) / 4));

        return [
            'status' => 'success',
            'response' => $text,
            'model' => $model,
            'duration_ms' => $duration_ms,
            'provider' => 'ollama',
            'tokens_used' => $token_usage,
        ];
    }

    /**
     * HuggingFace Inference API Handler (supports vision via pipeline)
     */
    private function chatHuggingFace(string $model, string $prompt, array $options, array $images = []): array
    {
        // HuggingFace uses a different API for vision - text+image via the feature extraction pipeline
        $url = "https://api-inference.huggingface.co/models/{$model}";

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->api_key}",
        ];

        // For vision tasks, we send images with a text prompt
        $payload_data = [];
        foreach ($images as $img_base64) {
            $clean_img = preg_replace('/^data:image\/[a-z]+;base64,/', '', $img_base64);
            $payload_data['inputs'] = [
                'image' => $clean_img,
                'text' => $prompt,
            ];
            // Use the first image for HuggingFace (it doesn't support multi-image in the same call)
            break;
        }

        if (empty($images)) {
            $payload_data['inputs'] = $prompt;
        }

        $payload = json_encode($payload_data);

        $start = microtime(true);
        $raw = $this->curlPost($url, $payload, $this->timeout_generate, $headers);
        $duration_ms = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) return $this->errorResult('HuggingFace API unreachable', $model, $duration_ms);

        $data = json_decode($raw, true);

        // HuggingFace returns different formats depending on the model
        $text = '';
        if (isset($data[0]['generated_text'])) {
            $text = $data[0]['generated_text'];
        } elseif (isset($data['generated_text'])) {
            $text = $data['generated_text'];
        } elseif (isset($data[0]['caption'])) {
            $text = $data[0]['caption'];
        } elseif (is_array($data) && !empty($data)) {
            $text = json_encode($data[0] ?? $data);
        }

        if (empty($text)) {
            $err = $data['error'] ?? 'Unknown HuggingFace Error';
            return $this->errorResult($err, $model, $duration_ms);
        }

        // HuggingFace doesn't always return token counts, estimate from response length
        $token_usage = max(1, (int)ceil(strlen($text) / 4));

        return [
            'status' => 'success',
            'response' => $text,
            'model' => $model,
            'duration_ms' => $duration_ms,
            'provider' => 'huggingface',
            'tokens_used' => $token_usage,
        ];
    }

    /**
     * OpenAI Compatible API Handler (DeepSeek, Groq, etc.)
     */
    private function chatOpenAICompatible(string $endpoint, string $model, string $prompt, array $options): array
    {
        $url = "{$endpoint}/chat/completions";
        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['num_predict'] ?? 1024,
        ]);

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->api_key}"
        ];

        $start = microtime(true);
        $raw = $this->curlPost($url, $payload, $this->timeout_generate, $headers);
        $duration_ms = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) return $this->errorResult("{$this->provider} API unreachable", $model, $duration_ms);
        
        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content'] ?? '';

        if (empty($text)) {
            $err = $data['error']['message'] ?? 'Unknown API Error';
            return $this->errorResult($err, $model, $duration_ms);
        }

        return [
            'status' => 'success',
            'response' => $text,
            'model' => $model,
            'duration_ms' => $duration_ms,
            'provider' => $this->provider
        ];
    }

    /**
     * Parse a voice transcript into a structured VTU transaction intent.
     */
    public function parseVtuIntent(string $transcript, string $model = 'gemini-1.5-flash', array $context = []): ?array
    {
        $safe_transcript = bc_sanitize(substr($transcript, 0, 300));
        
        $history_str = "No recent history.";
        if (!empty($context['recent_history']) && is_array($context['recent_history'])) {
            $history_str = implode("\n", $context['recent_history']);
        }

        $system_prompt = <<<PROMPT
You are a professional Nigerian VTU transaction intent parser.
Extract the following fields from the user's voice command:
- service: one of [airtime, data, electricity, cable, betting, exam]
- amount: numeric value in Naira (for airtime/electricity/betting). 
  IMPORTANT FOR DATA/CABLE: Look at the "Current Data Prices" context and use the EXACT string for the item (e.g. "1GB", "1.5GB", "Compact", "SME 1GB"). Do not change the format.
- phone: 11-digit Nigerian phone number starting with 0 OR Meter/IUC/Smartcard number.
- network: one of [MTN, Airtel, Glo, 9mobile] for mobile, OR [IKEDC, EKEDC, AEDC, EEDC, JEDC, IBEDC, KEDCO, PHED, YEDC, BEDC, ABA, KAEDCO] for electricity, OR [DSTV, GOTV, STARTIMES, SHOWMAX] for cable, OR [WAEC, NECO, NABTEB] for exam.
- type: for data [SME, Gifting, Corporate Gifting, Shared, Direct, DD-DATA] or electricity [Prepaid, Postpaid] or cable [package name].
- confidence: a number 0-100.

CONTEXTUAL HELP (Use this history to infer missing details if the user is vague):
$history_str

Output ONLY valid JSON.
Voice command: $safe_transcript
PROMPT;

        $result = $this->chat($model, $system_prompt, ['temperature' => 0.1]);

        if ($result['status'] !== 'success') return null;

        preg_match('/\{[^}]+\}/', $result['response'], $matches);
        if (empty($matches[0])) return null;

        $parsed = json_decode($matches[0], true);
        if (!is_array($parsed)) return null;

        // Validation & Sanitization
        $parsed['phone'] = bc_sanitize_phone($parsed['phone'] ?? '');
        
        $service = strtolower($parsed['service'] ?? '');
        if (in_array($service, ['data', 'cable', 'exam'])) {
            // Preserve strings for plan identifiers (e.g. "1GB", "Compact", "WAEC")
            $parsed['amount'] = trim(strip_tags((string)($parsed['amount'] ?? '')));
        } else {
            // Strictly numeric for monetary services
            $parsed['amount'] = bc_sanitize_number($parsed['amount'] ?? 0);
        }
        
        $parsed['confidence'] = (int)($parsed['confidence'] ?? 0);

        return $parsed;
    }

    /**
     * Check if the currently selected AI provider is reachable.
     */
    public function isAiOnline(): bool
    {
        if (empty($this->api_key)) return false;
        
        // Simple reachability check
        $ch = curl_init($this->base_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        return ($res !== false);
    }

    public function listModels(): array { return []; } // Cloud models are fixed

    private function curlPost(string $url, string $body, int $timeout = 30, array $headers = []): string|false
    {
        $ch = curl_init($url);
        $final_headers = array_merge(['Content-Type: application/json'], $headers);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $final_headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function curlGet(string $url, int $timeout = 10, array $headers = []): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * Load the API key for any provider (including the vision provider, which may differ from the main provider).
     */
    public function getProviderApiKey(string $provider): string
    {
        $key_name = "ai_{$provider}_api_key";
        $key = getSuperAdminOption($key_name, '');
        if (empty($key)) $key = getSuperAdminOption('ai_api_key', '');
        return (string)$key;
    }

    /**
     * Get the base URL for any provider.
     */
    public function getProviderBaseUrl(string $provider): string
    {
        $providers = self::getVisionProviders();
        $main_providers = ['gemini', 'deepseek', 'groq', 'openai', 'anthropic', 'ollama', 'huggingface'];
        if (!in_array($provider, $main_providers, true)) $provider = 'gemini';

        switch ($provider) {
            case 'gemini':     return 'https://generativelanguage.googleapis.com/v1beta';
            case 'deepseek':   return 'https://api.deepseek.com/v1';
            case 'groq':       return 'https://api.groq.com/openai/v1';
            case 'openai':     return 'https://api.openai.com/v1';
            case 'anthropic':  return 'https://api.anthropic.com/v1';
            case 'ollama':     return rtrim(getSuperAdminOption('ai_ollama_url', 'http://localhost:11434'), '/');
            case 'huggingface': return 'https://api-inference.huggingface.co';
            default:           return 'https://generativelanguage.googleapis.com/v1beta';
        }
    }

    private function errorResult(string $message, string $model = '', int $duration_ms = 0): array
    {
        return [
            'status'      => 'error',
            'response'    => '',
            'message'     => $message,
            'model'       => $model,
            'duration_ms' => $duration_ms,
        ];
    }
}

// Global singleton accessor
function ai_engine(): AIEngine
{
    static $instance = null;
    if ($instance === null) {
        $instance = new AIEngine();
    }
    return $instance;
}
