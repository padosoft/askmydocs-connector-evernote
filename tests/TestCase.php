<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorEvernote\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorEvernote\EvernoteServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The base package ships the connector_installations +
        // connector_credentials migrations the Feature tests need.
        $this->loadMigrationsFrom(
            dirname((new \ReflectionClass(ConnectorServiceProvider::class))->getFileName(), 2)
            .DIRECTORY_SEPARATOR.'database'
            .DIRECTORY_SEPARATOR.'migrations'
        );
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ConnectorServiceProvider::class,
            EvernoteServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }
}
