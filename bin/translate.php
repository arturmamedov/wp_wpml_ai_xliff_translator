<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NestsHostels\XLIFFTranslation\Core\XLIFFParser;
use NestsHostels\XLIFFTranslation\Core\BrandVoiceTranslator;
use NestsHostels\XLIFFTranslation\Utils\Logger;

/**
 * Production Translation Script with Provider Switching
 * Usage: php bin/translate.php input.xliff [--provider=claude] [--output=path]
 */

// Parse command line arguments
$options = getopt("", ["provider:", "output:", "help"]);
$inputFile = $argv[1] ?? null;

// Help message
if (isset($options['help']) || !$inputFile) {
    echo "🚀 XLIFF Translation Script - Nests Hostels Brand Voice\n\n";
    echo "Usage: php bin/translate.php input.xliff [options]\n\n";
    echo "Options:\n";
    echo "  --provider=<provider>    Translation provider (openai|claude)\n";
    echo "  --output=<path>          Output file path (default: input_translated.xliff)\n";
    echo "  --help                   Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/translate.php input.xliff\n";
    echo "  php bin/translate.php input.xliff --provider=claude\n";
    echo "  php bin/translate.php input.xliff --provider=openai --output=output/translated.xliff\n\n";
    exit(0);
}

// Validate input file
if (!file_exists($inputFile)) {
    echo "❌ Error: Input file not found: {$inputFile}\n";
    exit(1);
}

$filename = basename($inputFile);

try {
    // Load configuration
    $config = require __DIR__ . '/../config/translation-api.php';

    // Initialize logger with filename for session-specific logs
    $logger = new Logger('logs', $filename);

    echo "🚀 Starting XLIFF Translation\n";
    echo "File: {$inputFile}\n";
    echo str_repeat("=", 50) . "\n\n";

    // Initialize parser
    $parser = new XLIFFParser($logger);

    // Parse the XLIFF file
    echo "📖 Parsing XLIFF file...\n";
    $results = $parser->parseXLIFFFile($inputFile);

    // Initialize translator with config
    $translator = new BrandVoiceTranslator($config, $logger);

    // Set provider from command line or use config default
    $provider = $options['provider'] ?? $config['default_provider'];
    $translator->setProvider($provider);

    echo "🤖 Using provider: {$provider}\n";
    echo "📊 Translation units found: {$results['stats']['total_units']}\n";
    echo "  • Brand voice: {$results['stats']['brand_voice']}\n";
    echo "  • Metadata: {$results['stats']['metadata']}\n";
    echo "  • Non-translatable: {$results['stats']['non_translatable']}\n\n";

    // Check API keys
    $providerConfig = $config['providers'][$provider];
    $apiKey = getenv($providerConfig['key_env']);
    if (!$apiKey) {
        echo "❌ Error: API key not found in environment: {$providerConfig['key_env']}\n";
        echo "Please set your API key: export {$providerConfig['key_env']}=your_key_here\n";
        exit(1);
    }

    $allTranslations = [];

    // Process Brand Voice Content
    if (!empty($results['brand_voice'])) {
        echo "🎨 TRANSLATING BRAND VOICE CONTENT\n";
        echo str_repeat("-", 40) . "\n";

        $brandVoiceTranslations = $translator->translateBrandVoiceContent(
            $results['brand_voice'],
            $results['target_language']
        );

        $allTranslations = array_merge($allTranslations, $brandVoiceTranslations);
        echo "✅ Brand voice translations: " . count($brandVoiceTranslations) . "\n\n";
    }

    // Process Metadata Content
    if (!empty($results['metadata'])) {
        echo "🔍 TRANSLATING METADATA/SEO CONTENT\n";
        echo str_repeat("-", 40) . "\n";

        $metadataTranslations = [];
        $metadataUnits = $results['metadata'];
        $totalMetadata = count($metadataUnits);

        foreach ($metadataUnits as $index => $unit) {
            $unitId = $unit['id'];
            $sourceText = $unit['source'];
            $contentType = $unit['content_type'] ?? 'metadata';

            echo "🔍 Translating Metadata ({$provider}) (" . ($index + 1) . "/{$totalMetadata}): {$contentType}...\n";

            $translation = $translator->translateMetadata(
                $sourceText,
                $results['target_language'],
                $contentType
            );

            if ($translation !== $sourceText) {
                $metadataTranslations[$unitId] = $translation;
                echo "✅ Success: {$contentType}\n";
            } else {
                echo "⚠️  Fallback (kept Spanish): {$contentType}\n";
            }
        }

        $allTranslations = array_merge($allTranslations, $metadataTranslations);
        echo "✅ Metadata translations: " . count($metadataTranslations) . "\n\n";
    }

    // Process Non-Translatable Content (mark as translated but keep original)
    if (!empty($results['non_translatable'])) {
        echo "📋 PROCESSING NON-TRANSLATABLE CONTENT\n";
        echo str_repeat("-", 40) . "\n";

        $nonTranslatableTranslations = [];
        foreach ($results['non_translatable'] as $unit) {
            // IMPORTANT: Keep original Spanish content but mark XML state as 'translated'
            $nonTranslatableTranslations[$unit['id']] = $unit['source'];
        }

        $allTranslations = array_merge($allTranslations, $nonTranslatableTranslations);
        echo "✅ Non-translatable units marked as translated: " . count($nonTranslatableTranslations) . "\n\n";
    }

    // Insert all translations
    echo "💾 INSERTING TRANSLATIONS INTO XLIFF\n";
    echo str_repeat("-", 40) . "\n";
    echo "Total translations to insert: " . count($allTranslations) . "\n";

    $parser->insertTranslations($allTranslations);

    // Determine output path
    $outputPath = $options['output'] ?? null;
    if (!$outputPath) {
        $pathInfo = pathinfo($inputFile);
        $outputPath = $pathInfo['dirname'] . '/translated/' . $pathInfo['filename'] . '_translated.xliff';
    }

    // Save translated file
    if ($parser->saveToFile($outputPath)) {
        echo "✅ Translated file saved: {$outputPath}\n";
        $logger->logTranslationSuccess($results['target_language'], $outputPath);
        $logger->logFileComplete($filename);

        // Summary
        echo "\n🎉 TRANSLATION COMPLETED SUCCESSFULLY!\n";
        echo str_repeat("=", 50) . "\n";
        echo "Source: {$inputFile}\n";
        echo "Output: {$outputPath}\n";
        echo "Provider: {$provider}\n";
        echo "Language: {$results['source_language']} → {$results['target_language']}\n";
        echo "Total units processed: " . count($allTranslations) . "\n";
        echo "Duplicates handled: {$results['stats']['duplicates']}\n";
        echo "Check logs for detailed processing information.\n";

    } else {
        echo "❌ Failed to save translated file\n";
        $logger->logFileFailure("Failed to save translated file");
        exit(1);
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    if (isset($logger)) {
        $logger->logError("Script failed: " . $e->getMessage());
    }
    exit(1);
}
