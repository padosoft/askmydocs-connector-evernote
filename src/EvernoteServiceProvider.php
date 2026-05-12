<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorEvernote;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Evernote connector package.
 *
 * Merges the Evernote provider block into the host's `connectors.php`
 * config tree (under `providers.evernote`). Publishes both the config
 * fragment + the brand asset for hosts that want to customise either.
 *
 * Auto-registration into the connector registry happens at the base
 * package level via composer's `extra.askmydocs.connectors` discovery
 * — the entry is in this package's composer.json.
 */
class EvernoteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/evernote.php', 'connectors.providers.evernote');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/evernote.php' => config_path('connectors-evernote.php'),
            ], 'connector-evernote-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-evernote-assets');
        }
    }
}
