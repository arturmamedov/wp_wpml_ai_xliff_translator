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


    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }


    /**
     * Discover XLIFF files in input folder
     */
    public function discoverXLIFFFiles(string $inputFolder): array
    {
        $inputFolder = rtrim($inputFolder, '/');
        $extensions = [ '*.xliff', '*.xlf' ];
        $files = [];

        foreach ($extensions as $ext) {
            $foundFiles = glob($inputFolder . '/' . $ext);
            if ($foundFiles) {
                $files = array_merge($files, $foundFiles);
            }
        }

        // Also check subdirectories (one level deep)
        $subdirs = glob($inputFolder . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            foreach ($extensions as $ext) {
                $foundFiles = glob($subdir . '/' . $ext);
                if ($foundFiles) {
                    $files = array_merge($files, $foundFiles);
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
     * Process batch of files with progress tracking
     */
    public function processBatch(array $config): array
    {
        $batchId = $config['batch_id'];
        $files = $config['files'];
        $languages = $config['languages'];
        $provider = $config['provider'];
        $outputFolder = rtrim($config['output_folder'], '/');
        $resumeMode = $config['resume'] ?? false;

        // Initialize progress tracking
        $this->progressFile = "logs/batch-progress-{$batchId}.json";
        $this->loadBatchProgress($resumeMode);

        // Calculate total jobs
        $totalJobs = count($files) * count($languages);
        $currentJob = 0;
        $startTime = time();

        $results = [
            'success_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'failed_files' => [],
            'success_rate' => 0,
            'total_time' => 0
        ];

        $this->logger->logError("=== BATCH PROCESSING STARTED ===", null);
        $this->logger->logError("Batch ID: {$batchId} | Files: " . count($files) . " | Languages: " . implode(',', $languages), null);

        foreach ($files as $filePath) {
            $filename = basename($filePath);

            foreach ($languages as $targetLang) {
                $currentJob++;
                $jobKey = $filename . '_' . $targetLang;

                // Display progress
                $this->displayProgress($currentJob, $totalJobs, $startTime, $filename, $targetLang);

                // Check if already completed (for resume functionality)
                if ($this->isJobCompleted($jobKey)) {
                    $results['skipped_count']++;
                    echo " [SKIPPED - already completed]\n";
                    continue;
                }

                // Check if output file already exists
                $outputPath = $this->generateOutputPath($filePath, $targetLang, $outputFolder);
                if (file_exists($outputPath)) {
                    $this->markJobCompleted($jobKey, 'skipped');
                    $results['skipped_count']++;
                    echo " [SKIPPED - file exists]\n";
                    continue;
                }

                // Process single file+language combination
                try {
                    $success = $this->processSingleFile($filePath, $targetLang, $provider, $outputFolder);

                    if ($success) {
                        $this->markJobCompleted($jobKey, 'success');
                        $results['success_count']++;
                        echo " [SUCCESS]\n";
                    } else {
                        $this->markJobCompleted($jobKey, 'failed');
                        $results['failed_count']++;
                        $results['failed_files'][] = $jobKey;
                        echo " [FAILED]\n";
                    }

                } catch (Exception $e) {
                    $this->markJobCompleted($jobKey, 'error');
                    $results['failed_count']++;
                    $results['failed_files'][] = $jobKey . ' - ' . $e->getMessage();
                    $this->logger->logError("Job failed: {$jobKey} - " . $e->getMessage(), null);
                    echo " [ERROR: " . substr($e->getMessage(), 0, 50) . "]\n";
                }

                // Save progress after each job
                $this->saveBatchProgress();
            }
        }

        // Calculate final statistics
        $endTime = time();
        $results['total_time'] = $endTime - $startTime;
        $totalProcessed = $results['success_count'] + $results['failed_count'];
        $results['success_rate'] = $totalProcessed > 0 ? $results['success_count'] / $totalProcessed : 0;

        $this->logger->logError("=== BATCH PROCESSING COMPLETED ===", null);
        $this->logger->logError("Success: {$results['success_count']} | Failed: {$results['failed_count']} | Skipped: {$results['skipped_count']}", null);

        return $results;
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

            // Translate content (existing logic)
            $allTranslations = [];

            // Brand voice content
            if ( ! empty($results['brand_voice'])) {
                $brandTranslations = $translator->translateBrandVoiceContent(
                    $results['brand_voice'],
                    $targetLang
                );
                $allTranslations = array_merge($allTranslations, $brandTranslations);
            }

            // Metadata content
            if ( ! empty($results['metadata'])) {
                foreach ($results['metadata'] as $unit) {
                    $translation = $translator->translateMetadata(
                        $unit['source'],
                        $targetLang,
                        $unit['content_type'] ?? 'metadata'
                    );
                    if ($translation !== $unit['source']) {
                        $allTranslations[$unit['id']] = $translation;
                    }
                }
            }

            // Non-translatable content (mark as translated but keep original)
            if ( ! empty($results['non_translatable'])) {
                foreach ($results['non_translatable'] as $unit) {
                    $allTranslations[$unit['id']] = $unit['source'];
                }
            }

            // Insert translations and save
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
     * Generate output file path
     */
    private function generateOutputPath(string $inputPath, string $targetLang, string $outputFolder): string
    {
        $pathInfo = pathinfo($inputPath);
        $outputDir = $outputFolder . '/' . $targetLang;

        if ( ! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return $outputDir . '/' . $pathInfo['filename'] . '_' . $targetLang . '.xliff';
    }


    /**
     * Display real-time progress
     */
    private function displayProgress(int $current, int $total, int $startTime, string $currentFile, string $targetLang): void
    {
        $elapsed = time() - $startTime;
        $rate = $current > 0 ? $current / max($elapsed, 1) : 0;
        $eta = $total > $current && $rate > 0 ? ($total - $current) / $rate : 0;

        $progress = sprintf(
            "\r🔄 [%d/%d] (%.1f%%) %s → %s | ETA: %s",
            $current,
            $total,
            ($current / $total) * 100,
            substr(basename($currentFile), 0, 20),
            $targetLang,
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
