<?php

namespace NestsHostels\XLIFFTranslation\Core;

use NestsHostels\XLIFFTranslation\Utils\Logger;

/**
 * BrandVoiceTranslator - Multi-provider translation with Nests Hostels brand voice
 *
 * Supports OpenAI and Claude APIs with configurable prompts and provider switching
 */
class BrandVoiceTranslator
{
    private array $config;
    private Logger $logger;
    private string $currentProvider;
    private array $rateLimits = ['last_request_time' => 0];

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->currentProvider = $config['default_provider'];
    }

    /**
     * Set provider at runtime (--provider=claude)
     */
    public function setProvider(string $provider): void
    {
        $availableProviders = array_keys($this->config['providers']);

        if (!in_array($provider, $availableProviders)) {
            throw new \InvalidArgumentException("Provider '{$provider}' not available. Available: " . implode(', ', $availableProviders));
        }

        $this->currentProvider = $provider;
        $this->logger->logError("Provider switched to: {$provider}", null);
    }

    /**
     * Get available providers
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->config['providers']);
    }

    /**
     * Translate single brand voice content unit
     */
    public function translateBrandVoice(string $text, string $targetLanguage, string $context = ''): string
    {
        try {
            $this->respectRateLimit();

            $systemPrompt = $this->config['prompts']['system'];
            $userPrompt = $this->buildBrandVoicePrompt($text, $targetLanguage, $context);

            $translation = $this->handleApiCall($systemPrompt, $userPrompt);

            $this->logger->logTranslationApplied('single-unit', $text, $translation);
            return $translation;

        } catch (\Exception $e) {
            return $this->handleTranslationFailure($e, $text, [
                'type' => 'brand_voice',
                'target_lang' => $targetLanguage,
                'context' => $context
            ]);
        }
    }

    /**
     * Translate single metadata/SEO content unit
     */
    public function translateMetadata(string $text, string $targetLanguage, string $seoType = 'general'): string
    {
        try {
            $this->respectRateLimit();

            $systemPrompt = $this->config['prompts']['system'];
            $userPrompt = $this->buildMetadataPrompt($text, $targetLanguage, $seoType);

            $translation = $this->handleApiCall($systemPrompt, $userPrompt);

            $this->logger->logTranslationApplied('metadata-unit', $text, $translation);
            return $translation;

        } catch (\Exception $e) {
            return $this->handleTranslationFailure($e, $text, [
                'type' => 'metadata',
                'target_lang' => $targetLanguage,
                'seo_type' => $seoType
            ]);
        }
    }

    /**
     * Batch translate brand voice content
     */
    public function translateBrandVoiceContent(array $units, string $targetLanguage): array
    {
        $translations = [];
        $totalUnits = count($units);

        $this->logger->logTranslationStart($targetLanguage);

        foreach ($units as $index => $unit) {
            $unitId = $unit['id'];
            $sourceText = $unit['source'];
            $contentType = $unit['content_type'] ?? 'General';
            $context = $unit['purpose'] ?? '';

            echo "🔄 Translating Brand Voice ({$this->currentProvider}) (" . ($index + 1) . "/{$totalUnits}): {$contentType}...\n";

            $translation = $this->translateBrandVoice($sourceText, $targetLanguage, $context);

            if ($translation !== $sourceText) { // Only add if successfully translated
                $translations[$unitId] = $translation;
                echo "✅ Success: {$contentType}\n";
            } else {
                echo "⚠️  Fallback (kept Spanish): {$contentType}\n";
            }
        }

        $this->logger->updateTranslationCount(count($translations));
        return $translations;
    }

    /**
     * Route API call to correct provider
     */
    private function handleApiCall(string $systemPrompt, string $userPrompt): string
    {
        switch ($this->currentProvider) {
            case 'openai':
                return $this->callOpenAI($systemPrompt, $userPrompt);
            case 'claude':
                return $this->callClaude($systemPrompt, $userPrompt);
            default:
                throw new \Exception("Unsupported provider: {$this->currentProvider}");
        }
    }

    /**
     * Build brand voice prompt using config templates
     */
    private function buildBrandVoicePrompt(string $text, string $targetLanguage, string $context): string
    {
        $langKey = $this->config['language_mapping'][strtolower($targetLanguage)] ?? 'english';
        $template = $this->config['prompts']['brand_voice_user'][$langKey];

        $basePrompt = str_replace(
            ['{TEXT}', '{CONTEXT}'],
            [$text, $context ?: 'General content'],
            $template
        );

        return $this->wrapPrompt($basePrompt);
    }

    /**
     * Build metadata/SEO prompt using config templates
     */
    private function buildMetadataPrompt(string $text, string $targetLanguage, string $seoType): string
    {
        $langKey = $this->config['language_mapping'][strtolower($targetLanguage)] ?? 'english';
        $template = $this->config['prompts']['metadata_user'][$langKey];

        $basePrompt = str_replace(
            ['{TEXT}', '{SEO_TYPE}', '{CONTEXT}'],
            [$text, $seoType, 'SEO/Metadata content'],
            $template
        );

        return $this->wrapPrompt($basePrompt);
    }

    private function wrapPrompt(string $basePrompt): string
    {
        return $basePrompt . "\n\nReturn only the translation text, no explanations and no other versions.";
    }

    /**
     * OpenAI API call
     */
    private function callOpenAI(string $systemPrompt, string $userPrompt): string
    {
        $providerConfig = $this->config['providers']['openai'];
        $apiKey = getenv($providerConfig['key_env']);

        if (!$apiKey) {
            throw new \Exception("OpenAI API key not found in environment: {$providerConfig['key_env']}");
        }

        $data = [
            'model' => $providerConfig['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'max_tokens' => $providerConfig['max_tokens'],
            'temperature' => $providerConfig['temperature']
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        $response = $this->makeCurlRequest($providerConfig['endpoint'], $data, $headers);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid OpenAI API response structure');
        }

        return trim($response['choices'][0]['message']['content']);
    }

    /**
     * Claude API call
     */
    private function callClaude(string $systemPrompt, string $userPrompt): string
    {
        $providerConfig = $this->config['providers']['claude'];
        $apiKey = getenv($providerConfig['key_env']);

        if (!$apiKey) {
            throw new \Exception("Claude API key not found in environment: {$providerConfig['key_env']}");
        }

        $data = [
            'model' => $providerConfig['model'],
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'max_tokens' => $providerConfig['max_tokens']
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ];

        $response = $this->makeCurlRequest($providerConfig['endpoint'], $data, $headers);

        if (!isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid Claude API response structure');
        }

        // Trust the system prompt to handle verbosity properly and not add TRANSLATED VERSION: Wake up... [explanations] WHY THIS WORKS: [reasoning]
        return trim($response['content'][0]['text']);
    }

    /**
     * Generic cURL request handler
     */
    private function makeCurlRequest(string $endpoint, array $data, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->config['timeout_seconds']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            $errorResponse = json_decode($response, true);
            $errorMsg = $errorResponse['error']['message'] ?? $errorResponse['error']['type'] ?? 'Unknown API error';
            throw new \Exception("API error (HTTP {$httpCode}): {$errorMsg}");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response from API");
        }

        return $result;
    }

    /**
     * Handle translation failure with comprehensive logging
     */
    private function handleTranslationFailure(\Exception $e, string $originalText, array $context): string
    {
        $this->logger->logError("Translation failed ({$this->currentProvider})", [
            'provider' => $this->currentProvider,
            'error' => $e->getMessage(),
            'original_text' => substr($originalText, 0, 100),
            'target_language' => $context['target_lang'] ?? 'unknown',
            'content_type' => $context['type'] ?? 'unknown',
            'context' => $context
        ]);

        // Fallback to original Spanish text
        return $originalText;
    }

    /**
     * Respect rate limits
     */
    private function respectRateLimit(): void
    {
        $currentTime = time();
        $timeSinceLastRequest = $currentTime - $this->rateLimits['last_request_time'];
        $minInterval = 60 / $this->config['rate_limit_rpm']; // seconds between requests

        if ($timeSinceLastRequest < $minInterval) {
            $sleepTime = ceil($minInterval - $timeSinceLastRequest);
            echo "⏳ Rate limiting ({$this->currentProvider}): waiting {$sleepTime}s...\n";
            sleep($sleepTime);
        }

        $this->rateLimits['last_request_time'] = time();
    }
}
