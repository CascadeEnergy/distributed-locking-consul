<?php

namespace Cascade\DistributedLocking\Consul;

use Cascade\DistributedLocking\ILockSessionProvider;
use Cascade\Exceptions\ExceptionWithContext;
use SensioLabs\Consul\Services\Session;

class LockSessionProvider implements ILockSessionProvider
{
    /** The default TTL of a session, in seconds */
    const DEFAULT_TTL = 30;

    /** The minimum number of seconds between outbound heartbeat calls */
    const HEARTBEAT_INTERVAL = 5;

    private $consulSession;

    /** @var callable A function which returns the current time in (possibly fractional) seconds */
    private $currentTimeFn;

    private $lastHeartbeatTimestamp = 0;
    private $sessionId;
    private $sessionTtl;

    /**
     * @param Session $consulSession
     * @param int $ttl The session TTL, in seconds
     * @param callable $currentTimeFn A function which returns the current time in seconds (defaults to `microtime`)
     */
    public function __construct(Session $consulSession, $ttl = self::DEFAULT_TTL, callable $currentTimeFn = null)
    {
        $ttl = (int)$ttl;

        if ($ttl <= 0) {
            throw new \InvalidArgumentException('The session TTL must be a positive integer');
        }

        $this->consulSession = $consulSession;
        $this->currentTimeFn = $currentTimeFn;
        $this->sessionTtl = "{$ttl}s";

        if (is_null($this->currentTimeFn)) {
            $this->currentTimeFn = function () {
                return microtime(true);
            };
        }
    }

    /**
     * Creates a new session and returns the opaque session identifier.
     *
     * @return mixed The session identifier
     * @throws ExceptionWithContext
     */
    public function createSession()
    {
        if (empty($this->sessionId)) {
            $parameters = ['TTL' => $this->sessionTtl];
            $response = $this->consulSession->create(json_encode($parameters));

            $data = json_decode((string)$response->getBody(), true);

            if (!array_key_exists('ID', $data)) {
                throw new ExceptionWithContext('Malformed session data', $data);
            }

            $this->sessionId = $data['ID'];
        }

        return $this->sessionId;
    }

    /**
     * Destroys the current session ID
     */
    public function destroySession()
    {
        if (!empty($this->sessionId)) {
            $this->consulSession->destroy($this->sessionId);
        }
    }

    /**
     * Returns the current opaque session identifier, creating a new one if no session currently exists.
     *
     * @return mixed The current session identifier
     * @throws ExceptionWithContext
     */
    public function getSessionId()
    {
        $this->createSession();

        return $this->sessionId;
    }

    /**
     * Renews the TTL for the current session. If no session exists, this function does nothing. This function
     * also ensures that the session is only renewed once every static::HEARTBEAT_INTERVAL seconds, to allow it to
     * be called in tight processing loops without causing a flood of traffic.
     */
    public function heartbeat()
    {
        $currentTimeFn = $this->currentTimeFn;

        if (empty($this->sessionId)) {
            return;
        }

        if ($currentTimeFn() < $this->lastHeartbeatTimestamp + static::HEARTBEAT_INTERVAL) {
            return;
        }

        $this->consulSession->renew($this->getSessionId());
        $this->lastHeartbeatTimestamp = $currentTimeFn();
    }
}
