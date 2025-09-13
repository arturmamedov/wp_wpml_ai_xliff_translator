<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NestsHostels\XLIFFTranslation\Core\BrandVoiceTranslator;
use NestsHostels\XLIFFTranslation\Utils\Logger;

/**
 * Test Script for Both Translation Providers
 * Usage: php bin/test-providers.php [--provider=claude|openai]
 */

$options = getopt("", ["provider:", "help"]);

if (isset($options['help'])) {
    echo "🧪 Provider Testing Script\n\n";
    echo "Usage: php bin/test-providers.php [--provider=provider]\n\n";
    echo "Options:\n";
    echo "  --provider=<provider>    Test specific provider (openai|claude|both)\n";
    echo "  --help                   Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/test-providers.php                # Test default provider\n";
    echo "  php bin/test-providers.php --provider=both  # Test both providers\n";
    echo "  php bin/test-providers.php --provider=claude\n\n";
    exit(0);
}

try {
    // Load configuration
    $config = require __DIR__ . '/../config/translation-api.php';
    $logger = new Logger('logs', 'provider-test');

    // Test content samples
    $testContent = [
        'brand_voice' => [
            'spanish' => '¡Despiértate con vistas al océano y café fresco! 🌊 Tu próxima aventura comienza aquí. ¿Quién está listo para surfear?',
            'english' => 'Wake up to ocean views and fresh coffee! 🌊 Your next adventure starts here. Who\'s ready to catch some waves?',
        ],
        'metadata' => [
            'spanish' => 'Hostal surf Costa Adeje Tenerife - habitaciones económicas cerca playa',
            'english' => 'Surf hostel Costa Adeje Tenerife - budget rooms near beach',
        ]
    ];

    $translator = new BrandVoiceTranslator($config, $logger);
    $testProvider = $options['provider'] ?? $config['default_provider'];

    if ($testProvider === 'both') {
        $providersToTest = $translator->getAvailableProviders();
    } else {
        $providersToTest = [$testProvider];
    }

    echo "🧪 TESTING TRANSLATION PROVIDERS\n";
    echo str_repeat("=", 50) . "\n\n";

    foreach ($providersToTest as $provider) {
        echo "🤖 TESTING PROVIDER: " . strtoupper($provider) . "\n";
        echo str_repeat("-", 30) . "\n";

        // Check API key
        $providerConfig = $config['providers'][$provider];
        $apiKey = getenv($providerConfig['key_env']);

        if (!$apiKey) {
            echo "❌ API key not found: {$providerConfig['key_env']}\n";
            echo "   Set it with: export {$providerConfig['key_env']}=your_key_here\n\n";
            continue;
        }

        $translator->setProvider($provider);

        // Test Brand Voice Translation
        echo "🎨 Testing Brand Voice Translation (Spanish → English):\n";
        echo "Original: {$testContent['brand_voice']['spanish']}\n";

        try {
            $translation = $translator->translateBrandVoice(
                $testContent['brand_voice']['spanish'],
                'en',
                'Social media caption'
            );
            echo "✅ Translation: {$translation}\n";
        } catch (Exception $e) {
            echo "❌ Brand Voice Failed: " . $e->getMessage() . "\n";
        }

        echo "\n";

        // Test Metadata Translation
        echo "🔍 Testing Metadata Translation (Spanish → English):\n";
        echo "Original: {$testContent['metadata']['spanish']}\n";

        try {
            $translation = $translator->translateMetadata(
                $testContent['metadata']['spanish'],
                'en',
                'Meta Description'
            );
            echo "✅ Translation: {$translation}\n";
        } catch (Exception $e) {
            echo "❌ Metadata Failed: " . $e->getMessage() . "\n";
        }

        echo "\n" . str_repeat("=", 50) . "\n\n";
    }

    echo "✅ Provider testing completed!\n";
    echo "Check logs/provider-test-*.log for detailed information.\n";

} catch (Exception $e) {
    echo "❌ Test Error: " . $e->getMessage() . "\n";
    exit(1);
}
