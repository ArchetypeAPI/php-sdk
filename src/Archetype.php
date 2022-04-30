<?php 

namespace Archetype;
use Archetype\Exceptions\ArchetypeException;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Archetype
{
    /**
     * @constant string baseEndpoint
     */
    private static $baseEndpoint = 'https://api.archetype.dev';

    /**
     * @constant string logUrl
     */
    const logUrl = 'https://pipeline.archetype.dev/v1/query';
    

    public static function authenticate($request)
    {
        $payload = [
            "path" =>  $request->path() == '/' ? '/': '/' . $request->path(),
            "url_apikey" => $request->query('access_token') ? ($request->query()):  null,
            "body_apikey" =>  $request->input('access_token') ? ($request->input()): null,
            "header_apikey" => $request->header('Authorization') || $request->bearerToken() ? ($request->header()): null,
        ];
        $timestamp = microtime(true);

        $response = static::requestArchetype('/sdk/v2/authorize', $payload);
        // now send log to the system asynchronisly
        Archetype::sendToSystem(array_merge($payload, [
            'timestamp' => $timestamp,
            'status_code' => $response->status()
        ]), $request);
        
        
        if ($response->status() == 400) {
            throw new ArchetypeException("You've exceeded your quota or rate limit.");
        } elseif ($response->status() == 401) {
            throw new ArchetypeException("You don't have access to this endpoint.");
        } elseif ($response->status() == 403) {
            throw new ArchetypeException("The supplied apikey is invalid or expired.");
        }
        
       
        return $response;
    }

    protected static function requestArchetype($uri, array $payload = [], $method = 'post')
    {
        static::checkArchetypeKeys();
        try {
            $response = Http::withHeaders([
                "X-Archetype-SecretKey" =>  config('archetype.secret_key'),
                "X-Archetype-AppID" => config('archetype.app_id'),
                "Content-Type" => "application/json",
            ])->$method(static::$baseEndpoint . $uri, $payload);
            return $response;
        } catch (\Exception $e) {
            throw new ArchetypeException("Could not connect to Archetype.");
        }
        
    }

    public static function sendToSystem($payload, $request)
    {
        static::checkArchetypeKeys();
        $body = [
            'status_code' => $payload['status_code'],
            'duration' => (microtime(true) - $payload['timestamp']) / 1000,
            'size' => 0,
            'path' => $payload['path'],
            'method' => $request->method(),
            'ip' => $request->ip(),
            'headers' => $request->header('apikey'),
            'body' => $request->input('apikey'),
            'args' => $request->query('apikey'),
            'tier' => '',
            'app_id' => config('archetype.app_id'),
            'user_id' => $payload['header_apikey'],
            'timestamp' => time(),
        ];

        $client = new Client();
        $request = new \GuzzleHttp\Psr7\Request('POST', static::logUrl, [
            'json' => $body,
            'headers' => [
                "X-Archetype-SecretKey" =>  config('archetype.secret_key'),
                "X-Archetype-AppID" => config('archetype.app_id')
            ],
        ]);
        try {
            $promise = $client->sendAsync($request);
            $promise->wait();
        } catch (\Exception $e) {
            throw new ArchetypeException("Could not connect to Archetype.");
        }
        
    }

    public static function getProducts()
    {
        return static::requestArchetype('/sdk/v1/tiers')->json();
    }

    public static function getUser($uid)
    {
        return static::requestArchetype('/sdk/v1/user', ['custom_uid' => $uid])->json();
    }

    public static function createCheckoutSession($uid, $productId)
    {
        $res = static::requestArchetype('/sdk/v1/create-checkout-session', ['custom_uid' => $uid, 'tier_id' => $productId])
            ->json();

        return $res['url'] ?? $res;
    }

    public static function cancelSubscription($uid)
    {
        return static::requestArchetype('/sdk/v1/cancel-subscription', ['custom_uid' => $uid])
            ->json();
    }

    public static function registerUser($uid, $name, $email)
    {
        return static::requestArchetype('/sdk/v1/create-user', 
            ['custom_uid' => $uid, 'name' => $name, 'email' => $email]
            )
            ->json();
    }
    public static function track($cuid = null, Request $request)
    {
        static::checkArchetypeKeys();
        $body = [
            'status_code' => 200,
            'duration' => 0,
            'size' => 0,
            'path' => $request->path() == '/' ? '/': '/' . $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'headers' => $request->header('apikey'),
            'body' => $request->input('apikey'),
            'args' => $request->query('apikey'),
            'tier' => '',
            'app_id' => config('archetype.app_id'),
            'user_id' => $cuid,
            'timestamp' => time(),
        ];
        $client = new Client();
        $request = new \GuzzleHttp\Psr7\Request('POST', static::logUrl, [
            'json' => $body,
            'headers' => [
                "X-Archetype-SecretKey" =>  config('archetype.secret_key'),
                "X-Archetype-AppID" => config('archetype.app_id')
            ],
        ]);
        $promise = $client->sendAsync($request);
        $promise->wait();
    }
    public static function checkArchetypeKeys()
    {
        if (! config('archetype.app_id') || ! config('archetype.secret_key'))
            throw new ArchetypeException('Archetype app_id and secret_key are not specified in config/archetype.php');
        
        if (strpos(config('archetype.secret_key'), 'sk_test') !== false)
            static::$baseEndpoint = 'https://test.archetype.dev';    
        elseif (strpos(config('archetype.secret_key'), 'sk_prod') !== false)
            static::$baseEndpoint = 'https://api.archetype.dev';   
        else
            throw new ArchetypeException('Archetype secret_key is not valid.');  
    }
}