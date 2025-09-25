<?php

namespace NestsHostels\XLIFFTranslation\Utils;

/**
 * TranslationCache - Simple JSON file-based translation cache
 */
class TranslationCache
{

    private string $cacheFile;

    private array $cache = [];

    private bool $enabled;


    public function __construct(string $cacheFile, bool $enabled = true)
    {
        $this->cacheFile = $cacheFile;
        $this->enabled = $enabled;

        if ($this->enabled) {
            $this->ensureCacheDirectory();
            $this->loadCache();
        }
    }


    public function get(string $key): ?string
    {
        if ( ! $this->enabled) {
            return null;
        }

        $cached = $this->cache[$key] ?? null;

        // Backward compatibility: handle both old string format and new array format
        if (is_string($cached)) {
            return $cached; // Old format
        } elseif (is_array($cached) && isset($cached['translation'])) {
            return $cached['translation']; // New format
        }

        return null;
    }


    public function set(string $key, string $translation, array $metadata = []): void
    {
        if ( ! $this->enabled) {
            return;
        }

        // Store with metadata
        $this->cache[$key] = [
            'translation' => $translation,
            'created_at' => date('Y-m-d H:i:s'),
            'provider' => $metadata['provider'] ?? 'unknown',
            'content_type' => $metadata['content_type'] ?? 'general',
            'xliff_file' => $metadata['xliff_file'] ?? 'unknown'
        ];

        $this->saveCache();
    }


    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'cached_translations' => count($this->cache),
            'cache_file' => $this->cacheFile
        ];
    }


    private function ensureCacheDirectory(): void
    {
        $cacheDir = dirname($this->cacheFile);
        if ( ! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }


    private function loadCache(): void
    {
        if (file_exists($this->cacheFile)) {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            $this->cache = $data ?? [];
        }
    }


    private function saveCache(): void
    {
        file_put_contents($this->cacheFile, json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
