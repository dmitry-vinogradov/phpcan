<?php

namespace Porter;

const COOKIE = 'PSID';
const SESSION_IDLE = 1200;
const ANNON_USERID = 'ANON';

include __DIR__ . '/SessionProvider.php';

use \Can\Server as CanServer;
use \Can\Server\Router;
use \Can\Server\Route;
use \Can\Server\Request;
use \Can\HTTPForward;
use \Can\HTTPError;
use \Can\Client;

ini_set("date.timezone", "Europe/Berlin");

$server = new Server;
$server->start();

class Server
{
    protected $ip;
    protected $port;
    protected $server;
    protected $router;
    protected $logFormat = "time c-ip cs-method cs-uri sc-status sc-bytes time-taken x-memusage x-error\n";
    protected $sessionProvider;

    public function __construct($port = 4567, $ip = '0.0.0.0')
    {
        $this->ip   = $ip;
        $this->port = $port;
        $this->server = new CanServer($this->ip, $this->port, $this->logFormat);

        $this->sessionProvider = new SessionProvider;

        $this->router = new Router([new Route('/<uri:re:.*>', [$this, 'requestHandler'])]);
    }

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
                    'x-porter-userid'    => ANNON_USERID,
                    'x-porter-authlevel' => 0,
                    'x-porter-payload'   => '{}'
                )
            );

        } else {

            $headers = array_merge(
                $request->requestHeaders,
                array(
                    'x-porter-userid'    => $session->userId,
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

    public function upstreamResponseHandler(Request $request, Session &$session = null)
    {
        foreach ($request->responseHeaders as $name => $value) {
            if (false !== stripos($name, 'x-porter-')) {
                switch (strtolower($name)) {
                    case 'x-porter-session-start':
                        $session = $this->sessionProvider->createSession();
                        $request->setCookie(COOKIE, $session->id);
                        $session->userId  = $request->findResponseHeader('x-porter-userid');
                        $session->payload = $request->findResponseHeader('x-porter-payload');
                        $session->save();
                        break;
                    case 'x-porter-session-update':
                        $session->userId  = $request->findResponseHeader('x-porter-userid');
                        $session->payload = $request->findResponseHeader('x-porter-payload');
                        $session->save();
                        break;
                    case 'x-porter-session-delete':
                        $request->setCookie(COOKIE, '');
                        $session->delete();
                        break;
                    case 'x-porter-svc':
                        echo 'requesting svc' . PHP_EOL;
                        (new Client('http://127.0.0.1:4568/svc'))
                            ->send(
                            function ($response)
                            {
                                echo 'Response: ' . print_r($response, 1);
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
