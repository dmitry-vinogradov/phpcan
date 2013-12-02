<?php

namespace Porter;

use \Can\Server as CanServer;

class Session
{
    protected $id;
    protected $userId;
    protected $authLevel;
    protected $payload;
    protected $provider;

    public function __construct(SessionProvider $provider, $id, $userId = CanServer::ANNON_USERID, $payload = '{}')
    {
        $this->id        = $id;
        $this->userId    = $userId;
        $this->payload   = $payload;
        $this->provider  = $provider;
    }

    public function __get($property)
    {
        if (property_exists($this, $property)) {
            return $this->{$property};
        }
    }

    public function __set($property, $value)
    {
        if (property_exists($this, $property)) {
            $this->{$property} = $value;
        }
    }

    public function serialize()
    {
        return $this->id . "\1" .
               $this->userId . "\1" .
               $this->payload;
    }

    public static function unserialize(SessionProvider $provider, $data)
    {
        $c = substr_count($data, "\1");
        if (2 < $c) {
            return new Session;
        } else {
            $parts = explode("\1", $data);
            return new Session(
                $provider,
                array_shift($parts), // id
                array_shift($parts), // userId
                join("\1", $parts)   // payload
            );
        }
    }

    public function save()
    {
        $this->provider->saveSession($this);
    }

    public function delete()
    {
        $this->provider->deleteSession($this);
    }
}
