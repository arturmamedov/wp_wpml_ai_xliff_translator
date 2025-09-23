<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NestsHostels\XLIFFTranslation\Core\XLIFFParser;
use NestsHostels\XLIFFTranslation\Core\BrandVoiceTranslator;
use NestsHostels\XLIFFTranslation\Utils\Logger;
use NestsHostels\XLIFFTranslation\Utils\BatchProcessor;

/**
 * Batch XLIFF Translation Script
 * Usage: php bin/batch-translate.php input/folder/ [--provider=claude] [--languages=en,de,fr] [--resume=batch_id]
 */

// Parse command line arguments
$options = getopt("", [ "provider:", "languages:", "output:", "resume:", "help" ]);
$inputFolder = $argv[1] ?? null;

// Help message
if (isset($options['help']) || ! $inputFolder) {
    echo "🚀 BATCH XLIFF Translation Script - Nests Hostels\n\n";
    echo "Usage: php bin/batch-translate.php input/folder/ [options]\n\n";
    echo "Options:\n";
    echo "  --provider=<provider>      Translation provider (openai|claude) [default: claude]\n";
    echo "  --languages=<langs>        Target languages (en,de,fr,it) [default: en]\n";
    echo "  --output=<path>           Output folder [default: translated/]\n";
    echo "  --resume=<batch_id>       Resume failed batch by ID\n";
    echo "  --help                    Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/batch-translate.php input/xliff-files/\n";
    echo "  php bin/batch-translate.php input/ --provider=claude --languages=en,de,fr\n";
    echo "  php bin/batch-translate.php input/ --resume=2024-01-15_14-30-25\n\n";
    exit(0);
}

// Validate input folder
if ( ! is_dir($inputFolder)) {
    echo "❌ Error: Input folder not found: {$inputFolder}\n";
    exit(1);
}

try {
    // Load configuration
    $config = require __DIR__ . '/../config/translation-api.php';

    // Initialize batch processor
    $batchId = $options['resume'] ?? date('Y-m-d_H-i-s');
    $batchLogger = new Logger('logs', "batch-{$batchId}");
    $batchProcessor = new BatchProcessor($config, $batchLogger);

    // Configure batch settings
    $provider = $options['provider'] ?? $config['default_provider'];
    $languages = isset($options['languages'])
        ? explode(',', $options['languages'])
        : [ 'en' ]; // Default to English only
    $outputFolder = $options['output'] ?? 'translated/';

    // Check API keys
    $providerConfig = $config['providers'][$provider];
    $apiKey = getenv($providerConfig['key_env']);
    if ( ! $apiKey) {
        echo "❌ Error: API key not found: {$providerConfig['key_env']}\n";
        echo "Please set: export {$providerConfig['key_env']}=your_key_here\n";
        exit(1);
    }

    // Discovery XLIFF files
    $xliffFiles = $batchProcessor->discoverXLIFFFiles($inputFolder);

    if (empty($xliffFiles)) {
        echo "❌ No XLIFF files found in: {$inputFolder}\n";
        echo "Looking for files with extensions: .xliff, .xlf\n";
        exit(1);
    }

    // Display batch info
    echo "🚀 STARTING BATCH TRANSLATION\n";
    echo str_repeat("=", 50) . "\n";
    echo "Batch ID: {$batchId}\n";
    echo "Provider: {$provider}\n";
    echo "Input folder: {$inputFolder}\n";
    echo "Output folder: {$outputFolder}\n";
    echo "Target languages: " . implode(', ', $languages) . "\n";
    echo "Files found: " . count($xliffFiles) . "\n";
    echo "Total jobs: " . (count($xliffFiles) * count($languages)) . "\n";
    echo str_repeat("=", 50) . "\n\n";

    // Process batch
    $results = $batchProcessor->processBatch([
        'files' => $xliffFiles,
        'languages' => $languages,
        'provider' => $provider,
        'output_folder' => $outputFolder,
        'batch_id' => $batchId,
        'resume' => isset($options['resume'])
    ]);

    // Display final results
    echo "\n🎉 BATCH PROCESSING COMPLETED!\n";
    echo str_repeat("=", 50) . "\n";
    echo "Batch ID: {$batchId}\n";
    echo "Total files: " . count($xliffFiles) . "\n";
    echo "Target languages: " . count($languages) . "\n";
    echo "Successful translations: {$results['success_count']}\n";
    echo "Failed translations: {$results['failed_count']}\n";
    echo "Skipped (already exists): {$results['skipped_count']}\n";
    echo "Success rate: " . number_format($results['success_rate'] * 100, 1) . "%\n";
    echo "Total processing time: " . gmdate('H:i:s', $results['total_time']) . "\n";

    if ( ! empty($results['failed_files'])) {
        echo "\n⚠️  FAILED FILES FOR REVIEW:\n";
        foreach ($results['failed_files'] as $failed) {
            echo "  • {$failed}\n";
        }
        echo "\nRerun with: --resume={$batchId}\n";
    }

    echo "\nCheck logs/batch-{$batchId}-*.log for detailed information.\n";

} catch (Exception $e) {
    echo "❌ Batch Error: " . $e->getMessage() . "\n";
    exit(1);
}
