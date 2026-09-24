<?php

declare(strict_types=1);

namespace Epay\Laravel;

use Epay\Resource\PaymentProviders;
use Epay\Resource\Payments;
use Epay\Resource\Transactions;
use Epay\Webhooks;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the ePay client.
 *
 * ```php
 * use Epay\Laravel\EpayFacade as Epay;
 *
 * $session = Epay::getFacadeRoot()->payments->initialize([...]);
 * ```
 *
 * Injecting {@see \Epay\Epay} is preferred: the resources are public readonly
 * properties, which a facade cannot proxy as cleanly as methods.
 *
 * @method static string|null mode()
 * @method static bool isSandbox()
 * @method static string baseUrl()
 * @method static mixed request(string $method, string $path, array<string, scalar|null> $query = [], ?array<string, mixed> $body = null, array<string, string> $headers = [], ?bool $retryable = null)
 *
 * @property-read Payments         $payments
 * @property-read Transactions     $transactions
 * @property-read PaymentProviders $paymentProviders
 * @property-read Webhooks         $webhooks
 *
 * @see \Epay\Epay
 */
final class EpayFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'epay';
    }
}
