<?php

declare(strict_types=1);

namespace Epay\Resource;

use Epay\Exception\EpayValidationException;
use Epay\Support\Validator;
use Epay\Transport;

/** Payment lifecycle: hosted checkout, direct payments, SMS links, receipts, and cancellation. */
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
        $key = self::resolveIdempotencyKey($idempotencyKey);

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
     * Charges a customer's wallet or bank account directly, with no checkout page.
     *
     * ePay pushes an authorization prompt (USSD menu, SMS OTP, or in-app
     * notification) to the customer's phone. This returns as soon as the prompt
     * is sent, with `status` `pending`; the outcome arrives as a
     * `payment.success` or `payment.failed` webhook once the customer acts.
     *
     * Choose `pspCode` and `flowCode` from
     * {@see PaymentProviders::listForDirectPayment()}.
     *
     * Safe to retry: every attempt carries the same idempotency key. Pass your
     * order id so a double-submitted form cannot charge twice.
     *
     * @param array{
     *     amount: string|int|float,
     *     currencyCode: string,
     *     customerPhone: string,
     *     pspCode: string,
     *     flowCode?: string,
     *     merchantReference?: string,
     *     callbackUrl?: string
     * } $params
     *
     * @return array{reference: string, status: string, chargeAmount: string, feeAmount: string, attempt: array<string, mixed>}
     *
     * @throws EpayValidationException if a field fails local validation.
     * @throws \Epay\Exception\EpayBadRequestException `400` — amount, currency, or provider rejected.
     * @throws \Epay\Exception\EpayPermissionDeniedException `403` — direct payments are not enabled.
     */
    public function directPayment(array $params, ?string $idempotencyKey = null): array
    {
        $key = self::resolveIdempotencyKey($idempotencyKey);

        $body = [
            'amount' => Validator::amount($params['amount']),
            'currencyCode' => Validator::currency($params['currencyCode']),
            'customerPhone' => Validator::phone($params['customerPhone']),
            'pspCode' => self::requireCode($params['pspCode'], 'pspCode'),
        ];
        if (isset($params['flowCode'])) {
            $body['flowCode'] = self::requireCode($params['flowCode'], 'flowCode');
        }
        foreach (['merchantReference', 'callbackUrl'] as $field) {
            if (isset($params[$field])) {
                $body[$field] = $params[$field];
            }
        }

        return $this->transport->request(
            method: 'POST',
            path: '/transactions/direct',
            body: $body,
            headers: ['x-idempotency-key' => $key],
            // Safe to retry: every attempt carries the same idempotency key.
            retryable: true,
        );
    }

    /**
     * Texts the customer a signed link to the hosted checkout page.
     *
     * With an `amount`, the amount is signed into the link and cannot be
     * changed; without one, the customer enters it. The link is valid for 1
     * hour. Never retried automatically, so a customer is never texted twice.
     *
     * @param array{customerPhone: string, currencyCode: string, amount?: string|int|float} $params
     *
     * @return array{message: string, checkoutUrl: string, customerPhone: string, amount: ?string, currencyCode: string}
     *
     * @throws EpayValidationException if a field fails local validation.
     * @throws \Epay\Exception\EpayBadRequestException `400` — phone number or currency rejected.
     */
    public function sendSmsLink(array $params): array
    {
        $body = [
            'customerPhone' => Validator::phone($params['customerPhone']),
            'currencyCode' => Validator::currency($params['currencyCode']),
        ];
        if (isset($params['amount'])) {
            $body['amount'] = Validator::amount($params['amount']);
        }

        return $this->transport->request(
            method: 'POST',
            path: '/transactions/sms-link',
            body: $body,
            // The endpoint takes no idempotency key, so a retry after a lost
            // response would text the customer a second time.
            retryable: false,
        );
    }

    /**
     * Returns the receipt for a completed, failed, or cancelled transaction.
     *
     * Use it for records, audits, and customer-facing receipts. To confirm a
     * payment before fulfilling an order, use {@see verify()}.
     *
     * @return array<string, mixed>
     *
     * @throws \Epay\Exception\EpayBadRequestException `400` — still pending or processing.
     * @throws \Epay\Exception\EpayNotFoundException `404` — no such transaction.
     */
    public function receipt(string $reference): array
    {
        return $this->transport->request(
            method: 'GET',
            path: '/transactions/' . rawurlencode(Validator::reference($reference)) . '/receipt',
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

    /** Uses the caller's idempotency key, or generates one for this call. */
    private static function resolveIdempotencyKey(?string $idempotencyKey): string
    {
        $key = $idempotencyKey ?? 'epay_' . bin2hex(random_bytes(16));
        if (strlen($key) > 255) {
            throw new EpayValidationException(sprintf(
                'idempotencyKey must be at most 255 characters, received %d',
                strlen($key),
            ));
        }

        return $key;
    }

    /** Validates a provider or flow code is present, and trims it. */
    private static function requireCode(mixed $value, string $field): string
    {
        $code = is_string($value) ? trim($value) : '';
        if ($code === '') {
            throw new EpayValidationException(sprintf('%s must be a non-empty string', $field));
        }

        return $code;
    }
}
