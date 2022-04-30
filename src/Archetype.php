<?php 

namespace Archetype;
use Archetype\Exceptions\ArchetypeException;
use Exception;
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
            "url_apikey" => $request->query('apikey') ? ($request->query('apikey')):  null,
            "body_apikey" =>  $request->input('apikey') ? ($request->input('apikey')): null,
            "header_apikey" => $request->header('Authorization') || $request->bearerToken() ?? null,
        ];
        $timestamp = microtime(true);

        $response = static::requestArchetype('/sdk/v2/authorize', $payload);
        // now send log to the system asynchronisly
        Archetype::sendToSystem(array_merge($payload, [
            'timestamp' => $timestamp,
            'status_code' => $response->status()
        ]), $request);
        
       
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
            
            
            
            static::throwStatusCodeException($response->status());

            return $response;
        } catch (\Exception $e) {
            if ($e instanceof ArchetypeException)
                throw new ArchetypeException($e->getMessage());
            throw new ArchetypeException("Could not connect to Archetype.");
        }
        
    }

    public static function sendToSystem($payload, $request, $complete = false)
    {
        static::checkArchetypeKeys();
        if ($complete) {
            $body = $payload;
        } else {        
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
        }

        $client = new Client();
        $request = new \GuzzleHttp\Psr7\Request('POST', static::logUrl, [
            'json' => $body,
            'headers' => [
                "X-Archetype-SecretKey" =>  config('archetype.secret_key'),
                "X-Archetype-AppID" => config('archetype.app_id')
            ],
        ]);
        try {
            $promise = $client->sendAsync($request)
                ->otherwise(function (\GuzzleHttp\Exception\RequestException $reason) {
                $status = $reason->getResponse()->getStatusCode();
                static::throwStatusCodeException($status);
            });
            $promise->wait();
        } catch (\Exception $e) {
            if ($e instanceof ArchetypeException)
                throw new ArchetypeException($e->getMessage());
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
        

        static::sendToSystem($body, $request, true);
    }
    /**
     * @method checkArchetypeKeys
     * Check archetype keys and see if they are set with the environment wether it's on testing or production mode
     */
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
    /**
     * @method throwStatusCodeException
     * Throw an exception based on the status code.
     */
    public function throwStatusCodeException($status)
    {
        if ($status == 400) {
            throw new ArchetypeException("You've exceeded your quota or rate limit.");
        } elseif ($status == 401) {
            throw new ArchetypeException("You don't have access to this endpoint.");
        } elseif ($status == 403) {
            throw new ArchetypeException("The supplied apikey is invalid or expired.");
        } elseif($status == 404) {
            throw new ArchetypeException("The endpoint you're trying to access doesn't exist.");
        } elseif(! in_array($status, [200, 201, 202, 203, 204, 205, 206, 207, 208, 226])) {
            throw new ArchetypeException("Something went wrong.");
        }
    }
}