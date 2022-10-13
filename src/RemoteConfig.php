<?php

namespace Linx\RemoteConfigClient;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Psr\SimpleCache\CacheInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;

class RemoteConfig
{
    const REQUEST_TIMEOUT = 3; // seconds

    const REQUEST_URI = '/api/v1/configs/%s/%s/%s';

    const CACHE_TTL = -1;

    private $host;

    private $username;

    private $password;

    private $application;

    private $environment;

    private $cacheLifeTime;

    private $httpClient;

    private $cache;

    /** @var string|null $loggerClass */
    private $loggerClass;

    public function __construct(array $credentials)
    {
        $this->host = $this->addScheme($credentials['host']);
        $this->username = $credentials['username'];
        $this->password = $credentials['password'];
        $this->application = $credentials['application'];
        $this->environment = $credentials['environment'];
        $this->cacheLifeTime = $credentials['cache-life-time'] ?? self::CACHE_TTL;
    }

    private function logError(string $message, array $data = [])
    {
        if (!$this->loggerClass) {
            return;
        }
        $class = $this->loggerClass;
        $class::error($message, $data);
    }

    public function getClientConfig(string $client, string $config = null)
    {
        $uri = $this->buildUri($this->application, $client, $this->environment);
        $cacheKey = $this->buildCacheKey($uri);
        $cache = $this->getCache();
        $usedRedis = true;

        try {
            if (method_exists($cache, 'tags')) {
                $cache = $cache->tags($this->getCacheTags($client));
            }
            $data = $cache->get($cacheKey);
        } catch (\Exception $th) {
            $usedRedis = false;
            $this->logError('Could not open cache from Redis', [
                'client' => $client,
                'error_message' => $th->getMessage(),
                'uri' => $uri,
            ]);
        }
        if (null === $data) {
            $data = $this->httpGet($uri);
            if ($usedRedis) {
                $cache->set($cacheKey, $data, $this->cacheLifeTime);
            }
        }
        return Arr::get($data, $config, null);
    }

    private function getCacheTags($client)
    {
        return [$client, "{$client}-remoteconfig"];
    }

    private function httpGet($path)
    {
        try {
            $response = $this->getHttpClient()->request(
                'GET',
                $this->host . $path,
                [
                    'auth' => [$this->username, $this->password],
                    'connect_timeout' => self::REQUEST_TIMEOUT,
                    'read_timeout' => self::REQUEST_TIMEOUT,
                    'timeout' => self::REQUEST_TIMEOUT,
                ]
            );

            $data = json_decode($response->getBody(), true);
        } catch (ConnectException $e) {
            $this->logError('Could not get data from Remote Config API', [
                'error_message' => $e->getMessage(),
                'path' => $path
            ]);

            throw $e;
        } catch (RequestException $re) {
            if ($re->hasResponse()) {
                $this->logError('Could not get data from Remote Config API', [
                    'error_message' => $re->getMessage(),
                    'path' => $path
                ]);

                $exceptionMessage = $re->getMessage();

                if ($re->getResponse()->getStatusCode() === 404)
                    $exceptionMessage = "clientId is invalid!";

                throw new HttpException($re->getResponse()->getStatusCode(), $exceptionMessage);
            }
        }

        return $data;
    }

    public function getHttpClient()
    {
        if (!empty($this->httpClient)) {
            return $this->httpClient;
        }

        $httpClient = new Client();

        return $this->httpClient = $httpClient;
    }

    public function getCache()
    {
        return $this->cache;
    }

    public function setHttpClient(Client $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function setCache(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function setLoggerClass(string $loggerClass)
    {
        if ($loggerClass instanceof LoggerInterface) {
            return;
        }
        $this->loggerClass = $loggerClass;
    }

    public function updateCacheData(string $client, array $data, bool $canExpire = true)
    {
        $uri = $this->buildUri($this->application, $client, $this->environment);
        $cacheKey = $this->buildCacheKey($uri);
        $cache = $this->getCache();

        if (method_exists($cache, 'tags')) {
            $cache = $cache->tags($this->getCacheTags($client));
        }

        $cacheLifeTime = $canExpire ? $this->cacheLifeTime : null;

        $cache->set($cacheKey, $data, $cacheLifeTime);
    }

    private function addScheme($url, $scheme = 'http://')
    {
        return parse_url($url, PHP_URL_SCHEME) === null
            ? $scheme . $url
            : $url;
    }

    private function buildUri(
        string $application,
        string $client,
        string $environment
    ): string {
        return sprintf(
            self::REQUEST_URI,
            $application,
            $client,
            $environment
        );
    }

    private function buildCacheKey(string $uri): string
    {
        return md5($uri);
    }
}
