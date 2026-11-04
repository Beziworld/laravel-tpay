<?php

declare(strict_types=1);

namespace Beziworld\Tpay;

use Beziworld\Tpay\Auth\AccessTokenProvider;
use Beziworld\Tpay\Notifications\CertificateProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Tpay client, token cache, notification verification and the notification route.
 */
final class TpayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tpay.php', 'tpay');

        $this->app->singleton(TpayConfig::class, static function (Application $app): TpayConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make(Config::class)->get('tpay', []);

            return TpayConfig::fromArray($config);
        });

        $this->app->singleton(AccessTokenProvider::class, static fn(Application $app): AccessTokenProvider => new AccessTokenProvider(
            $app->make(HttpFactory::class),
            self::cache($app),
            $app->make(TpayConfig::class),
        ));

        $this->app->singleton(CertificateProvider::class, static fn(Application $app): CertificateProvider => new CertificateProvider(
            $app->make(HttpFactory::class),
            self::cache($app),
            $app->make(TpayConfig::class),
        ));

        $this->app->singleton(TpayClient::class, static fn(Application $app): TpayClient => new TpayClient(
            $app->make(HttpFactory::class),
            $app->make(AccessTokenProvider::class),
            $app->make(TpayConfig::class),
            static fn(): ?string => self::notificationUrl($app),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/tpay.php' => $this->app->configPath('tpay.php'),
            ], 'tpay-config');
        }

        if ((bool) $this->app->make(Config::class)->get('tpay.notifications.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/tpay.php');
        }
    }

    private static function cache(Application $app): Cache
    {
        return $app->make(CacheFactory::class)->store($app->make(TpayConfig::class)->cacheStore);
    }

    private static function notificationUrl(Application $app): ?string
    {
        $name = $app->make(Config::class)->get('tpay.notifications.name');

        if (! is_string($name) || ! $app->make(Router::class)->has($name)) {
            return null;
        }

        return $app->make(UrlGenerator::class)->route($name);
    }
}
