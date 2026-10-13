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
     * Returns every provider available to the account, each with its flows.
     *
     * Requires the `get_platform_payment_provider` permission.
     *
     * @return list<array{
     *     providerCode: string,
     *     providerName: ?string,
     *     providerCategory: ?string,
     *     flows: list<array{flowCode: string, isDefault: bool, supportsDirectPayment: bool}>
     * }>
     *
     * @throws \Epay\Exception\EpayPermissionDeniedException `403` — key lacks the permission.
     */
    public function getAll(): array
    {
        $response = $this->transport->request(method: 'GET', path: '/payment-providers');

        return $response['data'] ?? [];
    }

    /**
     * Returns the providers that can take a direct payment.
     *
     * Only their direct-payment flows are kept, and providers with none are left
     * out. Pass a provider's `providerCode` as `pspCode`, and pre-select the
     * flow whose `isDefault` is true.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDirectPayment(): array
    {
        $result = [];
        // Read loosely rather than trusting the documented shape, so a provider
        // missing `flows` is skipped instead of raising a warning.
        /** @var array<string, mixed> $provider */
        foreach ($this->getAll() as $provider) {
            /** @var list<array<string, mixed>> $declared */
            $declared = is_array($provider['flows'] ?? null) ? $provider['flows'] : [];
            $flows = array_values(array_filter(
                $declared,
                static fn (array $flow): bool => (bool) ($flow['supportsDirectPayment'] ?? false),
            ));
            if ($flows !== []) {
                $provider['flows'] = $flows;
                $result[] = $provider;
            }
        }

        return $result;
    }

    /**
     * Returns the same list as {@see getAll()}.
     *
     * @deprecated The API no longer reports a per-provider enabled state:
     *             every provider it returns is available to the account. Use
     *             getAll() or listForDirectPayment(). This will be removed in a
     *             future version.
     *
     * @return list<array<string, mixed>>
     */
    public function listEnabled(): array
    {
        return $this->getAll();
    }
}
