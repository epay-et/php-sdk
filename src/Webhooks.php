<?php

declare(strict_types=1);

namespace Epay;

use Epay\Exception\EpayConfigException;
use Epay\Exception\EpayValidationException;
use Epay\Exception\EpayWebhookSignatureException;

/** Webhook signature verification and event parsing. */
final class Webhooks
{
    /** Header ePay signs every delivery with. */
    public const SIGNATURE_HEADER = 'X-Epay-Signature';

    private const SIGNATURE_PREFIX = 'sha256=';

    /** Every webhook event name this SDK version documents. */
    public const EVENT_TYPES = [
        'payment.success',
        'payment.failed',
        'payment.cancelled',
        'payment.refunding',
        'payment.refunded',
        'payment.reversed',
    ];

    public function __construct(private readonly ?string $secret = null)
    {
    }

    /** Whether a webhook secret is configured. */
    public function isConfigured(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * Computes the expected signature for a payload.
     *
     * Useful for generating fixtures in your own tests.
     */
    public function sign(string $payload, ?string $secret = null): string
    {
        return hash_hmac('sha256', $payload, $this->requireSecret($secret));
    }

    /**
     * Checks an `X-Epay-Signature` header against the raw request body.
     *
     * Pass the **raw** body, never a re-encoded array: `json_encode` of a
     * decoded payload may reorder keys or change whitespace, which changes the
     * digest and rejects valid deliveries. The comparison is constant-time.
     */
    public function verify(string $payload, ?string $signatureHeader, ?string $secret = null): bool
    {
        $received = self::normalizeSignature($signatureHeader);
        if ($received === null) {
            return false;
        }

        return hash_equals($this->sign($payload, $secret), $received);
    }

    /**
     * Verifies a delivery and returns the decoded event.
     *
     * This is the entry point to use in a webhook handler: it fails closed, so
     * any event it returns had a valid signature.
     *
     * @return array<string, mixed>
     *
     * @throws EpayWebhookSignatureException if the header is missing or does not match.
     * @throws EpayValidationException if the verified body is not a JSON object.
     */
    public function constructEvent(
        string $payload,
        ?string $signatureHeader,
        ?string $secret = null,
    ): array {
        if (self::normalizeSignature($signatureHeader) === null) {
            throw new EpayWebhookSignatureException(sprintf(
                'Missing or malformed %s header. Expected "%s<hex>".',
                self::SIGNATURE_HEADER,
                self::SIGNATURE_PREFIX,
            ));
        }
        if (!$this->verify($payload, $signatureHeader, $secret)) {
            throw new EpayWebhookSignatureException(sprintf(
                '%s does not match the request body. Verify you are using the raw request '
                . 'body and the webhook secret from Developers -> Webhooks.',
                self::SIGNATURE_HEADER,
            ));
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new EpayValidationException(
                'Webhook body passed verification but is not valid JSON.',
                0,
                $error,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new EpayValidationException(
                'Webhook body passed verification but is not a JSON object.',
            );
        }

        return $decoded;
    }

    /**
     * Whether the event name is one this SDK version documents.
     *
     * @param array<string, mixed> $event
     */
    public static function isKnownEvent(array $event): bool
    {
        return in_array($event['event'] ?? null, self::EVENT_TYPES, true);
    }

    /** Reduces a header value to a bare lowercase hex digest. */
    private static function normalizeSignature(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $trimmed = trim($header);
        if (stripos($trimmed, self::SIGNATURE_PREFIX) === 0) {
            $trimmed = substr($trimmed, strlen(self::SIGNATURE_PREFIX));
        }

        if ($trimmed === '' || preg_match('/^[0-9a-f]+$/i', $trimmed) !== 1) {
            return null;
        }

        return strtolower($trimmed);
    }

    private function requireSecret(?string $override): string
    {
        $secret = $override ?? $this->secret;
        if ($secret === null || $secret === '') {
            throw new EpayConfigException(
                'No webhook secret configured. Pass ["webhook_secret" => …] to the client, '
                . 'set EPAY_WEBHOOK_SECRET, or supply the secret to this call.',
            );
        }

        return $secret;
    }
}
