<?php

declare(strict_types=1);

namespace Epay\Laravel;

use Epay\Epay;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the ePay client as a Laravel singleton.
 *
 * The package is auto-discovered, so no manual registration is needed. Set
 * `EPAY_SECRET_KEY` and `EPAY_WEBHOOK_SECRET` in your `.env` and inject
 * {@see Epay} anywhere:
 *
 * ```php
 * public function __construct(private readonly Epay $epay) {}
 * ```
 *
 * Publish the config file to change defaults:
 *
 * ```sh
 * php artisan vendor:publish --tag=epay-config
 * ```
 */
final class EpayServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /** Registers the container bindings. */
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'epay');

        $this->app->singleton(Epay::class, static function ($app): Epay {
            $config = $app->make('config')->get('epay', []);

            return new Epay([
                'api_key' => $config['api_key'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
                'timeout' => isset($config['timeout']) ? (float) $config['timeout'] : null,
                'max_retries' => isset($config['max_retries']) ? (int) $config['max_retries'] : null,
            ]);
        });

        // Alias for the facade and for `app('epay')`.
        $this->app->alias(Epay::class, 'epay');
    }

    /** Wires up config publishing. */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::configPath() => $this->app->configPath('epay.php')], 'epay-config');
        }
    }

    /**
     * Services this deferred provider offers.
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return [Epay::class, 'epay'];
    }

    private static function configPath(): string
    {
        return dirname(__DIR__, 2) . '/config/epay.php';
    }
}
