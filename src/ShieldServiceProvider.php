<?php

namespace XaniaShield\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use XaniaShield\Laravel\Http\Middleware\ShieldProtect;

class ShieldServiceProvider extends ServiceProvider
{
    /**
     * Register bindings: merge config and bind the client as a singleton.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/shield.php', 'shield');

        $this->app->singleton(ShieldClient::class, fn () => new ShieldClient());
        $this->app->alias(ShieldClient::class, 'shield');
    }

    /**
     * Bootstrap: publish config, register the 'shield' route middleware, and
     * add Blade directives for the honeypot and timing fields.
     */
    public function boot(Router $router): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/shield.php' => $this->app->configPath('shield.php'),
            ], 'shield-config');
        }

        // Route middleware alias: ->middleware('shield') or ->middleware('shield:formId')
        $router->aliasMiddleware('shield', ShieldProtect::class);

        // @shieldHoneypot — renders a hidden honeypot field.
        Blade::directive('shieldHoneypot', function () {
            return "<?php echo \\XaniaShield\\Laravel\\ShieldClient::honeypotFieldHtml(); ?>";
        });

        // @shieldTiming — renders the signed timing field (if a secret is set).
        Blade::directive('shieldTiming', function () {
            return "<?php echo app(\\XaniaShield\\Laravel\\ShieldClient::class)->timingField(); ?>";
        });
    }
}
