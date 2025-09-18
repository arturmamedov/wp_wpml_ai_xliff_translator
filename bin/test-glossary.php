<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NestsHostels\XLIFFTranslation\Core\BrandVoiceTranslator;
use NestsHostels\XLIFFTranslation\Utils\Logger;

/**
 * Test Script for Glossary Brand Term Protection
 * Usage: php bin/test-glossary.php [--provider=claude|openai] [--lang=en|de|fr|it]
 */

$options = getopt("", ["provider:", "lang:", "help"]);

if (isset($options['help'])) {
    echo "🔒 Glossary Protection Test Script\n\n";
    echo "Usage: php bin/test-glossary.php [--provider=provider] [--lang=language]\n\n";
    echo "Options:\n";
    echo "  --provider=<provider>    Translation provider (openai|claude)\n";
    echo "  --lang=<language>        Target language (en|de|fr|it)\n";
    echo "  --help                   Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/test-glossary.php --provider=claude --lang=en\n";
    echo "  php bin/test-glossary.php --provider=openai --lang=de\n\n";
    exit(0);
}

try {
    // Load configuration
    $config = require __DIR__ . '/../config/translation-api.php';
    $logger = new Logger('logs', 'glossary-test');

    // Test content with brand terms that should NOT be translated
    $testContent = [
        'brand_voice_with_terms' => [
            'spanish' => '¡Bienvenido a Nests Hostels! Reserva tu habitación en Duque Nest, ubicado en Costa Adeje, Tenerife. Disfruta de nuestro NEST PASS y conecta con otros viajeros.',
            'expected_protected_terms' => ['Nests Hostels', 'Duque Nest', 'Costa Adeje', 'Tenerife', 'NEST PASS']
        ],
        'location_heavy_content' => [
            'spanish' => 'Situado en el corazón de Playa del Duque, nuestro Las Eras Nest ofrece vistas espectaculares de Tenerife. Cerca de Santa Cruz de Tenerife.',
            'expected_protected_terms' => ['Playa del Duque', 'Las Eras Nest', 'Tenerife', 'Santa Cruz de Tenerife']
        ],
        'contact_info' => [
            'spanish' => 'Contáctanos en duquenesthostel@gmail.com o llama al +34 655 01 20 55. Visita nestshostels.cloudbeds.com para reservas.',
            'expected_protected_terms' => ['duquenesthostel@gmail.com', '+34 655 01 20 55', 'nestshostels.cloudbeds.com']
        ],
        'mixed_content' => [
            'spanish' => 'El Medano Nest en Las Eras está cerca de Instagram y TripAdvisor nos califican como excelente. WordPress facilita las reservas.',
            'expected_protected_terms' => ['Medano Nest', 'Las Eras', 'Instagram', 'TripAdvisor', 'WordPress']
        ]
    ];

    $translator = new BrandVoiceTranslator($config, $logger);
    $testProvider = $options['provider'] ?? $config['default_provider'];
    $targetLang = $options['lang'] ?? 'en';

    $translator->setProvider($testProvider);

    // Load glossary for validation
    $glossary = require __DIR__ . '/../config/glossary.php';
    $allGlossaryTerms = array_merge(...array_values($glossary));

    echo "🔒 GLOSSARY PROTECTION TEST\n";
    echo str_repeat("=", 60) . "\n\n";
    echo "Provider: " . strtoupper($testProvider) . "\n";
    echo "Target Language: " . strtoupper($targetLang) . "\n";
    echo "Total Glossary Terms: " . count($allGlossaryTerms) . "\n\n";

    // Check API key
    $providerConfig = $config['providers'][$testProvider];
    $apiKey = getenv($providerConfig['key_env']);

    if (!$apiKey) {
        echo "❌ API key not found: {$providerConfig['key_env']}\n";
        echo "   Set it with: export {$providerConfig['key_env']}=your_key_here\n\n";
        exit(1);
    }

    $overallResults = [
        'tests_passed' => 0,
        'tests_failed' => 0,
        'terms_protected' => 0,
        'terms_leaked' => 0
    ];

    foreach ($testContent as $testName => $testData) {
        echo "🧪 TEST: " . strtoupper($testName) . "\n";
        echo str_repeat("-", 40) . "\n";
        echo "Original: {$testData['spanish']}\n\n";

        try {
            // Perform translation
            $translation = $translator->translateBrandVoice(
                $testData['spanish'],
                $targetLang,
                $testName
            );

            echo "Translation: {$translation}\n\n";

            // Validate protection of expected terms
            $protectedCount = 0;
            $leakedCount = 0;
            $leakedTerms = [];

            foreach ($testData['expected_protected_terms'] as $protectedTerm) {
                $originalCount = substr_count(strtolower($testData['spanish']), strtolower($protectedTerm));
                $translationCount = substr_count(strtolower($translation), strtolower($protectedTerm));

                if ($originalCount > 0 && $translationCount >= $originalCount) {
                    $protectedCount++;
                    echo "✅ Protected: '{$protectedTerm}'\n";
                } else {
                    $leakedCount++;
                    $leakedTerms[] = $protectedTerm;
                    echo "❌ LEAKED: '{$protectedTerm}' (original: {$originalCount}, translated: {$translationCount})\n";
                }
            }

            // Additional check: Look for any glossary terms that might have been translated incorrectly
            $additionalLeaks = [];
            foreach ($allGlossaryTerms as $term => $keepAs) {
                if (stripos($testData['spanish'], $term) !== false) {
                    if (stripos($translation, $term) === false && stripos($translation, $keepAs) === false) {
                        // Term was translated when it should have been protected
                        $additionalLeaks[] = $term;
                    }
                }
            }

            foreach ($additionalLeaks as $leak) {
                echo "⚠️  Additional leak detected: '{$leak}'\n";
                $leakedCount++;
                $leakedTerms[] = $leak;
            }

            echo "\n";
            echo "📊 Results: {$protectedCount} protected, {$leakedCount} leaked\n";

            if ($leakedCount === 0) {
                echo "🎉 TEST PASSED - All brand terms protected!\n";
                $overallResults['tests_passed']++;
            } else {
                echo "❌ TEST FAILED - " . count($leakedTerms) . " terms leaked: " . implode(', ', $leakedTerms) . "\n";
                $overallResults['tests_failed']++;
            }

            $overallResults['terms_protected'] += $protectedCount;
            $overallResults['terms_leaked'] += $leakedCount;

        } catch (Exception $e) {
            echo "❌ Translation Error: " . $e->getMessage() . "\n";
            $overallResults['tests_failed']++;
        }

        echo "\n" . str_repeat("=", 60) . "\n\n";
    }

    // Overall results summary
    echo "🏆 OVERALL TEST RESULTS\n";
    echo str_repeat("=", 30) . "\n";
    echo "Tests Passed: {$overallResults['tests_passed']}\n";
    echo "Tests Failed: {$overallResults['tests_failed']}\n";
    echo "Terms Protected: {$overallResults['terms_protected']}\n";
    echo "Terms Leaked: {$overallResults['terms_leaked']}\n";

    $protectionRate = $overallResults['terms_protected'] + $overallResults['terms_leaked'] > 0
        ? round(($overallResults['terms_protected'] / ($overallResults['terms_protected'] + $overallResults['terms_leaked'])) * 100, 1)
        : 0;

    echo "Protection Rate: {$protectionRate}%\n\n";

    if ($overallResults['tests_failed'] === 0) {
        echo "🎉 ALL TESTS PASSED - Glossary system working perfectly!\n";
    } else {
        echo "⚠️  Some tests failed. Check the specific results above.\n";
        echo "Consider:\n";
        echo "  1. Adjusting prompt templates for better brand term recognition\n";
        echo "  2. Adding problematic terms to the glossary\n";
        echo "  3. Improving post-translation validation rules\n";
    }

    echo "\n✅ Glossary testing completed!\n";
    echo "Check logs/glossary-test-*.log for detailed information.\n";

} catch (Exception $e) {
    echo "❌ Test Error: " . $e->getMessage() . "\n";
    exit(1);
}
