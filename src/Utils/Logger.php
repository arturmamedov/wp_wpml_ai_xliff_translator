<?php

namespace NestsHostels\XLIFFTranslation\Utils;

/**
 * Logger class for XLIFF Translation Workflow
 * Provides structured logging with appropriate verbosity
 */
class Logger
{
    private string $logFile;
    private string $sessionId;
    private array $failedFiles = [];
    private array $sessionStats = [];

    public function __construct(string $logDir = 'logs', ?string $filename = null)
    {
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Create unique session ID
        $this->sessionId = date('Y-m-d_H-i-s');

        // Generate session-specific log file
        if ($filename) {
            $cleanFilename = pathinfo($filename, PATHINFO_FILENAME);
            $this->logFile = $logDir . '/xliff-translation-' . $this->sessionId . '_' . $cleanFilename . '.log';
        } else {
            $this->logFile = $logDir . '/xliff-translation-' . $this->sessionId . '.log';
        }

        $this->initializeLog();
    }

    private function initializeLog(): void
    {
        $this->writeLog('INFO', '=== XLIFF Translation Session Started ===');
        $this->writeLog('INFO', 'Timestamp: ' . date('Y-m-d H:i:s'));
        $this->writeLog('INFO', 'PHP Version: ' . PHP_VERSION);
    }

    public function logFileStart(string $filename): void
    {
        $this->writeLog('INFO', "Starting file: {$filename}");
        $this->sessionStats[$filename] = [
            'start_time' => microtime(true),
            'units_found' => 0,
            'units_translated' => 0,
            'duplicates_found' => 0,
            'non_translatable' => 0,
            'errors' => 0
        ];
    }

    public function logParsingStart(string $filename): void
    {
        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "PARSING XLIFF FILE: {$filename}");
        $this->writeLog('INFO', "========================================");
    }

    public function logParsingResults(array $results): void
    {
        $stats = $results['stats'];
        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "PARSING RESULTS SUMMARY");
        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "Total translation units: {$stats['total_units']}");
        $this->writeLog('INFO', "Brand voice content: {$stats['brand_voice']}");
        $this->writeLog('INFO', "Metadata content: {$stats['metadata']}");
        $this->writeLog('INFO', "Non-translatable content: {$stats['non_translatable']}");
        $this->writeLog('INFO', "Duplicate groups: {$stats['duplicates']}");
        $this->writeLog('INFO', "Source language: {$results['source_language']}");
        $this->writeLog('INFO', "Target language: {$results['target_language']}");
    }

    public function logDuplicateGroupsDetailed(array $duplicateGroups, array $translationUnits): void
    {
        if (empty($duplicateGroups)) {
            $this->writeLog('INFO', "No duplicate groups found");
            return;
        }

        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "DUPLICATE GROUPS DETAILED BREAKDOWN");
        $this->writeLog('INFO', "========================================");

        foreach ($duplicateGroups as $originalId => $duplicateIds) {
            $count = count($duplicateIds);
            $originalContent = isset($translationUnits[$originalId])
                ? substr($translationUnits[$originalId]['source'], 0, 80) . '...'
                : 'Content not found';

            $this->writeLog('INFO', "Group {$originalId} ({$count} duplicates): {$originalContent}");

            // Log all duplicate IDs for complete traceability
            $this->writeLog('DEBUG', "  Duplicate IDs: " . implode(', ', $duplicateIds));
        }
    }

    public function logContentSamplesDetailed(array $results): void
    {
        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "CONTENT SAMPLES BY TRANSLATION STRATEGY");
        $this->writeLog('INFO', "========================================");

        foreach (['brand_voice', 'metadata', 'non_translatable'] as $strategy) {
            if (!empty($results[$strategy])) {
                $count = count($results[$strategy]);
                $this->writeLog('INFO', "{$strategy}: {$count} units");

                // Log first 5 samples with full details
                $samples = array_slice($results[$strategy], 0, 5);
                foreach ($samples as $i => $unit) {
                    $content = substr($unit['source'], 0, 100);
                    $contentType = $unit['content_type'] ?? 'Unknown';
                    $purpose = $unit['purpose'] ?? '';
                    $group = $unit['group'] ?? '';

                    $this->writeLog('DEBUG', "  " . ($i + 1) . ". [{$unit['id']}] Type: {$contentType}");
                    $this->writeLog('DEBUG', "     Purpose: {$purpose} | Group: {$group}");
                    $this->writeLog('DEBUG', "     Content: {$content}" . (strlen($unit['source']) > 100 ? '...' : ''));

                    if ($unit['is_duplicate']) {
                        $this->writeLog('DEBUG', "     [DUPLICATE] Group: {$unit['duplicate_group']}");
                    }
                }

                if (count($results[$strategy]) > 5) {
                    $remaining = count($results[$strategy]) - 5;
                    $this->writeLog('DEBUG', "  ... and {$remaining} more {$strategy} units");
                }
            } else {
                $this->writeLog('INFO', "{$strategy}: 0 units");
            }
        }
    }

    public function logTranslationApplied(string $unitId, string $originalText, string $translatedText, bool $isDuplicate = false): void
    {
        $originalPreview = substr($originalText, 0, 60) . (strlen($originalText) > 60 ? '...' : '');
        $translatedPreview = substr($translatedText, 0, 60) . (strlen($translatedText) > 60 ? '...' : '');

        $duplicateMarker = $isDuplicate ? '[DUPLICATE]' : '';

        $this->writeLog('INFO', "[TRANSLATED] {$duplicateMarker} Unit: {$unitId}");
        $this->writeLog('DEBUG', "  Original: {$originalPreview}");
        $this->writeLog('DEBUG', "  Translation: {$translatedPreview}");
    }

    public function logTranslationBatch(array $translations, array $duplicateMap): void
    {
        $totalTranslations = count($translations);
        $duplicatesApplied = 0;

        // Count duplicates that will be auto-applied
        foreach ($duplicateMap as $originalId => $duplicateIds) {
            if (isset($translations[$originalId])) {
                $duplicatesApplied += count($duplicateIds) - 1; // Exclude original
            }
        }

        $this->writeLog('INFO', "Applying {$totalTranslations} unique translations");
        $this->writeLog('INFO', "Auto-applying to {$duplicatesApplied} duplicate units");
        $this->writeLog('INFO', "Total units translated: " . ($totalTranslations + $duplicatesApplied));
    }

    public function logUnitsFound(string $filename, int $count): void
    {
        $this->writeLog('INFO', "Found {$count} translation units in {$filename}");
        $this->sessionStats[$filename]['units_found'] = $count;
    }

    public function logDuplicatesFound(string $filename, int $count, array $examples = []): void
    {
        $this->writeLog('INFO', "Detected {$count} duplicates in {$filename}");
        $this->sessionStats[$filename]['duplicates_found'] = $count;

        if (!empty($examples)) {
            $exampleText = implode(', ', array_slice($examples, 0, 3));
            $this->writeLog('DEBUG', "Duplicate examples: {$exampleText}");
        }
    }

    public function logDuplicateDetails(string $filename, array $duplicateGroups): void
    {
        if (empty($duplicateGroups)) {
            return;
        }

        $this->writeLog('DEBUG', '=== DUPLICATE GROUPS BREAKDOWN ===');

        foreach ($duplicateGroups as $originalId => $duplicateIds) {
            $count = count($duplicateIds);
            $this->writeLog('DEBUG', "Group {$originalId}: {$count} duplicates");

            // Log first few duplicate IDs for debugging
            $sampleIds = array_slice($duplicateIds, 0, 3);
            $this->writeLog('DEBUG', "  Sample IDs: " . implode(', ', $sampleIds));
        }
    }

    public function logContentSamples(array $results): void
    {
        $this->writeLog('DEBUG', '=== CONTENT SAMPLES BY STRATEGY ===');

        foreach (['brand_voice', 'metadata', 'non_translatable'] as $strategy) {
            if (!empty($results[$strategy])) {
                $count = count($results[$strategy]);
                $this->writeLog('DEBUG', "{$strategy}: {$count} units");

                $samples = array_slice($results[$strategy], 0, 3);
                foreach ($samples as $i => $unit) {
                    $content = substr($unit['source'], 0, 60) . '...';
                    $this->writeLog('DEBUG', "  " . ($i + 1) . ". [{$unit['id']}] {$unit['content_type']}: {$content}");
                }
            }
        }
    }

    public function logLanguageInfo(string $filename, string $sourceLanguage, string $targetLanguage): void
    {
        $this->writeLog('INFO', "Source language: {$sourceLanguage} → Target language: {$targetLanguage}");
    }

    public function logProcessingComplete(string $filename, array $stats): void
    {
        $this->writeLog('INFO', '=== PROCESSING COMPLETE ===');
        $this->writeLog('INFO', "Total units: {$stats['total_units']}");
        $this->writeLog('INFO', "Brand voice: {$stats['brand_voice']} | Metadata: {$stats['metadata']} | Non-translatable: {$stats['non_translatable']}");
        $this->writeLog('INFO', "Duplicate groups: {$stats['duplicates']}");
    }

    public function logContentTypeStats(string $filename, array $stats): void
    {
        $brandVoice = $stats['brand_voice'] ?? 0;
        $metadata = $stats['metadata'] ?? 0;
        $nonTranslatable = $stats['non_translatable'] ?? 0;

        $this->writeLog('INFO',
            "{$brandVoice} units → Brand Voice | {$metadata} units → Metadata | {$nonTranslatable} units → Non-translatable"
        );

        $this->sessionStats[$filename]['non_translatable'] = $nonTranslatable;
    }

    public function logTranslationStart(string $filename, string $targetLanguage): void
    {
        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "STARTING TRANSLATION TO {$targetLanguage}");
        $this->writeLog('INFO', "========================================");
    }

    public function updateTranslationCount(string $filename, int $count): void
    {
        if (isset($this->sessionStats[$filename])) {
            $this->sessionStats[$filename]['units_translated'] = $count;
        }
    }

    public function logTranslationSuccess(string $filename, string $targetLanguage, string $outputPath): void
    {
        $this->writeLog('SUCCESS', "Translated to {$targetLanguage} → {$outputPath}");
        //$this->sessionStats[$this->filename]['units_translated']++; // TODO: i think this was designed for use this log after each translation
    }

    public function logError(string $filename, string $error, ?array $unit = null): void
    {
        $this->writeLog('ERROR', $error);

        if ($unit) {
            $unitId = $unit['id'] ?? 'unknown';
            $this->writeLog('ERROR', "Unit ID: {$unitId}");
        }

        $this->sessionStats[$filename]['errors']++;

        if (!in_array($filename, $this->failedFiles)) {
            $this->failedFiles[] = $filename;
        }
    }

    public function logFileComplete(string $filename): void
    {
        $stats = $this->sessionStats[$filename];
        $duration = round(microtime(true) - $stats['start_time'], 2);

        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "FILE PROCESSING COMPLETE: {$filename}");
        $this->writeLog('INFO', "========================================");
        $this->writeLog('INFO', "Duration: {$duration}s");
        $this->writeLog('INFO', "Units found: {$stats['units_found']}");
        $this->writeLog('INFO', "Units translated: {$stats['units_translated']}");
        $this->writeLog('INFO', "Duplicates handled: {$stats['duplicates_found']}");
        $this->writeLog('INFO', "Errors: {$stats['errors']}");
    }
    public function logFileFailure(string $filename, string $reason): void
    {
        $this->writeLog('ERROR', "File failed: {$filename} - {$reason}");

        if (!in_array($filename, $this->failedFiles)) {
            $this->failedFiles[] = $filename;
        }
    }

    public function logSessionSummary(): void
    {
        $totalFiles = count($this->sessionStats);
        $failedCount = count($this->failedFiles);
        $successCount = $totalFiles - $failedCount;

        $this->writeLog('INFO', '=== Session Summary ===');
        $this->writeLog('INFO', "Total files processed: {$totalFiles}");
        $this->writeLog('INFO', "Successful: {$successCount} | Failed: {$failedCount}");

        if (!empty($this->failedFiles)) {
            $this->writeLog('ERROR', 'Failed files for reprocessing:');
            foreach ($this->failedFiles as $file) {
                $this->writeLog('ERROR', "  - {$file}");
            }
        }

        // Calculate totals
        $totalUnits = array_sum(array_column($this->sessionStats, 'units_found'));
        $totalTranslated = array_sum(array_column($this->sessionStats, 'units_translated'));
        $totalDuplicates = array_sum(array_column($this->sessionStats, 'duplicates_found'));

        $this->writeLog('INFO', "Total translation units: {$totalUnits}");
        $this->writeLog('INFO', "Total translated: {$totalTranslated}");
        $this->writeLog('INFO', "Total duplicates handled: {$totalDuplicates}");
    }

    public function getFailedFiles(): array
    {
        return $this->failedFiles;
    }

    public function getSessionStats(): array
    {
        return $this->sessionStats;
    }

    private function writeLog(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$level}: {$message}" . PHP_EOL;

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Also output to console for immediate feedback
        $this->outputToConsole($level, $message);
    }

    private function outputToConsole(string $level, string $message): void
    {
        $colors = [
            'INFO' => "\033[0;37m",     // White
            'SUCCESS' => "\033[0;32m",  // Green
            'ERROR' => "\033[0;31m",    // Red
            'DEBUG' => "\033[0;36m"     // Cyan
        ];

        $reset = "\033[0m";
        $color = $colors[$level] ?? "\033[0;37m";

        echo $color . "[{$level}] " . $message . $reset . PHP_EOL;
    }
}
