<?php

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Sync Glossary CSV to PHP Config
 * Usage: php bin/sync-glossary-csv.php glossary_items.csv [--dry-run]
 */

$options = getopt("", ["dry-run", "help"]);
$csvFile = $argv[1] ?? null;

if (isset($options['help']) || !$csvFile) {
    echo "📄 CSV Glossary Sync Tool\n\n";
    echo "Usage: php bin/sync-glossary-csv.php glossary_items.csv [--dry-run]\n\n";
    echo "Options:\n";
    echo "  --dry-run    Show changes without updating config file\n";
    echo "  --help       Show this help message\n\n";
    echo "This tool reads your CSV glossary and updates config/glossary.php\n";
    echo "CSV should have columns: original_term, translated_term, kind\n\n";
    exit(0);
}

if (!file_exists($csvFile)) {
    echo "❌ CSV file not found: {$csvFile}\n";
    exit(1);
}

try {
    echo "📄 READING CSV GLOSSARY\n";
    echo str_repeat("=", 40) . "\n";

    // Read CSV content
    $csvContent = file_get_contents($csvFile);

    // Simple CSV parsing (assuming comma-separated)
    $lines = array_map('str_getcsv', explode("\n", trim($csvContent)));
    $header = array_shift($lines);

    // Find column indices
    $originalCol = array_search('original_term', $header);
    $translatedCol = array_search('translated_term', $header);
    $kindCol = array_search('kind', $header);

    if ($originalCol === false || $translatedCol === false) {
        throw new Exception("CSV must have 'original_term' and 'translated_term' columns");
    }

    // Parse glossary terms by category
    $glossaryByCategory = [];
    $totalTerms = 0;

    foreach ($lines as $line) {
        if (empty($line) || count($line) < 2) continue;

        $original = trim($line[$originalCol]);
        $translated = trim($line[$translatedCol]);
        $kind = $kindCol !== false ? trim($line[$kindCol]) : 'general';

        if (empty($original)) continue;

        // Group by kind/category
        if (!isset($glossaryByCategory[$kind])) {
            $glossaryByCategory[$kind] = [];
        }

        $glossaryByCategory[$kind][$original] = $translated;
        $totalTerms++;
    }

    echo "Found {$totalTerms} terms in " . count($glossaryByCategory) . " categories:\n";

    foreach ($glossaryByCategory as $category => $terms) {
        echo "  • {$category}: " . count($terms) . " terms\n";

        // Show first few examples
        $examples = array_slice(array_keys($terms), 0, 3);
        echo "    Examples: " . implode(', ', $examples) . "\n";
    }

    echo "\n";

    // Generate PHP array structure
    $phpConfig = "<?php\n\nreturn [\n";

    foreach ($glossaryByCategory as $category => $terms) {
        // Convert category name to valid PHP key
        $categoryKey = str_replace(' ', '_', strtolower($category)) . '_terms';

        $phpConfig .= "    '{$categoryKey}' => [\n";

        // Sort terms alphabetically for consistency
        ksort($terms);

        foreach ($terms as $original => $translated) {
            $phpConfig .= "        " . var_export($original, true) . " => " . var_export($translated, true) . ",\n";
        }

        $phpConfig .= "    ],\n\n";
    }

    $phpConfig .= "];\n";

    if (isset($options['dry-run'])) {
        echo "🔍 DRY RUN - Generated config/glossary.php content:\n";
        echo str_repeat("-", 50) . "\n";
        echo $phpConfig;
        echo str_repeat("-", 50) . "\n";
        echo "Use without --dry-run to update the actual file.\n";
    } else {
        // Backup existing file
        $configFile = __DIR__ . '/../config/glossary.php';
        if (file_exists($configFile)) {
            $backupFile = $configFile . '.backup.' . date('Y-m-d_H-i-s');
            copy($configFile, $backupFile);
            echo "📂 Backed up existing glossary to: " . basename($backupFile) . "\n";
        }

        // Write new config
        file_put_contents($configFile, $phpConfig);
        echo "✅ Updated config/glossary.php with {$totalTerms} terms\n";

        // Validate the new file
        try {
            $testLoad = require $configFile;
            echo "✅ New config file syntax is valid\n";

            $totalLoaded = array_sum(array_map('count', $testLoad));
            echo "✅ Loaded {$totalLoaded} terms successfully\n";

        } catch (Exception $e) {
            echo "❌ Error in generated config: " . $e->getMessage() . "\n";

            // Restore backup if it exists
            if (isset($backupFile)) {
                copy($backupFile, $configFile);
                echo "🔄 Restored backup file\n";
            }
        }
    }

    echo "\n🎉 Glossary sync completed!\n";
    echo "Run: php bin/test-glossary.php to test the updated glossary\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
