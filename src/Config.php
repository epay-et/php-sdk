<?php

declare(strict_types=1);

namespace Epay;

use Epay\Exception\EpayConfigException;

/** Fully resolved client configuration. */
final class Config
{
    /** Default API root. */
    public const DEFAULT_BASE_URL = 'https://api.epayethiopia.com/v1';

    /** Default per-attempt timeout, in seconds. */
    public const DEFAULT_TIMEOUT = 30.0;

    /** Default number of retries after the initial attempt. */
    public const DEFAULT_MAX_RETRIES = 2;

    /**
     * @param array<string, string> $defaultHeaders
     */
    private function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        public readonly ?string $webhookSecret,
        public readonly array $defaultHeaders,
        public readonly ?string $mode,
    ) {
    }

    /**
     * Validates and fills in every option, reading environment fallbacks.
     *
     * @param array{
     *     api_key?: string|null,
     *     webhook_secret?: string|null,
     *     base_url?: string|null,
     *     timeout?: float|int|null,
     *     max_retries?: int|null,
     *     default_headers?: array<string, string>
     * } $options
     *
     * @throws EpayConfigException if no API key is available, the key is clearly
     *                             not a secret key, or an option is out of range.
     */
    public static function fromOptions(array $options = []): self
    {
        $apiKey = trim((string) ($options['api_key'] ?? self::env('EPAY_SECRET_KEY') ?? ''));

        if ($apiKey === '') {
            throw new EpayConfigException(
                'Missing ePay API key. Pass ["api_key" => …] or set the EPAY_SECRET_KEY '
                . 'environment variable.',
            );
        }
        if (!str_starts_with($apiKey, 'sk_')) {
            throw new EpayConfigException(
                'Invalid ePay API key: expected a secret key beginning with "sk_live_" or '
                . '"sk_test_". Secret keys are found in the dashboard under '
                . 'Developers -> API Keys.',
            );
        }

        $timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            throw new EpayConfigException(
                sprintf('timeout must be a positive number of seconds, received %s', $timeout),
            );
        }

        $maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        if ($maxRetries < 0) {
            throw new EpayConfigException(
                sprintf('max_retries must be a non-negative integer, received %d', $maxRetries),
            );
        }

        $baseUrl = rtrim(
            (string) ($options['base_url'] ?? self::env('EPAY_BASE_URL') ?? self::DEFAULT_BASE_URL),
            '/',
        );

        $webhookSecret = $options['webhook_secret'] ?? self::env('EPAY_WEBHOOK_SECRET');

        return new self(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            timeout: $timeout,
            maxRetries: $maxRetries,
            webhookSecret: $webhookSecret === '' ? null : $webhookSecret,
            defaultHeaders: $options['default_headers'] ?? [],
            mode: self::modeFromApiKey($apiKey),
        );
    }

    /**
     * Derives the traffic mode from a key prefix.
     *
     * Returns null for an `sk_`-prefixed key with an unrecognised environment
     * segment, so a future key format does not break the client.
     */
    public static function modeFromApiKey(string $apiKey): ?string
    {
        return match (true) {
            str_starts_with($apiKey, 'sk_live_') => 'live',
            str_starts_with($apiKey, 'sk_test_') => 'sandbox',
            default => null,
        };
    }

    /** Reads an environment variable, treating an empty value as unset. */
    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
