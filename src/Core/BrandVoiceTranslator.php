<?php

namespace NestsHostels\XLIFFTranslation\Core;

use NestsHostels\XLIFFTranslation\Utils\Logger;

/**
 * BrandVoiceTranslator - OpenAI Integration for Nests Hostels Brand Voice
 *
 * Handles translation with specific brand voice preservation for different content types
 */
class BrandVoiceTranslator
{
    private string $apiKey;
    private Logger $logger;
    private string $baseUrl = 'https://api.openai.com/v1/chat/completions';
    private array $rateLimits = [
        'requests_per_minute' => 3,
        'last_request_time' => 0
    ];

    public function __construct(string $apiKey, Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
    }

    /**
     * Translate brand voice content units
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

            echo "Translating (" . $index + 1 . " /{$totalUnits}): {$contentType}...\n";

            try {
                // Respect rate limits
                $this->respectRateLimit();

                // Get brand voice translation
                $translation = $this->translateWithBrandVoice(
                    $sourceText,
                    $contentType,
                    $targetLanguage
                );

                $translations[$unitId] = $translation;

                $this->logger->logTranslationApplied($unitId, $sourceText, $translation);

            } catch (Exception $e) {
                $this->logger->logError("Translation failed for unit {$unitId}: " . $e->getMessage(), $unit);
                echo "❌ Failed: {$contentType}\n";
                continue;
            }
        }

        $this->logger->updateTranslationCount(count($translations));
        return $translations;
    }

    /**
     * Translate text with Nests Hostels brand voice
     */
    private function translateWithBrandVoice(string $text, string $contentType, string $targetLanguage): string
    {
        $prompt = $this->buildBrandVoicePrompt($text, $contentType, $targetLanguage);

        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->getBrandVoiceSystemPrompt($targetLanguage)
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 1000,
            'temperature' => 0.7
        ];

        $response = $this->callOpenAI($data);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid API response structure');
        }

        return trim($response['choices'][0]['message']['content']);
    }

    /**
     * Build content-specific brand voice prompt
     */
    private function buildBrandVoicePrompt(string $text, string $contentType, string $targetLanguage): string
    {
        $basePrompt = "Translate this {$contentType} from Spanish to {$targetLanguage}:\n\n\"{$text}\"\n\n";

        switch ($contentType) {
            case 'Title':
            case 'title':
                return $basePrompt . "Make it catchy and social media friendly while keeping the same meaning.";

            case 'Hostel Services':
            case 'Hostel Feature Description':
            case 'Hostel Content':
                return $basePrompt . "Maintain the casual 'group chat with travel friends' vibe. Sound excited about travel and adventures. Keep emojis and preserve HTML tags exactly.";

            case 'Hostel Services Description H4':
                return $basePrompt . "Keep it friendly but clear for booking decisions. Casual but informative.";

            case 'excerpt':
            case 'Excerpt':
                return $basePrompt . "This is a summary for travelers. Make it sound like you're recommending to friends.";

            case 'Paragraph':
                return $basePrompt . "Very casual and friendly, like texting travel buddies about this place.";

            default:
                return $basePrompt . "Keep the same casual, friendly Nests Hostels brand voice - like talking to travel friends.";
        }
    }

    /**
     * System prompt defining Nests Hostels brand voice
     */
    private function getBrandVoiceSystemPrompt(string $targetLanguage): string
    {
        return "You are a translator for Nests Hostels, a surf hostel chain in Tenerife targeting Gen-Z and Millennial travelers.

BRAND VOICE GUIDELINES:
- Casual, friendly tone like chatting with travel friends
- Enthusiastic about surf, beach life, and adventures  
- Social and welcoming atmosphere
- European Spanish influence (source language)
- Never overly formal or corporate

TECHNICAL REQUIREMENTS:
- Preserve ALL HTML tags exactly: <strong>, <br/>, <!-- comments -->
- Keep ALL WordPress shortcodes unchanged: [shortcode_name]
- Maintain all emojis and special characters
- Don't translate proper nouns: Duque Nest, Costa Adeje, Tenerife, NEST PASS
- URLs and email addresses stay unchanged

TARGET LANGUAGE SPECIFICS FOR {$targetLanguage}:
" . $this->getLanguageSpecificGuidelines($targetLanguage);
    }

    /**
     * Language-specific brand voice guidelines
     */
    private function getLanguageSpecificGuidelines(string $language): string
    {
        switch (strtolower($language)) {
            case 'en':
            case 'english':
                return "- Casual American/International English
- Use contractions (you're, we're, it's)
- Sound natural and conversational
- Beach/surf slang is welcome: 'vibes', 'chill', 'awesome'";

            case 'de':
            case 'german':
                return "- Friendly German but not overly formal
- Use 'du' form when appropriate for young travelers
- Keep English surf terms when they're commonly used
- Maintain warmth despite German structure";

            case 'fr':
            case 'french':
                return "- Warm, welcoming French
- Avoid overly formal 'vous' when context suggests casual
- Keep some English beach/surf terms if natural
- Mediterranean coastal friendly vibe";

            case 'it':
            case 'italian':
                return "- Enthusiastic, expressive Italian
- Family-friendly but trendy
- Keep beach energy and excitement
- Natural flow, not literal translation";

            default:
                return "- Maintain casual, friendly tone appropriate for young travelers
- Keep the energy and enthusiasm of the original";
        }
    }

    /**
     * Make OpenAI API call with error handling
     */
    private function callOpenAI(array $data): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
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
            $errorMsg = $errorResponse['error']['message'] ?? 'Unknown API error';
            throw new \Exception("OpenAI API error (HTTP {$httpCode}): {$errorMsg}");
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response from OpenAI API");
        }

        return $result;
    }

    /**
     * Respect OpenAI rate limits
     */
    private function respectRateLimit(): void
    {
        $currentTime = time();
        $timeSinceLastRequest = $currentTime - $this->rateLimits['last_request_time'];
        $minInterval = 60 / $this->rateLimits['requests_per_minute']; // seconds between requests

        if ($timeSinceLastRequest < $minInterval) {
            $sleepTime = $minInterval - $timeSinceLastRequest;
            echo "⏳ Rate limiting: waiting {$sleepTime}s...\n";
            sleep($sleepTime);
        }

        $this->rateLimits['last_request_time'] = time();
    }
}
