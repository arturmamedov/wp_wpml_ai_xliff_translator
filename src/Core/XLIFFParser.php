<?php

namespace NestsHostels\XLIFFTranslation\Core;

use DOMDocument;
use DOMXPath;
use NestsHostels\XLIFFTranslation\Utils\Logger;

/**
 * XLIFFParser - Core class for parsing and processing XLIFF files
 *
 * Handles WPML XLIFF files with:
 * - Duplicate detection and management
 * - Content type classification
 * - Non-translatable rules application
 * - Structure preservation for WPML import
 */
class XLIFFParser
{
    private DOMDocument $dom;
    private DOMXPath $xpath;
    private Logger $logger;
    private array $contentTypes;
    private array $nonTranslatableRules;
    private array $translationUnits = [];
    private array $duplicateMap = [];
    private string $sourceLanguage;
    private string $targetLanguage;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->contentTypes = require __DIR__ . '/../../config/content-types.php';
        $this->nonTranslatableRules = require __DIR__ . '/../../config/non-translatable-rules.php';

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = true;
        $this->dom->formatOutput = false; // Preserve original formatting
    }

    /**
     * Parse XLIFF file and extract translation units
     */
    public function parseXLIFFFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("XLIFF file not found: {$filePath}");
        }

        $this->logger->logFileStart(basename($filePath));

        // Load and validate XLIFF
        if (!$this->dom->load($filePath)) {
            throw new \Exception("Failed to load XLIFF file: {$filePath}");
        }

        $this->setupXPath();
        $this->extractLanguages();
        $this->extractTranslationUnits();
        $this->detectDuplicates();
        $this->classifyUnits();
        $this->applyNonTranslatableRules();

        $this->logger->logUnitsFound(basename($filePath), count($this->translationUnits));

        return $this->getProcessingResults();
    }

    /**
     * Setup XPath with XLIFF namespace
     */
    private function setupXPath(): void
    {
        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('xliff', 'urn:oasis:names:tc:xliff:document:1.2');
    }

    /**
     * Extract source and target languages from XLIFF header
     */
    private function extractLanguages(): void
    {
        $fileNode = $this->xpath->query('//xliff:file')->item(0);

        if (!$fileNode) {
            throw new \Exception('Invalid XLIFF structure: No file element found');
        }

        $this->sourceLanguage = $fileNode->getAttribute('source-language') ?: 'es';
        $this->targetLanguage = $fileNode->getAttribute('target-language') ?: 'en';
    }

    /**
     * Extract all translation units with their metadata
     */
    private function extractTranslationUnits(): void
    {
        $transUnits = $this->xpath->query('//xliff:trans-unit');

        foreach ($transUnits as $unit) {
            $unitId = $unit->getAttribute('id');
            $sourceNode = $this->xpath->query('.//xliff:source', $unit)->item(0);

            if (!$sourceNode) {
                $this->logger->logError(basename('current'), "No source found for unit: {$unitId}");
                continue;
            }

            // Extract source content (handle CDATA)
            $sourceContent = $this->extractTextContent($sourceNode);

            // Skip empty content
            if (trim($sourceContent) === '') {
                continue;
            }

            // Extract metadata from extradata
            $extradata = $this->extractExtraData($unit);

            $this->translationUnits[$unitId] = [
                'id' => $unitId,
                'source' => $sourceContent,
                'target' => '', // Will be filled by translator
                'extradata' => $extradata,
                'content_type' => null, // Will be classified
                'translation_strategy' => null, // brand_voice, metadata, or non_translatable
                'is_duplicate' => false,
                'duplicate_group' => null,
                'dom_node' => $unit, // Keep reference for reconstruction
                'original_structure' => $unit->ownerDocument->saveXML($unit)
            ];
        }
    }

    /**
     * Extract text content handling CDATA sections
     */
    private function extractTextContent(\DOMNode $node): string
    {
        $content = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_CDATA_SECTION_NODE) {
                $content .= $child->data;
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $content .= $child->textContent;
            } else {
                // Handle nested elements (preserve HTML)
                $content .= $node->ownerDocument->saveXML($child);
            }
        }

        return trim($content);
    }

    /**
     * Extract extradata metadata for content classification
     */
    private function extractExtraData(\DOMElement $unit): array
    {
        $extradata = [];
        $extradataNodes = $this->xpath->query('.//extradata', $unit);

        foreach ($extradataNodes as $node) {
            $key = $node->getAttribute('key');
            $value = $node->textContent;
            $extradata[$key] = $value;
        }

        return $extradata;
    }

    /**
     * Detect and group duplicate content for batch translation
     */
    private function detectDuplicates(): void
    {
        $contentHash = [];
        $duplicateGroups = [];
        $duplicateCount = 0;

        foreach ($this->translationUnits as $unitId => &$unit) {
            $hash = md5(trim($unit['source']));

            if (isset($contentHash[$hash])) {
                // This is a duplicate
                $originalId = $contentHash[$hash];

                if (!isset($duplicateGroups[$originalId])) {
                    $duplicateGroups[$originalId] = [$originalId];
                    $this->translationUnits[$originalId]['is_duplicate'] = true;
                    $this->translationUnits[$originalId]['duplicate_group'] = $originalId;
                }

                $duplicateGroups[$originalId][] = $unitId;
                $unit['is_duplicate'] = true;
                $unit['duplicate_group'] = $originalId;
                $duplicateCount++;

            } else {
                $contentHash[$hash] = $unitId;
            }
        }

        $this->duplicateMap = $duplicateGroups;

        if ($duplicateCount > 0) {
            $examples = array_slice(array_keys($duplicateGroups), 0, 3);
            $exampleTexts = array_map(function($id) {
                return substr($this->translationUnits[$id]['source'], 0, 50) . '...';
            }, $examples);

            $this->logger->logDuplicatesFound('current', $duplicateCount, $exampleTexts);
        }
    }

    /**
     * Classify content based on extradata for appropriate translation strategy
     */
    private function classifyUnits(): void
    {
        $stats = ['brand_voice' => 0, 'metadata' => 0, 'non_translatable' => 0];

        foreach ($this->translationUnits as &$unit) {
            $contentType = $unit['extradata']['wpml_context'] ?? 'Unknown';
            $unit['content_type'] = $contentType;

            // Classify for translation strategy
            if (in_array($contentType, $this->contentTypes['non_translatable'])) {
                $unit['translation_strategy'] = 'non_translatable';
                $stats['non_translatable']++;
            } elseif (in_array($contentType, $this->contentTypes['metadata'])) {
                $unit['translation_strategy'] = 'metadata';
                $stats['metadata']++;
            } elseif (in_array($contentType, $this->contentTypes['brand_voice'])) {
                $unit['translation_strategy'] = 'brand_voice';
                $stats['brand_voice']++;
            } else {
                // Default to brand voice for unknown content types
                $unit['translation_strategy'] = 'brand_voice';
                $stats['brand_voice']++;
            }
        }

        $this->logger->logContentTypeStats('current', $stats);
    }

    /**
     * Apply non-translatable rules to content
     */
    private function applyNonTranslatableRules(): void
    {
        foreach ($this->translationUnits as &$unit) {
            if ($unit['translation_strategy'] === 'non_translatable') {
                continue; // Already marked as non-translatable
            }

            $content = $unit['source'];

            // Check exact matches
            if (in_array(trim($content), $this->nonTranslatableRules['exact_matches'])) {
                $unit['translation_strategy'] = 'non_translatable';
                continue;
            }

            // Check patterns
            foreach ($this->nonTranslatableRules['patterns'] as $type => $pattern) {
                if (preg_match($pattern, $content)) {
                    $unit['translation_strategy'] = 'non_translatable';
                    break;
                }
            }

            // Check content patterns
            foreach ($this->nonTranslatableRules['content_patterns'] as $type => $pattern) {
                if (preg_match($pattern, $content)) {
                    $unit['translation_strategy'] = 'non_translatable';
                    break;
                }
            }
        }
    }

    /**
     * Get processing results organized by translation strategy
     */
    public function getProcessingResults(): array
    {
        $results = [
            'brand_voice' => [],
            'metadata' => [],
            'non_translatable' => [],
            'duplicates' => $this->duplicateMap,
            'stats' => [
                'total_units' => count($this->translationUnits),
                'brand_voice' => 0,
                'metadata' => 0,
                'non_translatable' => 0,
                'duplicates' => count($this->duplicateMap)
            ],
            'source_language' => $this->sourceLanguage,
            'target_language' => $this->targetLanguage,
            'dom' => $this->dom, // For reconstruction
            'xpath' => $this->xpath
        ];

        foreach ($this->translationUnits as $unit) {
            $strategy = $unit['translation_strategy'];
            $results[$strategy][] = $unit;
            $results['stats'][$strategy]++;
        }

        return $results;
    }

    /**
     * Insert translations back into the DOM structure
     */
    public function insertTranslations(array $translations): void
    {
        foreach ($translations as $unitId => $translatedText) {
            if (!isset($this->translationUnits[$unitId])) {
                continue;
            }

            $unit = $this->translationUnits[$unitId];
            $transUnitNode = $unit['dom_node'];

            // Find or create target element
            $targetNode = $this->xpath->query('.//xliff:target', $transUnitNode)->item(0);

            if (!$targetNode) {
                // Create target element if it doesn't exist
                $targetNode = $this->dom->createElement('target');
                $sourceNode = $this->xpath->query('.//xliff:source', $transUnitNode)->item(0);
                $sourceNode->parentNode->insertBefore($targetNode, $sourceNode->nextSibling);
            }

            // Clear existing content and insert translation
            while ($targetNode->firstChild) {
                $targetNode->removeChild($targetNode->firstChild);
            }

            // Handle CDATA wrapping like source
            if (strpos($unit['source'], '<![CDATA[') !== false ||
                strpos($translatedText, '<') !== false) {
                $cdata = $this->dom->createCDATASection($translatedText);
                $targetNode->appendChild($cdata);
            } else {
                $targetNode->textContent = $translatedText;
            }

            // Handle duplicates - apply same translation to all duplicates
            if (isset($this->duplicateMap[$unitId])) {
                foreach ($this->duplicateMap[$unitId] as $duplicateId) {
                    if ($duplicateId !== $unitId && isset($this->translationUnits[$duplicateId])) {
                        $this->insertSingleTranslation($duplicateId, $translatedText);
                    }
                }
            }
        }
    }

    /**
     * Insert translation for a single unit
     */
    private function insertSingleTranslation(string $unitId, string $translation): void
    {
        if (!isset($this->translationUnits[$unitId])) {
            return;
        }

        $unit = $this->translationUnits[$unitId];
        $transUnitNode = $unit['dom_node'];
        $targetNode = $this->xpath->query('.//xliff:target', $transUnitNode)->item(0);

        if (!$targetNode) {
            $targetNode = $this->dom->createElement('target');
            $sourceNode = $this->xpath->query('.//xliff:source', $transUnitNode)->item(0);
            $sourceNode->parentNode->insertBefore($targetNode, $sourceNode->nextSibling);
        }

        while ($targetNode->firstChild) {
            $targetNode->removeChild($targetNode->firstChild);
        }

        if (strpos($unit['source'], '<![CDATA[') !== false ||
            strpos($translation, '<') !== false) {
            $cdata = $this->dom->createCDATASection($translation);
            $targetNode->appendChild($cdata);
        } else {
            $targetNode->textContent = $translation;
        }
    }

    /**
     * Save the processed XLIFF file
     */
    public function saveToFile(string $outputPath): bool
    {
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $result = $this->dom->save($outputPath);

        if ($result === false) {
            $this->logger->logError(basename($outputPath), "Failed to save XLIFF file");
            return false;
        }

        return true;
    }

    /**
     * Get translation units by strategy for external processing
     */
    public function getUnitsByStrategy(string $strategy): array
    {
        return array_filter($this->translationUnits, function($unit) use ($strategy) {
            return $unit['translation_strategy'] === $strategy;
        });
    }

    /**
     * Get source and target languages
     */
    public function getLanguages(): array
    {
        return [
            'source' => $this->sourceLanguage,
            'target' => $this->targetLanguage
        ];
    }
}
