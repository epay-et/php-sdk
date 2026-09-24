<?php

declare(strict_types=1);

namespace Epay\Resource;

use Epay\Transport;

/**
 * Read operations over the account's payment providers.
 *
 * Both endpoints are mode-aware: a `sk_test_…` key returns sandbox providers
 * and a `sk_live_…` key returns live ones.
 */
final class PaymentProviders
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Returns a compact provider list suited to dropdowns.
     *
     * Requires the `list_platform_payment_provider` permission.
     *
     * @return list<array{providerCode: string, providerName: ?string, flowCode: string}>
     *
     * @throws \Epay\Exception\EpayPermissionDeniedException `403` — key lacks the permission.
     */
    public function list(): array
    {
        $response = $this->transport->request(method: 'GET', path: '/payment-providers/list');

        return $response['data'] ?? [];
    }

    /**
     * Returns every provider with its category and enabled state.
     *
     * Disabled providers are included so your UI can show them as unavailable
     * rather than having them silently disappear.
     *
     * Requires the `get_platform_payment_provider` permission.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \Epay\Exception\EpayPermissionDeniedException `403` — key lacks the permission.
     */
    public function getAll(): array
    {
        $response = $this->transport->request(method: 'GET', path: '/payment-providers');

        return $response['data'] ?? [];
    }

    /**
     * Returns only the providers the account can currently route through.
     *
     * @return list<array<string, mixed>>
     */
    public function listEnabled(): array
    {
        return array_values(array_filter(
            $this->getAll(),
            static fn (array $provider): bool => (bool) ($provider['isEnabled'] ?? false),
        ));
    }
}
