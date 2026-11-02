<?php

declare(strict_types=1);

namespace App\Support;

/** A context-integrity receipt, never a substitute for fresh record access. */
final class OperationalReportPreview
{
    public const TTL = 600;
    private const DOMAIN = 'contrust.operational-report-preview.v1.';

    public function __construct(private string $key)
    {
        if (strlen($key) < 32) throw new \RuntimeException('Report preview unavailable.');
    }

    public function issue(string $actor, array $context, int $now): string
    {
        $body = self::encode(json_encode(['v' => 1, 'expires' => $now + self::TTL,
            'digest' => self::digest($actor, $context)], JSON_THROW_ON_ERROR));
        return $body . '.' . hash_hmac('sha256', self::DOMAIN . $body, $this->key);
    }

    public function matches(string $receipt, string $actor, array $context, int $now): bool
    {
        if (strlen($receipt) > 1024 || substr_count($receipt, '.') !== 1) return false;
        [$body, $signature] = explode('.', $receipt);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || !hash_equals(hash_hmac('sha256', self::DOMAIN . $body, $this->key), $signature)) return false;
        $raw = base64_decode(strtr($body, '-_', '+/'), true);
        if (!is_string($raw) || self::encode($raw) !== $body) return false;
        try { $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $failure) { return false; }
        return is_array($value) && array_keys($value) === ['v', 'expires', 'digest']
            && $value['v'] === 1 && is_int($value['expires'])
            && $value['expires'] > $now && $value['expires'] <= $now + self::TTL
            && is_string($value['digest']) && hash_equals(self::digest($actor, $context), $value['digest']);
    }

    private static function digest(string $actor, array $context): string
    {
        return hash('sha256', json_encode([self::DOMAIN, $actor, $context], JSON_THROW_ON_ERROR));
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
