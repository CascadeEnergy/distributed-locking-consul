<?php

namespace CascadeEnergy\DistributedLocking\Consul;

use CascadeEnergy\DistributedLocking\ILockProvider;
use CascadeEnergy\DistributedLocking\ILockSessionProvider;
use SensioLabs\Consul\Services\KV;

class LockProvider implements ILockProvider
{
    /** @var KV */
    private $kvClient;

    /** @var ILockSessionProvider */
    private $sessionProvider;

    /**
     * @param ILockSessionProvider $sessionProvider
     * @param KV $kvClient
     */
    public function __construct(ILockSessionProvider $sessionProvider, KV $kvClient)
    {
        $this->sessionProvider = $sessionProvider;
        $this->kvClient = $kvClient;
    }

    /**
     * @param string $lockName The name of the lock to acquire
     *
     * @return mixed An opaque handle for the lock, or false if the lock could not be acquired
     */
    public function lock($lockName)
    {
        $sessionId = $this->sessionProvider->getSessionId();

        $result = (string)$this->kvClient->put($lockName, '', ['acquire' => $sessionId])->getBody();

        if ($result === 'false') {
            return false;
        }

        return [ 'name' => $lockName, 'sessionId' => $sessionId ];
    }

    /**
     * Note that the `$lockHandle` parameter is opaque and implementation specific
     *
     * @param mixed $lockHandle The opaque handle of the lock to be released
     *
     * @return void
     */
    public function release($lockHandle)
    {
        $this->kvClient->put(
            $lockHandle['name'],
            '',
            ['release' => $lockHandle['sessionId']]
        );
    }

    /**
     * Determines if the given lock handle is currently valid for the given lock name. This method can be used to
     * verify if, for example, a particular lock session currently owns a given lock.
     *
     * @param string $lockName The name of the lock to check
     * @param mixed $lockHandle The opaque lock handle to validate against the lock
     *
     * @return bool True if the handle is valid for the lock, false otherwise
     */
    public function isValid($lockName, $lockHandle)
    {
        if ($lockName != $lockHandle['name']) {
            return false;
        }

        $result = (string)$this->kvClient->get($lockHandle['name'])->getBody();
        $result = json_decode($result, true);

        if (!is_array($result) || !array_key_exists(0, $result)) {
            return false;
        }

        $keyValue = $result[0];

        if (!array_key_exists('Session', $keyValue)) {
            return false;
        }

        if ($keyValue['Session'] != $lockHandle['sessionId']) {
            return false;
        }

        return true;
    }
}
