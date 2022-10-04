<?php

namespace Linx\RemoteConfigClient;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Psr\SimpleCache\CacheInterface;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Arr;

class RemoteConfig
{
    const REQUEST_TIMEOUT = 3; // seconds

    const REQUEST_URI = '/api/v1/configs/%s/%s/%s';

    const RC_CACHE_FALLBACK = 'RC_CACHE_FALLBACK';
    const RC_CACHE_FALLBACK_TTL = 604800; //one week
    const CACHE_TTL = -1;

    private $host;

    private $username;

    private $password;

    private $application;

    private $environment;

    private $cacheLifeTime;

    private $httpClient;

    private $cache;

    private $cacheFallback;

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
        $this->cacheDirectory = $credentials['cache-directory'] ?? null;
        $this->cacheFallbackDirectory = $credentials['cache-fallback-directory'] ?? null;
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

        try {
            if (method_exists($cache, 'tags')) {
                $cache = $cache->tags($this->getCacheTags($client));
            }
            $data = $cache->get($cacheKey);
            if (!$this->cacheFallback()->has($cacheKey) && null !== $data) {
                $this->cacheFallback()->set($cacheKey, $data, self::RC_CACHE_FALLBACK_TTL);
            }
        } catch (\Exception $th) {
            $this->logError('Could not open cache from Redis', [
                'client' => $client,
                'error_message' => $th->getMessage(),
                'uri' => $uri,
            ]);
        }
        if (null === $data) {
            $data = $this->httpGet($uri);
        }
        return Arr::get($data, $config, null);
    }

    private function getCacheTags($client)
    {
        return [$client, "{$client}-remoteconfig"];
    }

    private function httpGet($path)
    {
        $cache = $this->cacheFallback();
        $cacheKey = $this->buildCacheKey($path);
        $timeout = self::REQUEST_TIMEOUT;

        $currentCache = $cache->get($cacheKey);

        try {
            $response = $this->getHttpClient()->request(
                'GET',
                $this->host . $path,
                [
                    'auth' => [$this->username, $this->password],
                    'connect_timeout' => $timeout,
                    'read_timeout' => $timeout,
                    'timeout' => $timeout,
                ]
            );

            $currentCache = json_decode($response->getBody(), true);
            $cache->set($cacheKey, $currentCache, self::RC_CACHE_FALLBACK_TTL);
        } catch (ConnectException $e) {
            $this->logError('Could not get data from Remote Config API', [
                'current_cache' => $currentCache,
                'error_message' => $e->getMessage(),
                'path' => $path,
                'timeout' => $timeout,
            ]);
            if (empty($currentCache)) {
                throw $e;
            }

            $cache->set($cacheKey, $currentCache, self::RC_CACHE_FALLBACK_TTL);
        }

        return $currentCache;
    }

    private function cacheFallback()
    {
        if (!empty($this->cacheFallback)) {
            return $this->cacheFallback;
        }

        return $this->cacheFallback = new FilesystemCache(
            self::RC_CACHE_FALLBACK,
            self::RC_CACHE_FALLBACK_TTL,
            $this->cacheFallbackDirectory
        );
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
        if (!empty($this->cache)) {
            return $this->cache;
        }

        $cache = new FilesystemCache('', $this->cacheLifeTime, $this->cacheDirectory);

        return $this->cache = $cache;
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
