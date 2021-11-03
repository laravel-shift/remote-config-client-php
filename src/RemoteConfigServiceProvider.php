<?php

namespace Linx\RemoteConfigClient;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Laravel\Lumen\Application;

class RemoteConfigServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(RemoteConfig::class, function ($app) {
            $this->configure($app);

            $config = $app->make('config')->get('remote-config');
            $remoteConfig = new RemoteConfig($config);
            $remoteConfig->setCache(Cache::getFacadeRoot()->store());

            $loggerClass = $config['logger-class'] ?? '';
            $remoteConfig->setLoggerClass($loggerClass);

            return $remoteConfig;
        });
    }

    private function configure($app)
    {
        $source = dirname(__DIR__).'/config/remote-config.php';

        if ($app instanceof Application) {
            $app->configure('remote-config');
        }

        $this->mergeConfigFrom($source, 'remote-config');
    }
}
