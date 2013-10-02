<?php

use \Can\Server;
use \Can\Server\Router;
use \Can\Server\Route;
use \Can\Server\Request;

ini_set("date.timezone", "Europe/Berlin");

$server = new Server('0.0.0.0', 4568,
    "time c-ip cs-method cs-uri sc-status sc-bytes time-taken x-memusage x-error\n");
$server->start(
    new Router(
        array(
            new Route(
                '/start',
                function(Request $request, array $args)
                {
                    $request->addResponseHeader('x-porter-session-start', '');
                    $request->addResponseHeader('x-porter-userid', '12345');
                    $request->addResponseHeader('x-porter-payload', '{"userId":12345,"counter":1}');
                    return 'Session started';
                }
            ),
            new Route(
                '/<uri:re:.*>',
                function(Request $request, array $args)
                {
                    $payload = $request->findRequestHeader('x-porter-payload');
                    if ($payload) {
                        $payload = json_decode($payload);
                        $payload->counter++;
                    }
                    $request->addResponseHeader('x-porter-session-update', '');
                    $request->addResponseHeader('x-porter-payload', json_encode($payload));
                    return 'Session updated ' . print_r($payload, 1);
                }
            ),
            new Route(
                '/delete',
                function(Request $request, array $args)
                {
                    $request->addResponseHeader('x-porter-session-delete', '');
                    return 'Session deleted';
                }
            ),
            new Route(
                '/getsvc',
                function(Request $request, array $args)
                {
                    $request->addResponseHeader('x-porter-svc', '');
                    return 'SVC version changed';
                }
            ),
            new Route(
                '/svc',
                function(Request $request, array $args)
                {
                    sleep(5);
                    return 'scv returned';
                }
            )
        )
    )
);

