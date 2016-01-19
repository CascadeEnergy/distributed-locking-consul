<?php

namespace CascadeEnergy\Tests\DistributedLocking\Consul;

use CascadeEnergy\DistributedLocking\Consul\LockSessionProvider;

class LockSessionProviderTest extends \PHPUnit_Framework_TestCase
{
    const EPSILON = 0.01;

    /** @var LockSessionProvider */
    private $lockSessionProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $session;
    
    public function setUp()
    {
        $this->session = $this->getMock('SensioLabs\Consul\Services\Session');
        
        /** @noinspection PhpParamsInspection */
        $this->lockSessionProvider = new LockSessionProvider($this->session);
    }

    public function testItRequiresAValidTTL()
    {
        $this->setExpectedException('\InvalidArgumentException');

        /** @noinspection PhpParamsInspection */
        new LockSessionProvider($this->session, -1);
    }

    public function testItUsesMicrotimeAsTheDefaultTimer()
    {
        /** @var callable $currentTimeFn */
        $currentTimeFn = $this->readAttribute($this->lockSessionProvider, 'currentTimeFn');
        $this->assertEquals($currentTimeFn(), $currentTimeFn(), '', static::EPSILON);
    }

    public function testItCreatesASessionUsingTheConsulSessionClient()
    {
        $this->setupValidSessionReturn();
        $this->assertEquals('sessionId', $this->lockSessionProvider->createSession());
    }

    public function testItWillNotCreateANewSessionIfOneAlreadyExists()
    {
        $this->setupValidSessionReturn();

        // Calling `createSession` twice ensures that we returning an existing session because `setupValidSessionReturn`
        // explicitly specifies that `create` should be called exactly once on the Consul session service.
        $this->assertEquals('sessionId', $this->lockSessionProvider->createSession());
        $this->assertEquals('sessionId', $this->lockSessionProvider->createSession());
    }

    public function testItShouldRaiseAnExceptionIfAnInvalidSessionIsReturned()
    {
        $createResultJson = json_encode(['bar' => 'baz']);
        $createResult = $this->getMock('Psr\Http\Message\ResponseInterface');

        $createResult->expects($this->once())->method('getBody')->willReturn($createResultJson);
        $this->session->expects($this->once())->method('create')->willReturn($createResult);

        $this->setExpectedException('CascadeEnergy\Exceptions\ExceptionWithContext', 'Malformed session data');
        $this->lockSessionProvider->createSession();
    }

    public function testItShouldDestroyASessionIfOneExists()
    {
        $this->setupValidSessionReturn();

        $this->session->expects($this->once())->method('destroy')->with('sessionId');

        $this->lockSessionProvider->createSession();
        $this->lockSessionProvider->destroySession();
    }

    public function testItShouldDoNothingIfAskedToDestroyASessionWhichDoesNotExist()
    {
        $this->session->expects($this->never())->method('destroy');
        $this->lockSessionProvider->destroySession();
    }

    public function testItShouldReturnTheCurrentSessionId()
    {
        $this->setupValidSessionReturn();
        $this->assertEquals('sessionId', $this->lockSessionProvider->getSessionId());
    }

    public function testItShouldDoNothingIfAskedToRenewASessionWhichDoesNotExist()
    {
        $this->session->expects($this->never())->method('renew');
        $this->lockSessionProvider->heartbeat();
    }

    public function testItShouldRenewAnExistingSessionAtMostOnceEveryHeartbeatIntervalSeconds()
    {
        $currentTime = 100;

        /** @noinspection PhpParamsInspection */
        $this->lockSessionProvider = new LockSessionProvider(
            $this->session,
            LockSessionProvider::DEFAULT_TTL,
            function () use ($currentTime) {
                return $currentTime;
            }
        );

        $this->setupValidSessionReturn();

        $this->lockSessionProvider->createSession();

        $this->session->expects($this->once())->method('renew')->with('sessionId');
        $this->lockSessionProvider->heartbeat();
        $this->lockSessionProvider->heartbeat();
    }

    public function testItShouldRenewASessionAfterTheHeartbeatIntervalHasPassed()
    {
        $currentTime = 100;

        /** @noinspection PhpParamsInspection */
        $this->lockSessionProvider = new LockSessionProvider(
            $this->session,
            LockSessionProvider::DEFAULT_TTL,
            function () use (&$currentTime) {
                return $currentTime;
            }
        );

        $this->setupValidSessionReturn();

        $this->lockSessionProvider->createSession();

        $this->session->expects($this->exactly(2))->method('renew')->with('sessionId');
        $this->lockSessionProvider->heartbeat();
        $this->lockSessionProvider->heartbeat();

        /** @noinspection PhpUnusedLocalVariableInspection */
        $currentTime += LockSessionProvider::HEARTBEAT_INTERVAL;

        $this->lockSessionProvider->heartbeat();
    }

    private function setupValidSessionReturn($sessionId = 'sessionId')
    {
        $parameterJson = json_encode(['TTL' => '30s']);
        $resultJson = json_encode(['ID' => $sessionId]);

        $result = $this->getMock('Psr\Http\Message\ResponseInterface');
        $result->expects($this->once())->method('getBody')->willReturn($resultJson);

        $this->session->expects($this->once())->method('create')->with($parameterJson)->willReturn($result);
    }
}
