<?php

namespace App\Ingest\Service;

/**
 * Service that detects proxy files in INBOX directory
 */
class ProxyFileDetector
{
    /**
     * Detects proxy files in INBOX directory with different formats
     * 
     * @param string $filePath Path to the file to check
     * @return array{path: string, type: string, original: string|null}|null
     */
    public function detectProxyFile(string $filePath): ?array
    {
        // Only process files in INBOX directory (main restriction)
        if (!str_contains(strtolower($filePath), '/inbox/')) {
            return null;
        }
        
        // Extract filename without path
        $filename = basename($filePath);
        
        // Proxy files with .lrf extension (DJI drone low-res files)
        if (str_ends_with(strtolower($filename), '.lrf')) {
            $baseName = substr($filename, 0, -4); // Remove .lrf extension
            return [
                'path' => $filePath,
                'type' => 'lrf',
                'original' => null
            ];
        }

        // CR2 with JPEG detection (raw + jpeg files)
        if (str_ends_with(strtolower($filename), '.cr2')) {
            return [
                'path' => $filePath,
                'type' => 'cr2_jpeg',
                'original' => null
            ];
        }

        // Proxy folder structure detection (proxy/, proxies/, proxie/)
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        if (count($pathParts) >= 2) {
            $firstDir = strtolower($pathParts[1]);
            if ($firstDir === 'proxy' || $firstDir === 'proxies' || $firstDir === 'proxie') {
                return [
                    'path' => $filePath,
                    'type' => 'proxy_folder',
                    'original' => null
                ];
            }
        }

        return null;
    }
    
    /**
     * Check if a file is a proxy file that should bypass proxy generation
     */
    public function isProxyFile(string $filePath): bool
    {
        return $this->detectProxyFile($filePath) !== null;
    }
    
    /**
     * Get proxy type for a file
     */
    public function getProxyType(string $filePath): ?string
    {
        $proxy = $this->detectProxyFile($filePath);
        return $proxy ? $proxy['type'] : null;
    }
}