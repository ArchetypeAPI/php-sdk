<?php 

namespace Archetype;
use Archetype\Exceptions\ArchetypeException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Archetype
{
    /**
     * @constant string baseEndpoint
     */
    private static $baseEndpoint = 'https://api.archetype.dev';

    private static $appId;

    private static $appSecret;

    /**
     * @constant string logUrl
     */
    const logUrl = 'https://pipeline.archetype.dev/v1/query';
    
    public static function init(array $options, $mute = false)
    {
        if (! $mute) {
            if (! isset($options['app_id']) || ! isset($options['secret_key']))
                throw new ArchetypeException('You must provide an app_id and secret_key');
            static::$appId = $options['app_id'];
            static::$appSecret = $options['secret_key'];
            static::checkArchetypeKeys();
        } else {
            static::$appId = $options['app_id'];
            static::$appSecret = $options['secret_key'];
        }
        

        return $options;
    }

    public static function authenticate($request = null)
    {
        if ($request instanceof Request):
            $payload = [
                "path" =>  $request->path() == '/' ? '/': '/' . $request->path(),
                "url_apikey" => $request->query('apikey') ? ($request->query('apikey')):  null,
                "body_apikey" =>  $request->input('apikey') ? ($request->input('apikey')): null,
                "header_apikey" => $request->header('apikey') ?? ($request->query('apikey') ?? ($request->input('apikey') ?? null) ),
            ];
        else: 
            $payload = [
                "path" =>  $_SERVER['SCRIPT_NAME'],
                "url_apikey" => $_GET['apikey'] ?? null,
                "body_apikey" =>  $_POST['apikey'] ?? null,
                "header_apikey" => static::getHeaders()['HTTP_APIKEY'] ?? ($_GET['apikey']?? ($_POST['apikey']??null)),
            ];
        endif;

        if (! $payload['header_apikey'])
            throw new ArchetypeException('You must provide an apikey, pass it as a GET or POST parameter or as a header');
        $timestamp = microtime(true);

        if (! $payload['url_apikey'] && ! $payload['body_apikey'] && ! $payload['header_apikey'])
            throw new ArchetypeException('No apikey is supplied');
        $response = static::requestArchetype('/sdk/v2/authorize', $payload);
        // now send log to the system asynchronisly
        Archetype::sendToSystem(array_merge($payload, [
            'timestamp' => $timestamp,
            'status_code' => $response->getStatusCode()
        ]), $request);
        
        if ($request == null)
            return json_decode($response->getBody()->getContents(), true);
        return $response;
    }

    protected static function requestArchetype($uri, array $payload = [], $method = 'POST')
    {
        static::checkArchetypeKeys();
        $headers = [
            "X-Archetype-SecretKey" =>  static::$appSecret,
            "X-Archetype-AppID" => static::$appId,
            'Content-Type' => 'application/json'
        ];

        $client = new Client(['headers' => $headers]);
        $url = static::$baseEndpoint . $uri;
        
        try {
            $response = $client->post($url, ['json' => $payload]);
            
            static::throwStatusCodeException($response->getStatusCode());
            return $response;
        } catch (\Exception $e) {
            if ($e instanceof \GuzzleHttp\Exception\ClientException || $e instanceof \GuzzleHttp\Exception\ServerException)
                static::throwStatusCodeException($e->getCode());
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
            if ($request instanceof Request) {     
                $body = [
                    'status_code' => $payload['status_code'],
                    'duration' => (microtime(true) - $payload['timestamp']),
                    'size' => 0,
                    'path' => $payload['path'],
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'headers' => $request->header(),
                    'body' => $request->input(),
                    'args' => $request->query(),
                    'tier' => '',
                    'app_id' => static::$appId,
                    'user_id' => $payload['header_apikey'],
                    'timestamp' => microtime(true),
                ];
            } else {
                $headers = static::getHeaders();
                $body = [
                    'status_code' => $payload['status_code'],
                    'duration' => (microtime(true) - $payload['timestamp']),
                    'size' => 0,
                    'path' => $payload['path'],
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'ip' => $_SERVER['REMOTE_ADDR'],
                    'headers' => $headers,
                    'body' => $_POST,
                    'args' => $_GET,
                    'tier' => '',
                    'app_id' => static::$appId,
                    'user_id' => $payload['header_apikey'],
                    'timestamp' => microtime(true),
                ];
            }
        }
        $headers = [
                "X-Archetype-SecretKey" =>  static::$appSecret,
                "X-Archetype-AppID" => static::$appId,
                'Content-Type' => 'application/json',
        ];
        $body['body'] = ! empty($body['body']) ? $body['body']: (object)[];
        $body['headers'] = ! empty($body['headers']) ? $body['headers']: (object)[];
        $body['args'] = ! empty($body['args']) ? $body['args']: (object)[];
        $client = new Client(['headers' => $headers]);
       
        try {
            $promise = $client->postAsync(static::logUrl, ['json' => $body]);
            $promise
                ->then(function ($response) {
                    
                })
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
        $res = static::requestArchetype('/sdk/v1/tiers')->getBody()->getContents();
        return json_decode($res, true);
    }

    public static function getUser($uid)
    {
        $res = static::requestArchetype('/sdk/v1/user', ['custom_uid' => $uid])->getBody()->getContents();
        return json_decode($res, true);
    }

    public static function createCheckoutSession($uid, $productId)
    {
        $res = static::requestArchetype('/sdk/v1/create-checkout-session', ['custom_uid' => $uid, 'tier_id' => $productId])
            ->getBody()->getContents();
        $res = json_decode($res, true);
        return $res['url'] ?? $res;
    }

    public static function cancelSubscription($uid)
    {
        $res = static::requestArchetype('/sdk/v1/cancel-subscription', ['custom_uid' => $uid])
            ->getBody()->getContents();
        return json_decode($res, true);
    }

    public static function registerUser($uid, $name, $email)
    {
        $res = static::requestArchetype(
            '/sdk/v1/create-user', 
            ['custom_uid' => $uid, 'name' => $name, 'email' => $email]
            )->getBody()->getContents();
        
        return json_decode($res, true);
    }
    public static function log($userApiKey, $request = null)
    {
        if ($request instanceof Request):
            $body = [
                'status_code' => 200,
                'duration' => 0.000,
                'size' => 0,
                'path' => $request->path() == '/' ? '/': '/' . $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'headers' => $request->header('apikey'),
                'body' => $request->input('apikey'),
                'args' => $request->query('apikey'),
                'tier' => '',
                'app_id' => static::$appId,
                'user_id' => $userApiKey,
                'timestamp' => microtime(true),
            ];
        else: 
            $body = [
                'status_code' => 200,
                'duration' => 0.000,
                'size' => 0,
                'path' => $_SERVER['SCRIPT_NAME'],
                'method' => $_SERVER['REQUEST_METHOD'],
                'ip' => $_SERVER['REMOTE_ADDR'],
                'headers' => static::getHeaders(),
                'body' => $_POST,
                'args' => $_GET,
                'tier' => '',
                'app_id' => static::$appId,
                'user_id' => $userApiKey,
                'timestamp' => microtime(true),
            ];
        endif;
        

        static::sendToSystem($body, $request, true);
    }
    /**
     * @method checkArchetypeKeys
     * Check archetype keys and see if they are set with the environment wether it's on testing or production mode
     */
    public static function checkArchetypeKeys()
    {
        $errorMsg = class_exists(Request::class) ? 
        'Archetype app_id and secret_key are not specified in config/archetype.php': 
            'Archetype app_id and secret_key are not specified, please call Archetype::init method with the required options.';
        if (! static::$appId || ! static::$appSecret)
            throw new ArchetypeException($errorMsg);
        
        if (strpos(static::$appSecret, 'sk_test') !== false)
            static::$baseEndpoint = 'https://test.archetype.dev';    
        elseif (strpos(static::$appSecret, 'sk_prod') !== false)
            static::$baseEndpoint = 'https://api.archetype.dev';   
        else
            throw new ArchetypeException('Archetype secret_key is not valid.');  
    }
    /**
     * @method throwStatusCodeException
     * Throw an exception based on the status code.
     */
    public static function throwStatusCodeException($status)
    {
        if ($status == 400) {
            throw new ArchetypeException("You've exceeded your quota or rate limit.", $status);
        } elseif ($status == 401) {
            throw new ArchetypeException("You don't have access to this endpoint.", $status);
        } elseif ($status == 403) {
            throw new ArchetypeException("The supplied apikey is invalid or expired.", $status);
        } elseif($status == 404) {
            throw new ArchetypeException("The endpoint you're trying to access doesn't exist.", $status);
        } elseif(! in_array($status, [200, 201, 202, 203, 204, 205, 206, 207, 208, 226])) {
            throw new ArchetypeException("Something went wrong.", $status);
        }
    }
    protected static function getHeaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP') !== false)
                $headers[$key] = $value;
        }
        return $headers;
    }
}