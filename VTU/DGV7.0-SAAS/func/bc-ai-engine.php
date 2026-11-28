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

if (defined('BC_AI_ENGINE_LOADED')) return; // Guard against double-include
define('BC_AI_ENGINE_LOADED', true);

if (!class_exists('AIEngine', false)) {
class AIEngine
{
    // ─── Configuration ────────────────────────────────────────
    /** @var string */
    private $base_url;
    /** @var string */
    private $api_key          = '';
    /** @var string */
    private $provider         = 'gemini'; // Default to gemini
    /** @var int */
    private $timeout_generate = 60;
    /** @var bool */
    private $debug            = false;

    public function __construct()
    {
        // Load Global Configuration
        $this->provider = strtolower(trim((string)(getSuperAdminOption('ai_provider', 'gemini') ?? 'gemini')));
        if ($this->provider === '') $this->provider = 'gemini';

        // Load provider-specific key with fallback to global
        $key_name = "ai_{$this->provider}_api_key";
        $this->api_key = (string)(getSuperAdminOption($key_name, '') ?? '');
        if ($this->api_key === '') $this->api_key = (string)(getSuperAdminOption('ai_api_key', '') ?? '');

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
                $ollama_url = (string)(getSuperAdminOption('ai_ollama_url', 'http://localhost:11434') ?? 'http://localhost:11434');
                $this->base_url = rtrim($ollama_url !== '' ? $ollama_url : 'http://localhost:11434', '/');
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

    public function getApiKeyForProvider(string $provider): string {
        $k = (string)(getSuperAdminOption("ai_{$provider}_api_key", '') ?? '');
        if ($k !== '') return $k;
        if ($provider === $this->provider && $this->api_key !== '') return $this->api_key;
        return (string)(getSuperAdminOption('ai_api_key', '') ?? '');
    }

    public function getBaseUrlForProvider(string $provider): string {
        switch ($provider) {
            case 'gemini':      return 'https://generativelanguage.googleapis.com/v1beta';
            case 'deepseek':    return 'https://api.deepseek.com/v1';
            case 'groq':        return 'https://api.groq.com/openai/v1';
            case 'openai':      return 'https://api.openai.com/v1';
            case 'anthropic':   return 'https://api.anthropic.com/v1';
            case 'ollama':
                $ollama_url = (string)(getSuperAdminOption('ai_ollama_url', 'http://localhost:11434') ?? 'http://localhost:11434');
                return rtrim($ollama_url !== '' ? $ollama_url : 'http://localhost:11434', '/');
            case 'huggingface': return 'https://api-inference.huggingface.co';
            default:            return $this->base_url;
        }
    }

    public function isVisionConfigured(): bool {
        $vp = $this->getVisionProvider();
        if ($vp === 'ollama') return true;
        return $this->getApiKeyForProvider($vp) !== '';
    }

    private function extractMediaPart(string $data_url): array {
        if (preg_match('/^data:([^;]+);base64,(.+)$/s', $data_url, $m)) {
            return ['mime' => strtolower(trim($m[1])), 'data' => $m[2]];
        }
        return ['mime' => 'image/jpeg', 'data' => $data_url];
    }

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
        $vision_provider = (string)(getSuperAdminOption('ai_vision_provider', '') ?? '');
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
            $configured = (string)(getSuperAdminOption('ai_vision_model', '') ?? '');
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
        $api_key = $this->getApiKeyForProvider('gemini');
        $base_url = $this->getBaseUrlForProvider('gemini');

        $url = "{$base_url}/models/{$model}:generateContent?key={$api_key}";
        
        $contents_parts = [['text' => $prompt]];
        foreach ($images as $img_base64) {
            $part = $this->extractMediaPart($img_base64);
            $contents_parts[] = [
                'inline_data' => [
                    'mime_type' => $part['mime'],
                    'data' => $part['data']
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
        $api_key = $this->getApiKeyForProvider('openai');
        $base_url = $this->getBaseUrlForProvider('openai');
        $url = "{$base_url}/chat/completions";

        // Build content array: text + images/documents
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $img_base64) {
            $part = $this->extractMediaPart($img_base64);
            if ($part['mime'] === 'application/pdf') {
                $content[] = [
                    'type' => 'file',
                    'file' => [
                        'filename' => 'document.pdf',
                        'file_data' => "data:application/pdf;base64,{$part['data']}",
                    ]
                ];
            } else {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$part['mime']};base64,{$part['data']}"]
                ];
            }
        }

        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens' => $options['num_predict'] ?? 1024,
        ]);

        $headers = [
            "Authorization: Bearer {$api_key}",
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
        $api_key = $this->getApiKeyForProvider('anthropic');
        $base_url = $this->getBaseUrlForProvider('anthropic');
        $url = "{$base_url}/messages";

        // Build content array: text + image/document blocks
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $img_base64) {
            $part = $this->extractMediaPart($img_base64);
            if ($part['mime'] === 'application/pdf') {
                $content[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $part['data']
                    ]
                ];
            } else {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $part['mime'],
                        'data' => $part['data']
                    ]
                ];
            }
        }

        $payload = json_encode([
            'model' => $model,
            'max_tokens' => $options['num_predict'] ?? 1024,
            'temperature' => $options['temperature'] ?? 0.3,
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        $headers = [
            "x-api-key: {$api_key}",
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
        $base_url = $this->getBaseUrlForProvider('ollama');
        $url = "{$base_url}/api/chat";

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
            $part = $this->extractMediaPart($img_base64);
            $payload_data['images'][] = $part['data'];
        }

        $payload = json_encode($payload_data);

        $start = microtime(true);
        $raw = $this->curlPost($url, $payload, $this->timeout_generate);
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
        $api_key = $this->getApiKeyForProvider('huggingface');

        $headers = [
            "Authorization: Bearer {$api_key}",
        ];

        // For vision tasks, we send images with a text prompt
        $payload_data = [];
        foreach ($images as $img_base64) {
            $part = $this->extractMediaPart($img_base64);
            $payload_data['inputs'] = [
                'image' => $part['data'],
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

        $api_key = $this->getApiKeyForProvider($this->provider);
        $headers = [
            "Authorization: Bearer {$api_key}",
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
        if ($this->provider !== 'ollama' && empty($this->api_key)) return false;
        
        // Simple reachability check
        $ch = curl_init($this->base_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        return ($res !== false);
    }

    public function listModels(): array { return []; } // Cloud models are fixed

    /**
     * Build deduplicated HTTP headers for JSON POST requests so Content-Type: application/json
     * is never sent twice (duplicate Content-Type headers are folded into
     * "application/json, application/json" by HTTP/2 / proxies and rejected by Groq/OpenAI).
     */
    public static function buildPostHeaders(array $headers = []): array
    {
        $by_name = ['content-type' => 'Content-Type: application/json'];
        foreach ($headers as $h) {
            $line = trim((string)$h);
            if ($line === '') continue;
            $colon = strpos($line, ':');
            if ($colon === false) {
                $by_name['raw_' . count($by_name)] = $line;
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $val  = trim(substr($line, $colon + 1));
            if ($name === '') continue;
            $by_name[$name] = trim(substr($line, 0, $colon)) . ': ' . $val;
        }
        return array_values($by_name);
    }

    /** @return string|false */
    private function curlPost(string $url, string $body, int $timeout = 30, array $headers = [])
    {
        $ch = curl_init($url);
        $final_headers = self::buildPostHeaders($headers);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $final_headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /** @return string|false */
    private function curlGet(string $url, int $timeout = 10, array $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
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
}

// Global singleton accessor
if (!function_exists('ai_engine')) {
    function ai_engine(): AIEngine
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new AIEngine();
        }
        return $instance;
    }
}

if (!function_exists('bc_ai_is_saas')) {
    function bc_ai_is_saas(): bool
    {
        if (!function_exists('bc_is_saas_edition') && is_file(__DIR__ . '/bc-edition.php')) {
            include_once(__DIR__ . '/bc-edition.php');
        }
        return function_exists('bc_is_saas_edition') ? (bool)bc_is_saas_edition() : true;
    }
}

if (!function_exists('bc_ai_get_action_cost')) {
    /**
     * Per-action token fee configured by Super Admin in bc-spadmin/AIManagement.php.
     * In NON-SAAS, vendors are never billed for AI (always 0).
     */
    function bc_ai_get_action_cost(string $action_type): int
    {
        if (!bc_ai_is_saas()) return 0;
        $opt = function_exists('getSuperAdminOption') ? 'getSuperAdminOption' : null;
        if (!$opt) return 0;

        switch (strtolower(trim($action_type))) {
            case 'av_scan':
                return max(0, (int)getSuperAdminOption('ai_av_scan_cost', '1'));
            case 'kyc_report':
            case 'kyb_report':
                return max(0, (int)getSuperAdminOption('ai_kyc_report_cost', '3'));
            case 'support_reply':
                return max(0, (int)getSuperAdminOption('ai_support_reply_cost', '1'));
            case 'marketing_copilot':
                return max(0, (int)getSuperAdminOption('ai_marketing_copilot_cost', '3'));
            case 'sentinel_action':
                return 0;
            default:
                return max(0, (int)getSuperAdminOption('ai_price_per_request', '1'));
        }
    }
}

if (!function_exists('bc_ai_platform_status')) {
    /**
     * Unified check for whether a vendor can use the platform AI engine for a given capability.
     * - SAAS: checks Super Admin global toggle + Super Admin API key + vendor ai_status + vendor token balance.
     * - NON-SAAS: connects directly to owner API key (no Super Admin toggle or token balance billing).
     */
    function bc_ai_platform_status($connection_server, int $vendor_id, string $action_type = 'general'): array
    {
        $is_saas = bc_ai_is_saas();
        $ai = ai_engine();
        $provider = $ai->getProvider();
        $key_ok = ($provider === 'ollama') || ($ai->getApiKey() !== '');
        $global_enabled = true;
        if ($is_saas && function_exists('getSuperAdminOption')) {
            $global_enabled = ((string)getSuperAdminOption('ai_global_enabled', '0') === '1');
        }

        $vendor_enabled = !$is_saas;
        $token_balance = 0;
        $price_per_1k = $is_saas && function_exists('getSuperAdminOption')
            ? (float)getSuperAdminOption('ai_price_per_1k_tokens', '100')
            : 0.0;
        $assigned_model = '';

        if ($connection_server && $vendor_id > 0) {
            $q = @mysqli_query($connection_server, "SELECT ai_status, ai_token_balance, ai_price_per_1k_tokens, ai_model_assigned FROM sas_vendors WHERE id='" . (int)$vendor_id . "' LIMIT 1");
            if ($q && ($row = mysqli_fetch_assoc($q))) {
                $vendor_enabled = ((int)($row['ai_status'] ?? ($is_saas ? 0 : 1)) === 1);
                $token_balance = (int)($row['ai_token_balance'] ?? 0);
                if ($is_saas && isset($row['ai_price_per_1k_tokens']) && (float)$row['ai_price_per_1k_tokens'] > 0) {
                    $price_per_1k = (float)$row['ai_price_per_1k_tokens'];
                }
                $assigned_model = (string)($row['ai_model_assigned'] ?? '');
            }
        }

        $model = ($assigned_model !== '' && $ai->isModelCompatible($assigned_model))
            ? $assigned_model
            : $ai->getDefaultModel();

        $token_cost = bc_ai_get_action_cost($action_type);

        $available = true;
        $reason = 'AI engine active.';
        if (!$global_enabled) {
            $available = false;
            $reason = 'Global AI is disabled by Super Admin.';
        } elseif (!$vendor_enabled) {
            $available = false;
            $reason = 'AI Engine is paused in AI Business Suite.';
        } elseif (!$key_ok) {
            $available = false;
            $reason = $is_saas
                ? 'Super Admin has not configured an AI API key yet.'
                : 'No AI API key configured in AI Business Suite.';
        } elseif ($is_saas && $token_cost > 0 && $token_balance < $token_cost) {
            $available = false;
            $reason = "Insufficient AI tokens ({$token_balance} left, {$token_cost} required). Top up in AI Business Suite.";
        }

        return [
            'available'      => $available,
            'is_saas'        => $is_saas,
            'global_enabled' => $global_enabled,
            'vendor_enabled' => $vendor_enabled,
            'key_configured' => $key_ok,
            'token_balance'  => $token_balance,
            'token_cost'     => $token_cost,
            'price_per_1k'   => $price_per_1k,
            'provider'       => $provider,
            'model'          => $model,
            'reason'         => $reason,
        ];
    }
}

if (!function_exists('bc_ai_charge_vendor_tokens')) {
    /**
     * Atomically bill a SAAS vendor for AI usage (AV scan, KYC/KYB report, Support AI, Copilot)
     * and record the audit entry in sas_ai_transactions.
     * In NON-SAAS, skips token deduction ($cost=0) while keeping usage logs in sas_ai_transactions.
     */
    function bc_ai_charge_vendor_tokens(
        $connection_server,
        int $vendor_id,
        string $action_type,
        string $username = 'system',
        string $summary = '',
        string $model = '',
        int $duration_ms = 0,
        ?int $override_cost = null
    ): array {
        $is_saas = bc_ai_is_saas();
        $cost = ($override_cost !== null) ? max(0, $override_cost) : bc_ai_get_action_cost($action_type);
        if (!$is_saas) $cost = 0;

        if (!$connection_server || $vendor_id <= 0) {
            return ['ok' => !$is_saas || $cost === 0, 'tokens_charged' => 0, 'cost_naira' => 0.0, 'tokens_remaining' => 0, 'reason' => 'No DB connection'];
        }

        $q = @mysqli_query($connection_server, "SELECT ai_token_balance, ai_price_per_1k_tokens FROM sas_vendors WHERE id='" . (int)$vendor_id . "' LIMIT 1");
        $row = $q ? mysqli_fetch_assoc($q) : null;
        $bal = (int)($row['ai_token_balance'] ?? 0);
        $p1k = $is_saas
            ? (float)(($row['ai_price_per_1k_tokens'] ?? 0) > 0 ? $row['ai_price_per_1k_tokens'] : (function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_price_per_1k_tokens', '100') : 100))
            : 0.0;

        if ($is_saas && $cost > 0) {
            if ($bal < $cost) {
                return [
                    'ok'               => false,
                    'tokens_charged'   => 0,
                    'cost_naira'       => 0.0,
                    'tokens_remaining' => $bal,
                    'reason'           => "Insufficient AI tokens ({$bal} available, {$cost} needed).",
                ];
            }
            $upd = @mysqli_query($connection_server, "UPDATE sas_vendors SET ai_token_balance = ai_token_balance - " . (int)$cost . " WHERE id='" . (int)$vendor_id . "' AND ai_token_balance >= " . (int)$cost);
            if (!$upd || mysqli_affected_rows($connection_server) === 0) {
                return [
                    'ok'               => false,
                    'tokens_charged'   => 0,
                    'cost_naira'       => 0.0,
                    'tokens_remaining' => $bal,
                    'reason'           => 'Token deduction race or insufficient balance.',
                ];
            }
            $bal -= $cost;
        }

        $cost_naira = ($is_saas && $cost > 0) ? round(($cost / 1000.0) * $p1k, 2) : 0.0;
        $esc_user = mysqli_real_escape_string($connection_server, substr($username !== '' ? $username : 'system', 0, 190));
        $esc_act  = mysqli_real_escape_string($connection_server, substr($action_type, 0, 48));
        $esc_mod  = mysqli_real_escape_string($connection_server, substr($model !== '' ? $model : ai_engine()->getDefaultModel(), 0, 96));
        $esc_sum  = mysqli_real_escape_string($connection_server, substr($summary, 0, 500));
        $hash     = hash('sha256', $action_type . '|' . $username . '|' . $summary);

        @mysqli_query($connection_server,
            "INSERT INTO sas_ai_transactions (vendor_id, username, action_type, model_used, tokens_burned, cost_naira, prompt_hash, prompt, status, duration_ms)
             VALUES ('" . (int)$vendor_id . "', '$esc_user', '$esc_act', '$esc_mod', '" . (int)$cost . "', '" . number_format($cost_naira, 2, '.', '') . "', '$hash', '$esc_sum', 'success', '" . (int)$duration_ms . "')"
        );

        return [
            'ok'               => true,
            'tokens_charged'   => $cost,
            'cost_naira'       => $cost_naira,
            'tokens_remaining' => $bal,
            'reason'           => 'ok',
        ];
    }
}

if (!function_exists('bc_ai_sentinel_settings')) {
    function bc_ai_sentinel_settings($connection_server, int $vendor_id): array
    {
        $defaults = [
            'sentinel_auto_block'      => '0',
            'sentinel_block_threshold' => '20',
            'sentinel_anomaly_alert'   => '1',
            'sentinel_av_auto_flag'    => '1',
        ];
        if (!$connection_server || $vendor_id <= 0) return $defaults;
        $names = "'" . implode("','", array_keys($defaults)) . "'";
        $q = @mysqli_query($connection_server, "SELECT setting_name, setting_value FROM sas_settings WHERE vendor_id='" . (int)$vendor_id . "' AND setting_name IN ($names)");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                if (array_key_exists($r['setting_name'], $defaults)) {
                    $defaults[$r['setting_name']] = (string)$r['setting_value'];
                }
            }
        }
        return $defaults;
    }
}

if (!function_exists('bc_ai_sentinel_save_settings')) {
    function bc_ai_sentinel_save_settings($connection_server, int $vendor_id, array $values): bool
    {
        if (!$connection_server || $vendor_id <= 0) return false;
        $allowed = ['sentinel_auto_block', 'sentinel_block_threshold', 'sentinel_anomaly_alert', 'sentinel_av_auto_flag'];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $values)) continue;
            $esc_k = mysqli_real_escape_string($connection_server, $k);
            $esc_v = mysqli_real_escape_string($connection_server, substr((string)$values[$k], 0, 64));
            $ex = @mysqli_query($connection_server, "SELECT id FROM sas_settings WHERE vendor_id='" . (int)$vendor_id . "' AND setting_name='$esc_k' LIMIT 1");
            if ($ex && mysqli_num_rows($ex) > 0) {
                @mysqli_query($connection_server, "UPDATE sas_settings SET setting_value='$esc_v' WHERE vendor_id='" . (int)$vendor_id . "' AND setting_name='$esc_k'");
            } else {
                @mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('" . (int)$vendor_id . "', '$esc_k', '$esc_v')");
            }
        }
        return true;
    }
}

if (!function_exists('bc_ai_sentinel_auto_block')) {
    /**
     * Automatically block a flagged user account & log the Sentinel event when auto-block is enabled.
     */
    function bc_ai_sentinel_auto_block($connection_server, int $vendor_id, string $username, string $reason = '', int $trust_score = 0): bool
    {
        if (!$connection_server || $vendor_id <= 0 || trim($username) === '') return false;
        $esc_u = mysqli_real_escape_string($connection_server, trim($username));
        $esc_r = mysqli_real_escape_string($connection_server, substr($reason !== '' ? $reason : 'AI Security Sentinel auto-block', 0, 450));
        $ip    = mysqli_real_escape_string($connection_server, $_SERVER['REMOTE_ADDR'] ?? 'sentinel');

        @mysqli_query($connection_server, "UPDATE sas_users SET is_blocked=1 WHERE vendor_id='" . (int)$vendor_id . "' AND username='$esc_u'");
        @mysqli_query($connection_server,
            "INSERT INTO sas_blocked_accounts (vendor_id, username, ip_address, failed_attempts, block_until, reason)
             VALUES ('" . (int)$vendor_id . "', '$esc_u', '$ip', 1, DATE_ADD(NOW(), INTERVAL 7 DAY), '$esc_r')
             ON DUPLICATE KEY UPDATE block_until=DATE_ADD(NOW(), INTERVAL 7 DAY), reason='$esc_r'"
        );
        @mysqli_query($connection_server,
            "INSERT INTO sas_ai_audit_log (event_type, action, actor, detail, ip_address, created_at)
             VALUES ('SENTINEL_AUTO_BLOCKED', 'ai_auto_block', '" . (int)$vendor_id . ":$esc_u', 'Trust: $trust_score — $esc_r', '$ip', NOW())"
        );
        return true;
    }
}
