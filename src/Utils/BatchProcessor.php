<?php

namespace NestsHostels\XLIFFTranslation\Utils;

use NestsHostels\XLIFFTranslation\Core\XLIFFParser;
use NestsHostels\XLIFFTranslation\Core\BrandVoiceTranslator;

/**
 * BatchProcessor - Handles batch translation of multiple XLIFF files
 *
 * Features:
 * - File discovery and filtering
 * - Progress tracking and resume capability
 * - Error recovery and retry logic
 * - Batch statistics and reporting
 */
class BatchProcessor
{

    private array $config;

    private Logger $logger;

    private string $progressFile;

    private array $batchProgress = [];

    private array $batchConfig;

    private array $lastFileStats = []; // Track stats from last processed file


    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->batchConfig = require __DIR__ . '/../../config/batch-settings.php';
    }


    /**
     * Discover XLIFF files in input folder
     */
    public function discoverXLIFFFiles(string $inputFolder): array
    {
        $inputFolder = rtrim($inputFolder, '/');
        $extensions = array_map(fn($ext) => "*.$ext", $this->batchConfig['supported_extensions']);
        $files = [];

        // Scan main directory
        foreach ($extensions as $ext) {
            $foundFiles = glob($inputFolder . '/' . $ext);
            if ($foundFiles) {
                $files = array_merge($files, $foundFiles);
            }
        }

        // Scan subdirectories if enabled
        if ($this->batchConfig['scan_subdirectories']) {
            $maxDepth = $this->batchConfig['max_subdirectory_depth'];

            for ($depth = 1; $depth <= $maxDepth; $depth++) {
                $pattern = str_repeat('/*', $depth);
                $subdirs = glob($inputFolder . $pattern, GLOB_ONLYDIR);

                foreach ($subdirs as $subdir) {
                    foreach ($extensions as $ext) {
                        $foundFiles = glob($subdir . '/' . $ext);
                        if ($foundFiles) {
                            $files = array_merge($files, $foundFiles);
                        }
                    }
                }
            }
        }

        // Remove duplicates and sort
        $files = array_unique($files);
        sort($files);

        $this->logger->logError("Discovered " . count($files) . " XLIFF files", null);

        return $files;
    }


    /**
     * Process batch of files with automatic target language detection
     */
    public function processBatch(array $config): array
    {
        $batchId = $config['batch_id'];
        $files = $config['files'];
        $provider = $config['provider'];
        $outputFolder = rtrim($config['output_folder'], '/');
        $resumeMode = $config['resume'] ?? false;

        // Initialize progress tracking
        $this->progressFile = "logs/batch-progress-{$batchId}.json";
        $this->loadBatchProgress($resumeMode);

        // Each file is processed once to its designated target language
        $totalJobs = count($files);
        $currentJob = 0;
        $startTime = time();

        $results = [
            'success_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'failed_files' => [],
            'success_rate' => 0,
            'total_time' => 0,
            'language_breakdown' => [], // Track which languages were processed
            'duplicate_savings' => [
                'total_duplicates_saved' => 0,
                'total_api_calls_made' => 0,
                'total_api_calls_would_have_made' => 0
            ]
        ];

        $this->logger->logError("=== BATCH PROCESSING STARTED ===", null);
        $this->logger->logError("Batch ID: {$batchId} | Files: " . count($files), null);

        foreach ($files as $filePath) {
            $currentJob++;
            $filename = basename($filePath);

            // Display progress
            echo sprintf(
                "\r🔄 [%d/%d] (%.1f%%) Processing: %s",
                $currentJob,
                $totalJobs,
                ($currentJob / $totalJobs) * 100,
                substr($filename, 0, 40)
            );

            // Check if already completed (for resume functionality)
            if ($this->isJobCompleted($filename)) {
                $results['skipped_count']++;
                echo " [SKIPPED - already completed]\n";
                continue;
            }

            // Detect target language from XLIFF file
            try {
                $targetLang = $this->detectTargetLanguage($filePath);
                if ( ! $targetLang) {
                    throw new \Exception("Could not detect target language from XLIFF file");
                }

                // Check if output file already exists
                $outputPath = $this->generateOutputPath($filePath, $targetLang, $outputFolder);
                if (file_exists($outputPath)) {
                    $this->markJobCompleted($filename, 'skipped');
                    $results['skipped_count']++;
                    echo " → {$targetLang} [SKIPPED - file exists]\n";
                    continue;
                }

                echo " → {$targetLang}";

                // Process single file to its designated target language
                $success = $this->processSingleFile($filePath, $targetLang, $provider, $outputFolder);

                if ($success) {
                    $this->markJobCompleted($filename, 'success');
                    $results['success_count']++;

                    // Track language breakdown
                    if ( ! isset($results['language_breakdown'][$targetLang])) {
                        $results['language_breakdown'][$targetLang] = 0;
                    }
                    $results['language_breakdown'][$targetLang]++;

                    echo " [SUCCESS]";

                    // Accumulate duplicate savings statistics
                    if (isset($this->lastFileStats)) {
                        $duplicatesSaved = $this->lastFileStats['duplicates_saved'] ?? 0;
                        $totalUnits = $this->lastFileStats['total_units'] ?? 0;

                        $results['duplicate_savings']['total_duplicates_saved'] += $duplicatesSaved;
                        $results['duplicate_savings']['total_api_calls_made'] += ($totalUnits - $duplicatesSaved);
                        $results['duplicate_savings']['total_api_calls_would_have_made'] += $totalUnits;

                        if ($duplicatesSaved > 0) {
                            echo " (saved {$duplicatesSaved} duplicate API calls)";
                        }
                    }
                    echo "\n";
                } else {
                    $this->markJobCompleted($filename, 'failed');
                    $results['failed_count']++;
                    $results['failed_files'][] = $filename . ' → ' . $targetLang;
                    echo " [FAILED]\n";
                }

            } catch (\Exception $e) {
                $this->markJobCompleted($filename, 'error');
                $results['failed_count']++;
                $results['failed_files'][] = $filename . ' - ' . $e->getMessage();
                $this->logger->logError("Job failed: {$filename} - " . $e->getMessage(), null);
                echo " [ERROR: " . substr($e->getMessage(), 0, 50) . "]\n";
            }

            // Save progress after each job
            $this->saveBatchProgress();
        }

        // Calculate final statistics
        $endTime = time();
        $results['total_time'] = $endTime - $startTime;
        $totalProcessed = $results['success_count'] + $results['failed_count'];
        $results['success_rate'] = $totalProcessed > 0 ? $results['success_count'] / $totalProcessed : 0;

        $this->logger->logError("=== BATCH PROCESSING COMPLETED ===", null);
        $this->logger->logError("Success: {$results['success_count']} | Failed: {$results['failed_count']} | Skipped: {$results['skipped_count']}", null);

        // Log language breakdown
        foreach ($results['language_breakdown'] as $lang => $count) {
            $this->logger->logError("Language {$lang}: {$count} files processed", null);
        }

        return $results;
    }


    /**
     * Detect target language from XLIFF file content
     */
    private function detectTargetLanguage(string $filePath): ?string
    {
        try {
            // Use existing XLIFFParser to read language info
            $tempLogger = new Logger('logs', 'temp-detection');
            $parser = new XLIFFParser($tempLogger);

            // Just parse to get language info, don't process
            $dom = new \DOMDocument();
            if ( ! $dom->load($filePath)) {
                return null;
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('xliff', 'urn:oasis:names:tc:xliff:document:1.2');

            $fileNode = $xpath->query('//xliff:file')->item(0);
            if ( ! $fileNode) {
                return null;
            }

            return $fileNode->getAttribute('target-language') ?: null;

        } catch (\Exception $e) {
            $this->logger->logError("Failed to detect target language from {$filePath}: " . $e->getMessage(), null);

            return null;
        }
    }


    /**
     * Filter out duplicate units before translation to avoid redundant API calls
     * return array
     */
    public function filterUniqueUnits($units): array
    {
        return array_filter($units, function ($unit) {
            return ! $unit['is_duplicate']; // Keep only non-duplicates
        });
    }


    /**
     * Process single file for one target language using existing components
     */
    private function processSingleFile(string $filePath, string $targetLang, string $provider, string $outputFolder): bool
    {
        $filename = basename($filePath);

        try {
            // Use existing components WITHOUT modification
            $fileLogger = new Logger('logs', $filename . '_' . $targetLang);
            $parser = new XLIFFParser($fileLogger);
            $translator = new BrandVoiceTranslator($this->config, $fileLogger);
            $translator->setProvider($provider);

            // Parse file (existing logic)
            $results = $parser->parseXLIFFFile($filePath);

            // Calculate duplicate savings for statistics
            $totalUnits = $results['stats']['total_units'];
            $duplicateGroups = count($results['duplicates']);
            $duplicatesSaved = 0;

            // Count how many API calls we're saving through duplicate detection
            foreach ($results['duplicates'] as $originalId => $duplicateIds) {
                $duplicatesSaved += count($duplicateIds) - 1; // -1 because we still translate the original
            }

            // Store stats for progress display
            $this->lastFileStats = [
                'total_units' => $totalUnits,
                'duplicate_groups' => $duplicateGroups,
                'duplicates_saved' => $duplicatesSaved
            ];

            $allTranslations = [];

            // Process Brand Voice Content
            if ( ! empty($results['brand_voice'])) {
                $uniqueBrandVoice = $this->filterUniqueUnits($results['brand_voice']);
                $brandVoiceTranslations = $translator->translateBrandVoiceContent(
                    $uniqueBrandVoice,
                    $targetLang
                );
                $allTranslations = array_merge($allTranslations, $brandVoiceTranslations);
            }

            // Process Metadata Content
            if ( ! empty($results['metadata'])) {
                $metadataTranslations = [];
                $metadataUnits = $this->filterUniqueUnits($results['metadata']); // Filter duplicates

                foreach ($metadataUnits as $unit) {
                    $translation = $translator->translateMetadata(
                        $unit['source'],
                        $targetLang,
                        $unit['content_type'] ?? 'metadata'
                    );
                    if ($translation !== $unit['source']) {
                        $metadataTranslations[$unit['id']] = $translation;
                    }
                }

                $allTranslations = array_merge($allTranslations, $metadataTranslations);
            }

            // Non-translatable content (mark as translated but keep original)
            if ( ! empty($results['non_translatable'])) {
                foreach ($results['non_translatable'] as $unit) {
                    $allTranslations[$unit['id']] = $unit['source'];
                }
            }

            // Insert translations and save (this handles duplicate propagation automatically)
            $parser->insertTranslations($allTranslations);
            $outputPath = $this->generateOutputPath($filePath, $targetLang, $outputFolder);

            return $parser->saveToFile($outputPath);

        } catch (Exception $e) {
            $this->logger->logError("Single file processing failed: {$filename} -> {$targetLang}", [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);

            return false;
        }
    }


    /**
     * Generate output file path based on batch configuration
     */
    private function generateOutputPath(string $inputPath, string $targetLang, string $outputFolder): string
    {
        $pathInfo = pathinfo($inputPath);

        // Determine output directory structure
        if ($this->batchConfig['organize_by_language']) {
            $outputDir = $outputFolder . '/' . $targetLang;
        } else {
            $outputDir = $outputFolder;
        }

        // Handle preserve folder structure
        if ($this->batchConfig['preserve_folder_structure']) {
            $relativePath = str_replace(dirname(dirname($inputPath)), '', dirname($inputPath));
            $relativePath = trim($relativePath, '/');
            if ($relativePath) {
                $outputDir .= '/' . $relativePath;
            }
        }

        // Create directory if it doesn't exist
        if ( ! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Generate filename from pattern
        $pattern = $this->batchConfig['output_filename_pattern'];
        $outputFilename = str_replace(
            [ '{filename}', '{language}' ],
            [ $pathInfo['filename'], $targetLang ],
            $pattern
        );

        return $outputDir . '/' . $outputFilename;
    }


    /**
     * Display real-time progress - simplified for single language per file
     */
    private function displayProgress(int $current, int $total, int $startTime, string $currentFile): void
    {
        $elapsed = time() - $startTime;
        $rate = $current > 0 ? $current / max($elapsed, 1) : 0;
        $eta = $total > $current && $rate > 0 ? ($total - $current) / $rate : 0;

        $progress = sprintf(
            "🔄 [%d/%d] (%.1f%%) %s | ETA: %s",
            $current,
            $total,
            ($current / $total) * 100,
            substr(basename($currentFile), 0, 30),
            $eta > 0 ? gmdate('H:i:s', $eta) : 'calculating...'
        );

        echo $progress;
    }


    // Progress tracking methods
    private function loadBatchProgress(bool $resumeMode): void
    {
        if ($resumeMode && file_exists($this->progressFile)) {
            $this->batchProgress = json_decode(file_get_contents($this->progressFile), true) ?? [];
        } else {
            $this->batchProgress = [ 'completed' => [], 'timestamp' => time() ];
        }
    }


    private function saveBatchProgress(): void
    {
        $this->batchProgress['last_update'] = time();
        file_put_contents($this->progressFile, json_encode($this->batchProgress, JSON_PRETTY_PRINT));
    }


    private function isJobCompleted(string $jobKey): bool
    {
        return isset($this->batchProgress['completed'][$jobKey]);
    }


    private function markJobCompleted(string $jobKey, string $status): void
    {
        $this->batchProgress['completed'][$jobKey] = [
            'status' => $status,
            'timestamp' => time()
        ];
    }
}
