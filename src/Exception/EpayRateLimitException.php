<?php

declare(strict_types=1);

namespace Epay\Exception;

/**
 * `429` — the 100 requests / 60s per-key budget is exhausted.
 *
 * The SDK already retries these, so receiving one means the retry budget was
 * also exhausted.
 */
final class EpayRateLimitException extends EpayApiException
{
    /** Seconds to wait before retrying, from the `Retry-After` header. */
    public function retryAfterSeconds(): ?float
    {
        $value = $this->headers['retry-after'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0.0, $timestamp - time());
    }
}
