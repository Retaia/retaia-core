<?php

namespace App\Tests\Support;

use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
use Symfony\Component\HttpFoundation\Request;

final class AgentSigningTestHelper
{
    private const AGENT_ID = '11111111-1111-4111-8111-111111111111';

    /** @var array{home: string, fingerprint: string, public_key: string}|null */
    private static ?array $material = null;

    /**
     * @return array{agent_id: string, fingerprint: string, public_key: string}
     */
    public static function publicMaterial(): array
    {
        $material = self::ensureMaterial();

        return [
            'agent_id' => self::AGENT_ID,
            'fingerprint' => $material['fingerprint'],
            'public_key' => $material['public_key'],
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, string>
     */
    public static function signedHeaders(string $method, string $uri, ?array $payload = null, ?string $nonce = null): array
    {
        $material = self::ensureMaterial();
        $json = $payload === null ? '' : (string) json_encode($payload, JSON_THROW_ON_ERROR);
        return self::signedHeadersForBody($method, $uri, $json, $nonce);
    }

    /**
     * @return array<string, string>
     */
    public static function signedHeadersForBody(string $method, string $uri, string $body = '', ?string $nonce = null): array
    {
        $material = self::ensureMaterial();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $nonce ??= bin2hex(random_bytes(16));

        $request = Request::create(
            $uri,
            strtoupper($method),
            [],
            [],
            [],
            [],
            $body
        );
        $request->headers->set('X-Retaia-Agent-Id', self::AGENT_ID);
        $request->headers->set('X-Retaia-OpenPGP-Fingerprint', $material['fingerprint']);
        $request->headers->set('X-Retaia-Signature-Timestamp', $timestamp);
        $request->headers->set('X-Retaia-Signature-Nonce', $nonce);

        $canonical = (new SignedAgentMessageCanonicalizer())->canonicalize($request);
        $signature = self::sign($material['home'], $canonical);

        return [
            'HTTP_X_RETAIA_AGENT_ID' => self::AGENT_ID,
            'HTTP_X_RETAIA_OPENPGP_FINGERPRINT' => $material['fingerprint'],
            'HTTP_X_RETAIA_SIGNATURE' => $signature,
            'HTTP_X_RETAIA_SIGNATURE_TIMESTAMP' => $timestamp,
            'HTTP_X_RETAIA_SIGNATURE_NONCE' => $nonce,
        ];
    }

    private static function sign(string $home, string $message): string
    {
        $messageFile = $home.'/message.txt';
        $signatureFile = $home.'/signature.asc';
        file_put_contents($messageFile, $message);

        self::run([
            'gpg',
            '--batch',
            '--yes',
            '--no-tty',
            '--pinentry-mode',
            'loopback',
            '--homedir',
            $home,
            '--armor',
            '--detach-sign',
            '--output',
            $signatureFile,
            $messageFile,
        ]);

        $signature = file_get_contents($signatureFile);
        if (!is_string($signature) || $signature === '') {
            throw new \RuntimeException('Unable to read signed test payload.');
        }

        return base64_encode($signature);
    }

    /**
     * @return array{home: string, fingerprint: string, public_key: string}
     */
    private static function ensureMaterial(): array
    {
        if (self::$material !== null) {
            return self::$material;
        }

        $home = sys_get_temp_dir().'/retaia-agent-signing-test';
        if (!is_dir($home) && !mkdir($home, 0700, true) && !is_dir($home)) {
            throw new \RuntimeException('Unable to create gpg home for tests.');
        }
        chmod($home, 0700);

        $fingerprint = self::fingerprint($home);
        if ($fingerprint === null) {
            self::run([
                'gpg',
                '--batch',
                '--yes',
                '--no-tty',
                '--pinentry-mode',
                'loopback',
                '--homedir',
                $home,
                '--passphrase',
                '',
                '--quick-generate-key',
                'Retaia Test Agent <agent-signing@retaia.local>',
                'ed25519',
                'sign',
                '0',
            ]);
            $fingerprint = self::fingerprint($home);
        }

        if ($fingerprint === null) {
            throw new \RuntimeException('Unable to generate test OpenPGP fingerprint.');
        }

        [$code, $publicKey] = self::run([
            'gpg',
            '--batch',
            '--yes',
            '--no-tty',
            '--homedir',
            $home,
            '--armor',
            '--export',
            $fingerprint,
        ]);
        if ($code !== 0 || trim($publicKey) === '') {
            throw new \RuntimeException('Unable to export test OpenPGP public key.');
        }

        self::$material = [
            'home' => $home,
            'fingerprint' => $fingerprint,
            'public_key' => $publicKey,
        ];

        return self::$material;
    }

    private static function fingerprint(string $home): ?string
    {
        [$code, $output] = self::run([
            'gpg',
            '--batch',
            '--yes',
            '--no-tty',
            '--homedir',
            $home,
            '--list-secret-keys',
            '--with-colons',
        ]);
        if ($code !== 0) {
            return null;
        }

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!str_starts_with($line, 'fpr:')) {
                continue;
            }

            $parts = explode(':', $line);
            $fingerprint = strtoupper((string) ($parts[9] ?? ''));
            if (preg_match('/^[A-F0-9]{40}$/', $fingerprint) === 1) {
                return $fingerprint;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $command
     * @return array{int, string}
     */
    private static function run(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start gpg process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, trim((string) $stdout."\n".(string) $stderr)];
    }
}
