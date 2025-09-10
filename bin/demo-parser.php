<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NestsHostels\XLIFFTranslation\Core\XLIFFParser;
use NestsHostels\XLIFFTranslation\Utils\Logger;

/**
 * Demo script to test XLIFFParser functionality
 * Run: php bin/demo-parser.php path/to/your/xliff/file.xliff
 */

if ($argc < 2) {
    echo "Usage: php bin/demo-parser.php <xliff-file-path>\n";
    echo "Example: php bin/demo-parser.php input/sample.xliff\n";
    exit(1);
}

$xliffFile = $argv[1];

try {
    // Initialize logger with filename for session-specific logs
    $logger = new Logger('logs', basename($xliffFile));

    echo "🚀 Starting XLIFF Parser Demo\n";
    echo "File: {$xliffFile}\n";
    echo str_repeat("=", 50) . "\n\n";

    // Initialize parser
    $parser = new XLIFFParser($logger);

    // Parse the XLIFF file
    echo "📖 Parsing XLIFF file...\n";
    $results = $parser->parseXLIFFFile($xliffFile);

    // After parsing
    $logger->logParsingResults($results);
    $logger->logDuplicateGroupsDetailed($results['duplicates'], $parser->getUnitsByStrategy('brand_voice'));
    $logger->logContentSamplesDetailed($results);

    // Display results
    echo "\n📊 PARSING RESULTS:\n";
    echo str_repeat("-", 30) . "\n";

    printf("Total translation units: %d\n", $results['stats']['total_units']);
    printf("Brand voice content: %d\n", $results['stats']['brand_voice']);
    printf("Metadata content: %d\n", $results['stats']['metadata']);
    printf("Non-translatable content: %d\n", $results['stats']['non_translatable']);
    printf("Duplicate groups: %d\n", $results['stats']['duplicates']);
    printf("Source language: %s\n", $results['source_language']);
    printf("Target language: %s\n", $results['target_language']);

    // Show duplicates if any
    if (!empty($results['duplicates'])) {
        echo "\n🔄 DUPLICATE GROUPS:\n";
        echo str_repeat("-", 30) . "\n";

        foreach ($results['duplicates'] as $originalId => $duplicateIds) {
            $originalContent = substr($results['brand_voice'][0]['source'] ?? 'N/A', 0, 60);
            printf("Group %s (%d duplicates): %s...\n",
                $originalId,
                count($duplicateIds),
                $originalContent
            );
        }
    }

    // Show sample content by strategy
    echo "\n📝 SAMPLE CONTENT BY STRATEGY:\n";
    echo str_repeat("-", 40) . "\n";

    foreach (['brand_voice', 'metadata', 'non_translatable'] as $strategy) {
        if (!empty($results[$strategy])) {
            echo "\n🎯 {$strategy} (showing first 3):\n";

            $samples = array_slice($results[$strategy], 0, 3);
            foreach ($samples as $i => $unit) {
                $content = substr($unit['source'], 0, 80);
                printf("  %d. [%s] %s: %s%s\n",
                    $i + 1,
                    $unit['id'],
                    $unit['content_type'],
                    $content,
                    strlen($unit['source']) > 80 ? '...' : ''
                );
            }
        }
    }

    // Demonstrate translation insertion (mock)
    echo "\n🔄 DEMONSTRATING TRANSLATION INSERTION:\n";
    echo str_repeat("-", 40) . "\n";

    // Create mock translations for brand voice content
    $mockTranslations = [];
    $brandVoiceUnits = array_slice($results['brand_voice'], 0, 2);

    foreach ($brandVoiceUnits as $unit) {
        // Mock translation - just add [TRANSLATED] prefix
        $mockTranslations[$unit['id']] = "[TRANSLATED] " . $unit['source'];
    }

    // Before translation
    if (!empty($mockTranslations)) {
        $logger->logTranslationStart(basename($xliffFile), $results['target_language']);

        echo "Inserting mock translations for " . count($mockTranslations) . " units...\n";

        // Update translation count
        $filename = basename($xliffFile);
        if (isset($logger->getSessionStats()[$filename])) {
            $logger->updateTranslationCount($filename, count($mockTranslations));
        }

        $parser->insertTranslations($mockTranslations);

        // Save and log completion
        $outputPath = dirname($xliffFile) . '/translated/' . basename($xliffFile);

        if ($parser->saveToFile($outputPath)) {
            echo "✅ Demo translated file saved to: {$outputPath}\n";
            $logger->logTranslationSuccess($filename, $results['target_language'], $outputPath);
            $logger->logFileComplete(basename($xliffFile));
        } else {
            echo "❌ Failed to save demo file\n";
            $logger->logFileFailure(basename($xliffFile), "Failed to save translated file");
        }
    }

    echo "\n✅ Demo completed successfully!\n";
    echo "Check the logs/ directory for detailed processing logs.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
