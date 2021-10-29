<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Linx\RemoteConfigClient\RemoteConfig;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Simple\FilesystemCache;

class RemoteConfigTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Mockery::close();
    }

    public function testGetClientConfig()
    {
        $remoteConfig = new RemoteConfig([
            'host' => 'http://remote-config',
            'username' => 'username@email.com',
            'password' => 'password',
            'application' => 'application',
            'environment' => 'environment',
        ]);

        $cacheMock = Mockery::mock(FilesystemCache::class);
        $httpClientMock = Mockery::mock(Client::class);

        $remoteConfig->setHttpClient($httpClientMock);
        $remoteConfig->setCache($cacheMock);

        $cacheMock
            ->shouldReceive('has')
            ->with(md5('/api/v1/configs/application/client/environment'))
            ->once()
            ->andReturn(false);

        $httpClientMock
            ->shouldReceive('request')
            ->with('GET', 'http://remote-config/api/v1/configs/application/client/environment', [
                'auth' => ['username@email.com', 'password'],
                'connect_timeout' => RemoteConfig::REQUEST_TIMEOUT,
                'read_timeout' => RemoteConfig::REQUEST_TIMEOUT,
                'timeout' => RemoteConfig::REQUEST_TIMEOUT,
                ])
            ->once()
            ->andReturnSelf()
            ->getMock()
            ->shouldReceive('getBody')
            ->once()
            ->andReturn('{"key": "value"}');

        $cacheMock
            ->shouldReceive('set')
            ->with(md5('/api/v1/configs/application/client/environment'), ['key' => 'value'], -1)
            ->once()
            ->andReturn(true);

        $remoteConfig->getClientConfig('client');
    }

    public function testLogWhenFails()
    {
        $remoteConfig = new RemoteConfig([
            'host' => 'http://remote-config',
            'username' => 'username@email.com',
            'password' => 'password',
            'application' => 'application2',
            'environment' => 'environment',
        ]);

        $cacheMock = Mockery::mock(FilesystemCache::class);
        $httpClientMock = Mockery::mock(Client::class);

        $remoteConfig->setHttpClient($httpClientMock);
        $remoteConfig->setCache($cacheMock);
        $remoteConfig->setLoggerClass(Log::class);

        $cacheMock
            ->shouldReceive('has')
            ->with(md5('/api/v1/configs/application2/client/environment'))
            ->once()
            ->andThrow(Exception::class, 'Testing Exception');

        Log::shouldReceive('error')
            ->withArgs(['Could not open cache from Redis', [
                'client' => 'client',
                'error_message' => 'Testing Exception',
                'uri' => '/api/v1/configs/application2/client/environment',
            ]])
            ->once()
            ->getMock()
            ->shouldReceive('error')
            ->withArgs(['Could not get data from Remote Config API', [
                'current_cache' => null,
                'error_message' => 'Testing ConnectException',
                'path' => '/api/v1/configs/application2/client/environment',
                'timeout' => 3,
            ]])
            ->once();

        $httpClientMock
            ->shouldReceive('request')
            ->with('GET', 'http://remote-config/api/v1/configs/application2/client/environment', [
                'auth' => ['username@email.com', 'password'],
                'connect_timeout' => RemoteConfig::REQUEST_TIMEOUT,
                'read_timeout' => RemoteConfig::REQUEST_TIMEOUT,
                'timeout' => RemoteConfig::REQUEST_TIMEOUT,
            ])
            ->once()
            ->andThrow(
                ConnectException::class,
                'Testing ConnectException',
                new Request('http://remote-config', '/api/v1/configs/application2/client/environment')
            );

        self::expectException(ConnectException::class);
        $remoteConfig->getClientConfig('client');
    }
}
