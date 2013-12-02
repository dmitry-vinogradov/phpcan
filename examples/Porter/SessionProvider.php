<?php

namespace Porter;

include_once __DIR__ . '/Session.php';

use \Memcached;
use \Can\Server\Request;

class SessionProvider
{
    protected $memcached;

    protected function __construct()
    {
        $this->memcached = new Memcached;
        $this->memcached->addServer('127.0.0.1', 11211);
        $this->memcached->setOptions(
            array(
                Memcached::OPT_NO_BLOCK      => true,
                Memcached::OPT_TCP_NODELAY   => true,
                Memcached::OPT_BUFFER_WRITES => true,
                Memcached::OPT_DISTRIBUTION  => Memcached::DISTRIBUTION_CONSISTENT
            )
        );
    }

    public static function getInstance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new SessionProvider;
        }

        return $instance;
    }

    public function createSession($id = null)
    {
        $session = new Session($this, $id === null ? md5(uniqid()) : $id);
        $this->saveSession($session);
        return $session;
    }


    public function getSession(Request $request)
    {
        if (isset($request->cookies[Server::COOKIE])) {
            $id = $request->cookies[Server::COOKIE];
            if (!($data = $this->memcached->get($id))) {
                $session = $this->createSession($id);
            } else {
                $session = Session::unserialize($this, $data);
            }
            return $session;
        }
    }

    public function saveSession(Session $session)
    {
        $this->memcached->set($session->id, $session->serialize(), Server::SESSION_IDLE);
    }

    public function deleteSession(Session $session)
    {
        $this->memcached->delete($session->id);
        unset($session);
    }
}
