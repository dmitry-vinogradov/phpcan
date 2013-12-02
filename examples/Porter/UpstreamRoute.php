<?php

namespace Porter;

use \Can\Server\Request;
use \Can\Server\Route;

class UpstreamRoute extends Route
{
    const AUTH_LEVEL_NONE   = 0;

    /**
     * No access restrictions, x-porter-user header will allways contain 'ANNON'
     */
    const AUTH_LEVEL_ANON   = 1;

    /**
     * Only authenticated users may access this route
     */
    const AUTH_LEVEL_AUTH   = 2;

    /**
     * Only NOT authenticated users may access this route
     */
    const AUTH_LEVEL_UNAUTH = 4;

    /**
     * Restrict access to the route only from localhost (127.0.0.1)
     */
    const AUTH_LEVEL_LOCAL  = 8;

    /**
     * All valid authentication level combined.
     */
    const AUTH_LEVEL_VALID = 15;

    /**
     * Authentication level of the current route instance
     * @var integer
     */
    protected $authLevel = 0;

    /**
     * Server address of this upstream route instance
     * @var string
     */
    protected $server;

    /**
     * Set server address for this route
     * @param string $value Server address, e.g. 127.0.0.1:80
     * @return \Porter\UpstreamRoute
     */
    public function setServer($value)
    {
        $this->server = $value;
        return $this;
    }

    /**
     * Set authentication level for this route
     * @param int $value Authentication level, one of the self::AUTH_LEVEL_*
     * @return \Porter\UpstreamRoute
     * @throws \InvalidArgumentException if invalid authentication level provided
     */
    public function setAuthLevel($value)
    {
        if (0 === (self::AUTH_LEVEL_VALID & $value)) {
            throw new \InvalidArgumentException('Unknown auth level provided.');
        }

        $this->authLevel = $value;
        return $this;
    }

    /**
     * Set
     * @param type $method
     * @return \Porter\UpstreamRoute
     */
    public function setRouteMethod($method)
    {
        parent::setMethod($method);
        return $this;
    }

    /**
     * Creates an instance of the route based on provided configuration array.
     * @param array $config Route configuration
     * @param string $server Server address
     * @return \Porter\UpstreamRoute
     * @throws \InvalidArgumentException if configuration array contains invalid route definition
     */
    public static function fromConfig(array $config, $server)
    {
        if (false === isset($config['pattern'])) {
            throw new \InvalidArgumentException(
                'No pattern definition in the route configuration.'
            );
        }

        if (false === isset($config['method'])) {
            throw new \InvalidArgumentException(
                'No HTTP method definition in the route configuration.'
            );
        }

        if (false === isset($config['authlevel'])) {
            throw new \InvalidArgumentException(
                'No auth level definition in the route configuration.'
            );
        }

        $methods = false !== strpos($config['method'], '|') ?
            explode('|', $config['method']) : array($config['method']);

        $method = 0;
        foreach ($methods as $meth) {
            switch ($meth) {
                case '*':
                    $method = self::METHOD_ALL;
                    break;
                case 'GET':
                    $method |= self::METHOD_GET;
                    break;
                case 'POST':
                    $method |= self::METHOD_POST;
                    break;
                case 'HEAD':
                    $method |= self::METHOD_HEAD;
                    break;
                case 'PUT':
                    $method |= self::METHOD_PUT;
                    break;
                case 'DELETE':
                    $method |= self::METHOD_DELETE;
                    break;
                case 'OPTIONS':
                    $method |= self::METHOD_OPTIONS;
                    break;
                case 'TRACE':
                    $method |= self::METHOD_TRACE;
                    break;
                case 'CONNECT':
                    $method |= self::METHOD_CONNECT;
                    break;
                case 'PATCH':
                    $method |= self::METHOD_PATCH;
                    break;
            }
        }

        if ($method === 0) {
            throw new \InvalidArgumentException(
                'Invalid HTTP method definition in the route configuration.'
            );
        }

        try {
            $route = (new self($config['pattern']))
                ->setServer($server)
                ->setRouteMethod($method)
                ->setAuthLevel($config['authlevel']);
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return $route;
    }

    /**
     * Request handler
     * @param \Can\Server\Request $request
     */
    public function handleRequest(Request $request, array $args)
    {
        $session = SessionProvider::getInstance()->getSession($request);
        if (!$session) {
            if ($this->authLevel & self::AUTH_LEVEL_AUTH) {
                throw new HTTPError(403);
            }
        }

        return 'http://' . $this->server . $request->uri;

        return new HTTPForward(
            'http://' . $this->server . $request->uri,
            $headers,
            function (Request $req) use ($session)
            {
                global $server;
                $server->upstreamResponseHandler($req, $session);
            }
        );

    }
}
