<?php

namespace Linx\RemoteConfigClient;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Laravel\Lumen\Application;

class RemoteConfigServiceProvider extends ServiceProvider
{
    public function __construct($app)
    {
        parent::__construct($app);

        $source = dirname(__DIR__).'/config/remote-config.php';

        if ($this->app instanceof Application) {
            $this->app->configure('remote-config');
        }

        $this->mergeConfigFrom($source, 'remote-config');
    }

    public function register()
    {
        $this->app->singleton(RemoteConfig::class, function ($app) {
            $config = $app->make('config')->get('remote-config');
            $remoteConfig = new RemoteConfig($config);
            $remoteConfig->setCache(Cache::getFacadeRoot()->store());

            return $remoteConfig;
        });
    }
}
