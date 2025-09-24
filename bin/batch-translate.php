<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NestsHostels\XLIFFTranslation\Core\XLIFFParser;
use NestsHostels\XLIFFTranslation\Core\BrandVoiceTranslator;
use NestsHostels\XLIFFTranslation\Utils\Logger;
use NestsHostels\XLIFFTranslation\Utils\BatchProcessor;

/**
 * Batch XLIFF Translation Script - Auto-detects target language from each file
 * Usage: php bin/batch-translate.php input/folder/ [--provider=claude] [--output=path] [--resume=batch_id]
 */

// Parse command line arguments
$options = getopt("", [ "provider:", "output:", "resume:", "help" ]);
$inputFolder = $argv[1] ?? 'input/';

// Help message
if (isset($options['help']) || ! $inputFolder) {
    echo "🚀 BATCH XLIFF Translation Script - Nests Hostels\n\n";
    echo "Usage: php bin/batch-translate.php input/folder/ [options]\n\n";
    echo "This script automatically detects the target language from each XLIFF file\n";
    echo "and translates it to that specific language (no need to specify languages).\n\n";
    echo "Options:\n";
    echo "  --provider=<provider>      Translation provider (openai|claude) [default: openai]\n";
    echo "  --output=<path>           Output folder [default: translated/]\n";
    echo "  --resume=<batch_id>       Resume failed batch by ID\n";
    echo "  --help                    Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/batch-translate.php input/xliff-files/\n";
    echo "  php bin/batch-translate.php input/ --provider=openai\n";
    echo "  php bin/batch-translate.php input/ --resume=2024-01-15_14-30-25\n\n";
    echo "XLIFF File Processing:\n";
    echo "  • Each .xliff file contains its target language (es→en, es→de, etc.)\n";
    echo "  • Script automatically detects and processes to correct language\n";
    echo "  • No language conflicts - each file processed exactly once\n\n";
    echo "Output Structure (configurable in config/batch-settings.php):\n";
    echo "  translated/en/    - English translations\n";
    echo "  translated/de/    - German translations  \n";
    echo "  translated/fr/    - French translations\n";
    echo "  translated/it/    - Italian translations\n\n";
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
    $batchConfig = require __DIR__ . '/../config/batch-settings.php';

    // Initialize batch processor
    $batchId = $options['resume'] ?? date('Y-m-d_H-i-s');
    $batchLogger = new Logger('logs', "batch-{$batchId}");
    $batchProcessor = new BatchProcessor($config, $batchLogger);

    // Configure batch settings
    $provider = $options['provider'] ?? $config['default_provider'];
    $outputFolder = $options['output'] ?? $batchConfig['default_output_folder'];

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
    echo "Files found: " . count($xliffFiles) . "\n";
    echo "Processing: Each file to its designated target language\n";
    echo str_repeat("=", 50) . "\n\n";

    // Process batch
    $results = $batchProcessor->processBatch([
        'files' => $xliffFiles,
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
    echo "Successful translations: {$results['success_count']}\n";
    echo "Failed translations: {$results['failed_count']}\n";
    echo "Skipped (already exists): {$results['skipped_count']}\n";
    echo "Success rate: " . number_format($results['success_rate'] * 100, 1) . "%\n";
    echo "Total processing time: " . gmdate('H:i:s', $results['total_time']) . "\n";

    // Show duplicate optimization results
    if ( ! empty($results['duplicate_savings'])) {
        $savings = $results['duplicate_savings'];
        $totalSaved = $savings['total_duplicates_saved'];
        $apiCallsMade = $savings['total_api_calls_made'];
        $apiCallsWouldHave = $savings['total_api_calls_would_have_made'];

        if ($totalSaved > 0) {
            $savingsPercent = $apiCallsWouldHave > 0 ? ($totalSaved / $apiCallsWouldHave) * 100 : 0;
            echo "\n💰 DUPLICATE OPTIMIZATION RESULTS:\n";
            echo "API calls made: {$apiCallsMade}\n";
            echo "API calls saved: {$totalSaved}\n";
            echo "Total savings: " . number_format($savingsPercent, 1) . "%\n";
        }
    }

    // Show language breakdown
    if ( ! empty($results['language_breakdown'])) {
        echo "\nLanguage breakdown:\n";
        foreach ($results['language_breakdown'] as $lang => $count) {
            echo "  • {$lang}: {$count} files\n";
        }
    }

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
