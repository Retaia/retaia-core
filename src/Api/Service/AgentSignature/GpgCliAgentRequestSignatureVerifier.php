<?php

namespace App\Api\Service\AgentSignature;

final class GpgCliAgentRequestSignatureVerifier implements AgentRequestSignatureVerifier
{
    public function verify(string $publicKey, string $expectedFingerprint, string $message, string $signature): bool
    {
        $publicKey = trim($publicKey);
        $expectedFingerprint = strtoupper(preg_replace('/\s+/', '', trim($expectedFingerprint)) ?? '');
        $decodedSignature = base64_decode(trim($signature), true);
        if ($publicKey === '' || $expectedFingerprint === '' || !is_string($decodedSignature) || $decodedSignature === '') {
            return false;
        }

        $tempDir = sys_get_temp_dir().'/retaia-gpg-'.bin2hex(random_bytes(8));
        if (!@mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            return false;
        }

        try {
            if (file_put_contents($tempDir.'/pubkey.asc', $publicKey) === false) {
                return false;
            }
            if (file_put_contents($tempDir.'/message.txt', $message) === false) {
                return false;
            }
            if (file_put_contents($tempDir.'/signature.asc', $decodedSignature) === false) {
                return false;
            }

            [$importCode, $importOutput] = $this->run([
                'gpg',
                '--batch',
                '--no-tty',
                '--status-fd=1',
                '--homedir',
                $tempDir,
                '--import',
                $tempDir.'/pubkey.asc',
            ]);
            if ($importCode !== 0) {
                return false;
            }

            $importedFingerprint = $this->extractFingerprint($importOutput);
            if ($importedFingerprint === null || !hash_equals($expectedFingerprint, $importedFingerprint)) {
                return false;
            }

            [$verifyCode, $verifyOutput] = $this->run([
                'gpg',
                '--batch',
                '--no-tty',
                '--status-fd=1',
                '--homedir',
                $tempDir,
                '--verify',
                $tempDir.'/signature.asc',
                $tempDir.'/message.txt',
            ]);
            if ($verifyCode !== 0) {
                return false;
            }

            $verifiedFingerprint = $this->extractValidSignatureFingerprint($verifyOutput);

            return $verifiedFingerprint !== null && hash_equals($expectedFingerprint, $verifiedFingerprint);
        } finally {
            $this->deleteDir($tempDir);
        }
    }

    /**
     * @param array<int, string> $command
     * @return array{int, string}
     */
    private function run(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return [1, ''];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, trim((string) $stdout."\n".(string) $stderr)];
    }

    private function extractFingerprint(string $output): ?string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!str_contains($line, 'IMPORT_OK')) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $fingerprint = strtoupper((string) end($parts));
            if (preg_match('/^[A-F0-9]{40}$/', $fingerprint) === 1) {
                return $fingerprint;
            }
        }

        return null;
    }

    private function extractValidSignatureFingerprint(string $output): ?string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!str_contains($line, 'VALIDSIG')) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line)) ?: [];
            foreach ($parts as $part) {
                $candidate = strtoupper(trim($part));
                if (preg_match('/^[A-F0-9]{40}$/', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            @rmdir($dir);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
