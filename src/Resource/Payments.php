<?php

declare(strict_types=1);

namespace Epay\Resource;

use Epay\Exception\EpayValidationException;
use Epay\Support\Validator;
use Epay\Transport;

/** Operations on the payment lifecycle. */
final class Payments
{
    private const OPTIONAL_FIELDS = [
        'merchantReference',
        'email',
        'firstName',
        'lastName',
        'returnUrl',
        'callbackUrl',
    ];

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Creates a payment session and returns a hosted checkout URL.
     *
     * `amount`, `currencyCode`, and `customerPhone` are normalized before the
     * request is sent: amounts are formatted to two decimal places, currencies
     * uppercased, and phone numbers rewritten to `+251XXXXXXXXX`.
     *
     * Redirect the customer to `checkoutUrl` and store `reference` to reconcile
     * the payment later.
     *
     * When `idempotencyKey` is omitted a fresh one is generated per call, which
     * makes the SDK's internal retries safe but does not deduplicate across
     * separate calls. Pass your order id to get that guarantee.
     *
     * @param array{
     *     amount: string|int|float,
     *     currencyCode: string,
     *     customerPhone: string,
     *     merchantReference?: string,
     *     email?: string,
     *     firstName?: string,
     *     lastName?: string,
     *     returnUrl?: string,
     *     callbackUrl?: string
     * } $params
     *
     * @return array{reference: string, checkoutUrl: string, status: string, expiresAt: string}
     *
     * @throws EpayValidationException if a field fails local validation.
     */
    public function initialize(array $params, ?string $idempotencyKey = null): array
    {
        $key = $idempotencyKey ?? 'epay_' . bin2hex(random_bytes(16));
        if (strlen($key) > 255) {
            throw new EpayValidationException(sprintf(
                'idempotencyKey must be at most 255 characters, received %d',
                strlen($key),
            ));
        }

        $body = [
            'amount' => Validator::amount($params['amount']),
            'currencyCode' => Validator::currency($params['currencyCode']),
            'customerPhone' => Validator::phone($params['customerPhone']),
        ];

        // Only forward optional fields the caller actually set, so the API
        // applies its own defaults rather than receiving explicit nulls.
        foreach (self::OPTIONAL_FIELDS as $field) {
            if (isset($params[$field])) {
                $body[$field] = $params[$field];
            }
        }

        return $this->transport->request(
            method: 'POST',
            path: '/transactions/initialize',
            body: $body,
            headers: ['x-idempotency-key' => $key],
            // Safe to retry: every attempt carries the same idempotency key.
            retryable: true,
        );
    }

    /**
     * Returns the full receipt for a completed transaction.
     *
     * Call this before fulfilling an order. The API rejects any transaction
     * that is not yet `completed`, so use `Transactions::retrieve` first if you
     * would rather branch on status than catch an exception.
     *
     * @return array<string, mixed>
     *
     * @throws \Epay\Exception\EpayBadRequestException `400` — not yet completed.
     * @throws \Epay\Exception\EpayNotFoundException `404` — no such transaction.
     */
    public function verify(string $reference): array
    {
        return $this->transport->request(
            method: 'GET',
            path: '/transactions/' . rawurlencode(Validator::reference($reference)) . '/verify',
        );
    }

    /**
     * Cancels a `pending` or `processing` transaction.
     *
     * Fires a `payment.cancelled` webhook. Cancellation is irreversible: the
     * checkout session is invalidated and the customer can no longer pay.
     *
     * @throws \Epay\Exception\EpayBadRequestException `400` — not cancellable.
     * @throws \Epay\Exception\EpayNotFoundException `404` — no such transaction.
     */
    public function cancel(string $reference): void
    {
        $this->transport->request(
            method: 'POST',
            path: '/transactions/' . rawurlencode(Validator::reference($reference)) . '/cancel',
            // Cancelling twice is rejected with 400 rather than duplicated, but
            // the endpoint takes no idempotency key, so a retried attempt could
            // act on a request the server already processed.
            retryable: false,
        );
    }
}
