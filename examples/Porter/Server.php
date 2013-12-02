<?php

namespace Porter;

$options = getopt('c:');
$config  = array();
if (isset($options['c'])) {
    if (false === is_file($options['c']) || false === is_readable($options['c'])) {
        die("Configuration file {$options['c']} does not exist or is not readable.\n");
    }

    $config = json_decode(file_get_contents($options['c']), true);
    if (null === $config) {
        die("Content of configuration file {$options['c']} is not valid JSON.\n");
    }
}

include_once __DIR__ . '/SessionProvider.php';
include_once __DIR__ . '/UpstreamRoute.php';

use \Can\Server as CanServer;
use \Can\Server\Router;
use \Can\Server\Request;
use \Can\HTTPForward;
use \Can\HTTPError;
use \Can\Client;

ini_set("date.timezone", "Europe/Berlin");

$server = new Server($config);
$server->start();

class Server
{
    const COOKIE       = 'PSID';
    const SESSION_IDLE = 1200;
    const ANNON_USERID = 'ANON';

    protected $config = array(
        'port'      => 4567,
        'addr'      => '0.0.0.0',
        'logformat' => 'time c-ip cs-method cs-uri sc-status sc-bytes time-taken x-memusage x-error',
        'upstreams' => array()
    );

    protected $server;
    protected $router;
    protected $sessionProvider;

    /**
     * Constructor.
     * Configures the server and requests all defined upstreams for
     * its routes configurations.
     * @param array $config Server configuration array
     * @throws \UnexpectedValueException on invalid upstream configuration
     */
    public function __construct(array $config)
    {
        $this->config = array_merge($this->config, $config);
        foreach ($this->config['upstreams'] as $upstream) {
            if (!isset($upstream['addr']) || !isset($upstream['port']) || !isset($upstream['config'])) {
                throw new \UnexpectedValueException('Unexpected upstream configuration.');
            }
            $url = sprintf("http://%s:%s%s", $upstream['addr'], $upstream['port'], $upstream['config']);
            echo "sending request to $url\n";
            (new Client($url))->send([$this, 'handleUpstreamConfig']);
        }
        $this->server = new CanServer(
            $this->config['addr'],
            $this->config['port'],
            trim($this->config['logformat']) . PHP_EOL
        );

        $this->sessionProvider = SessionProvider::getInstance();
    }

    /**
     * Starts the server.
     */
    public function start()
    {
        $this->server->start($this->router);
    }

    public function requestHandler(Request $request, array $args)
    {
        $session = $this->sessionProvider->getSession($request);
        if (!$session) {
            if ($args['uri'] !== 'start') {
                throw new HTTPError(403);
            }

            $headers = array_merge(
                $request->requestHeaders,
                array(
                    'x-porter-user'      => ANNON_USERID,
                    'x-porter-authlevel' => 0,
                    'x-porter-payload'   => '{}'
                )
            );

        } else {

            $headers = array_merge(
                $request->requestHeaders,
                array(
                    'x-porter-user'      => $session->userId,
                    'x-porter-authlevel' => 1,
                    'x-porter-payload'   => $session->payload
                )
            );
        }

        return new HTTPForward(
            'http://127.0.0.1:4568/' . $args['uri'],
            $headers,
            function (Request $req) use ($session)
            {
                global $server;
                $server->upstreamResponseHandler($req, $session);
            }
        );
    }

    public function handleUpstreamConfig($response)
    {
        if (!$this->router) {
            $this->router = new Router();
        }

        $host = $response->getRequestHeaders()['Host'];

        $upstreamConfig = json_decode($response->getBody(), true);
        if (null !== $upstreamConfig) {
            foreach ($upstreamConfig as $routeConfig) {
                try {
                    $route = UpstreamRoute::fromConfig($routeConfig, $host);
                    $this->router->addRoute($route);
                } catch (\Exception $e) {
                    return $e->getMessage();
                }
            }
        }
    }

    public function upstreamResponseHandler(Request $request, Session &$session = null)
    {
        foreach ($request->responseHeaders as $name => $value) {
            if (false !== stripos($name, 'x-porter-')) {
                switch (strtolower($name)) {
                    case 'x-porter-session-start':
                        $session = $this->sessionProvider->createSession();
                        $request->setCookie(self::COOKIE, $session->id);
                        $session->userId  = $request->findResponseHeader('x-porter-user');
                        $session->payload = $request->findResponseHeader('x-porter-payload');
                        $session->save();
                        break;
                    case 'x-porter-session-update':
                        $session->userId  = $request->findResponseHeader('x-porter-user');
                        $session->payload = $request->findResponseHeader('x-porter-payload');
                        $session->save();
                        break;
                    case 'x-porter-session-delete':
                        $request->setCookie(self::COOKIE, '');
                        $session->delete();
                        break;
                    case 'x-porter-svc':
                        echo 'requesting svc' . PHP_EOL;
                        (new Client('http://www.spiegel.de'))
                            ->send(
                            function ($response)
                            {
                                echo 'Request URL: ' . print_r($response->getUrl(), 1) . "\n";
                                echo 'Request headers: ' . print_r($response->getRequestHeaders(), 1);
                                echo 'Response headers: ' . print_r($response->getResponseHeaders(), 1);
                            }
                        );
                        echo 'request sent' . PHP_EOL;
                        break;
                }
                $request->removeResponseHeader($name);
            }
        }
    }
}
