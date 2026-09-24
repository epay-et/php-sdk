<?php

declare(strict_types=1);

namespace Epay\Exception;

use Throwable;

/**
 * Base class for any non-2xx HTTP response.
 *
 * Callers branch on the subclass rather than inspecting status numbers.
 */
class EpayApiException extends EpayException
{
    /**
     * @param int $status HTTP status code of the response.
     * @param mixed $body Parsed body, or raw text when it was not JSON.
     * @param array<string, string> $headers Response headers, lowercased.
     * @param string $request Method and path of the originating request.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly mixed $body = null,
        public readonly array $headers = [],
        public readonly string $request = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Builds the most specific exception class for a response.
     *
     * @param array<string, string> $headers
     */
    public static function fromResponse(
        int $status,
        mixed $body,
        array $headers = [],
        string $request = '',
    ): self {
        $message = self::extractMessage($body, $status);
        $detail = $request !== ''
            ? sprintf('%s failed with %d: %s', $request, $status, $message)
            : $message;

        $class = match (true) {
            $status === 400 => EpayBadRequestException::class,
            $status === 401 => EpayAuthenticationException::class,
            $status === 403 => EpayPermissionDeniedException::class,
            $status === 404 => EpayNotFoundException::class,
            $status === 409 => EpayConflictException::class,
            $status === 422 => EpayUnprocessableEntityException::class,
            $status === 429 => EpayRateLimitException::class,
            $status >= 500 => EpayServerException::class,
            default => self::class,
        };

        return new $class($detail, $status, $body, $headers, $request);
    }

    /**
     * Extracts a human-readable message from an error body.
     *
     * The API uses two envelopes: `{"status":"error","data":{"message":…}}` on the
     * transaction endpoints and `{"statusCode","message","error"}` on the provider
     * endpoints, where `message` may itself be an array of validation strings.
     */
    public static function extractMessage(mixed $body, int $status): string
    {
        $fallback = sprintf('HTTP %d', $status);

        if (is_string($body)) {
            return trim($body) !== '' ? trim($body) : $fallback;
        }
        if (!is_array($body)) {
            return $fallback;
        }

        if (isset($body['data']) && is_array($body['data'])) {
            $nested = self::flatten($body['data']['message'] ?? null);
            if ($nested !== null) {
                return $nested;
            }
        }

        $flattened = self::flatten($body['message'] ?? null);
        if ($flattened !== null) {
            return $flattened;
        }

        if (isset($body['error']) && is_string($body['error']) && trim($body['error']) !== '') {
            return trim($body['error']);
        }

        return $fallback;
    }

    /** Reduces a message field to a string, joining validation lists. */
    private static function flatten(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (is_array($value)) {
            $parts = array_values(array_filter($value, 'is_string'));
            if ($parts !== []) {
                return implode('; ', $parts);
            }
        }

        return null;
    }
}
