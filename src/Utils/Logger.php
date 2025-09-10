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
        $this->writeLog('INFO', "Starting translation to {$targetLanguage}");
    }

    public function logTranslationSuccess(string $filename, string $targetLanguage, string $outputPath): void
    {
        $this->writeLog('SUCCESS', "Translated to {$targetLanguage} → {$outputPath}");
        $this->sessionStats[$filename]['units_translated']++;
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

        $this->writeLog('INFO', "File completed: {$filename}");
        $this->writeLog('INFO', "Duration: {$duration}s | Units: {$stats['units_found']} | Translated: {$stats['units_translated']} | Errors: {$stats['errors']}");
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
