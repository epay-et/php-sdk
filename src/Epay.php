<?php

declare(strict_types=1);

namespace Epay;

use Epay\Resource\PaymentProviders;
use Epay\Resource\Payments;
use Epay\Resource\Transactions;
use Epay\Support\Validator;
use Psr\Http\Client\ClientInterface;

/**
 * Client for the ePay Business API.
 *
 * The key prefix selects the environment — `sk_test_…` hits the sandbox,
 * `sk_live_…` moves real money — so there is no separate mode to configure.
 *
 * ```php
 * use Epay\Epay;
 *
 * $epay = new Epay(['api_key' => getenv('EPAY_SECRET_KEY')]);
 *
 * $session = $epay->payments->initialize([
 *     'amount' => '250.00',
 *     'currencyCode' => 'ETB',
 *     'customerPhone' => '+251911234567',
 *     'merchantReference' => 'order_123',
 * ], idempotencyKey: 'order_123');
 *
 * header('Location: ' . $session['checkoutUrl']);
 * ```
 *
 * Failed requests throw an {@see \Epay\Exception\EpayApiException} subclass.
 * Transient failures — `429`, `5xx`, and network errors — are retried with
 * exponential backoff before surfacing.
 */
final class Epay
{
    /** Package version, sent as part of the User-Agent. */
    public const VERSION = '0.1.0';

    /** Initialize, verify, and cancel payment sessions. */
    public readonly Payments $payments;

    /** List, retrieve, and inspect the timeline of transactions. */
    public readonly Transactions $transactions;

    /** Discover which providers and flows the account can use. */
    public readonly PaymentProviders $paymentProviders;

    /** Verify webhook signatures and parse events. */
    public readonly Webhooks $webhooks;

    private readonly Config $config;

    private readonly Transport $transport;

    /**
     * @param array{
     *     api_key?: string|null,
     *     webhook_secret?: string|null,
     *     base_url?: string|null,
     *     timeout?: float|int|null,
     *     max_retries?: int|null,
     *     default_headers?: array<string, string>
     * } $options Any option left unset falls back to `EPAY_SECRET_KEY`,
     *            `EPAY_WEBHOOK_SECRET`, or `EPAY_BASE_URL`.
     * @param ClientInterface|null $http An existing PSR-18 client to reuse, for
     *                                   connection pooling, proxies, or mTLS.
     *
     * @throws \Epay\Exception\EpayConfigException if no API key is available,
     *                                             the key is not a secret key,
     *                                             or an option is out of range.
     */
    public function __construct(array $options = [], ?ClientInterface $http = null)
    {
        $this->config = Config::fromOptions($options);
        $this->transport = new Transport($this->config, $http);

        $this->payments = new Payments($this->transport);
        $this->transactions = new Transactions($this->transport);
        $this->paymentProviders = new PaymentProviders($this->transport);
        $this->webhooks = new Webhooks($this->config->webhookSecret);
    }

    /**
     * Which environment the configured key targets, or null for a key whose
     * prefix this SDK version does not recognise.
     */
    public function mode(): ?string
    {
        return $this->config->mode;
    }

    /** Whether the configured key targets the sandbox. */
    public function isSandbox(): bool
    {
        return $this->config->mode === 'sandbox';
    }

    /** The API root every request is sent to. */
    public function baseUrl(): string
    {
        return $this->config->baseUrl;
    }

    /**
     * Escape hatch for endpoints this SDK version does not model yet.
     *
     * Applies the same authentication, timeout, retry, and error handling as
     * the typed methods.
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
        ?bool $retryable = null,
    ): mixed {
        return $this->transport->request(
            strtoupper($method),
            $path,
            $query,
            $body,
            $headers,
            $retryable,
        );
    }

    /** Renders the client without leaking the API key. */
    public function __toString(): string
    {
        return sprintf(
            'Epay(mode=%s, apiKey=%s)',
            $this->config->mode ?? 'unknown',
            Validator::maskSecret($this->config->apiKey),
        );
    }

    /** Keeps var_dump and debug output from printing the API key. */
    public function __debugInfo(): array
    {
        return [
            'mode' => $this->config->mode,
            'baseUrl' => $this->config->baseUrl,
            'apiKey' => Validator::maskSecret($this->config->apiKey),
        ];
    }
}
