<?php

namespace CascadeEnergy\Tests\DistributedLocking\Consul;

use CascadeEnergy\DistributedLocking\Consul\LockProvider;

class LockProviderTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $consulKeyValue;

    /** @var LockProvider */
    private $lockProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $lockSessionProvider;

    public function setUp()
    {
        $this->consulKeyValue = $this->getMock('SensioLabs\Consul\Services\KV');
        $this->lockSessionProvider = $this->getMock('CascadeEnergy\DistributedLocking\ILockSessionProvider');

        /** @noinspection PhpParamsInspection */
        $this->lockProvider = new LockProvider($this->lockSessionProvider, $this->consulKeyValue);
    }

    public function testItShouldReturnALockHandleContainingTheLockNameAndSessionId()
    {
        $this->lockSessionProvider->expects($this->once())->method('getSessionId')->willReturn('sessionId');

        $result = $this->getMock('Psr\Http\Message\ResponseInterface');
        $result->expects($this->once())->method('getBody');

        $this->consulKeyValue
            ->expects($this->once())
            ->method('put')
            ->willReturn($result);

        $handle = $this->lockProvider->lock('foo');

        $this->assertEquals($handle, ['name' => 'foo', 'sessionId' => 'sessionId']);
    }

    public function testItShouldReturnFalseIfALockWasNotAcquired()
    {
        $result = $this->getMock('Psr\Http\Message\ResponseInterface');
        $result->expects($this->once())->method('getBody')->willReturn('false');
        $this->consulKeyValue->expects($this->once())->method('put')->willReturn($result);

        $this->assertFalse($this->lockProvider->lock('foo'));
    }

    public function testItShouldReleaseALockWhenRequested()
    {
        $this->consulKeyValue->expects($this->once())->method('put')->with('foo', '', ['release' => 'bar']);
        $this->lockProvider->release(['name' => 'foo', 'sessionId' => 'bar']);
    }

    public function testItShouldIndicateAnInvalidLockIfTheNamesDoNotMatch()
    {
        $this->assertFalse($this->lockProvider->isValid('foo', ['name' => 'bar']));
    }

    public function testALockIsNotValidIfItDoesNotExist()
    {
        $result = $this->getMock('Psr\Http\Message\ResponseInterface');
        $result->expects($this->once())->method('getBody')->willReturn(json_encode('foo'));

        $this->consulKeyValue->expects($this->once())->method('get')->with('foo')->willReturn($result);

        $this->assertFalse($this->lockProvider->isValid('foo', ['name' => 'foo']));
    }

    public function testALockIsNotValidIfItHasNoSessionAssignedToIt()
    {
        $resultJson = json_encode([['Key' => 'foo']]);

        $result = $this->getMock('Psr\Http\Message\ResponseInterface');
        $result->expects($this->once())->method('getBody')->willReturn($resultJson);

        $this->consulKeyValue->expects($this->once())->method('get')->with('foo')->willReturn($result);

        $this->assertFalse($this->lockProvider->isValid('foo', ['name' => 'foo']));
    }

    public function testALockIsNotValidIfItTheSessionDoesNotMatchTheHandle()
    {
        $resultJson = json_encode([['Key' => 'foo', 'Session' => 'baz']]);

        $result = $this->getMock('Psr\Http\Message\ResponseInterface');
        $result->expects($this->once())->method('getBody')->willReturn($resultJson);

        $this->consulKeyValue->expects($this->once())->method('get')->with('foo')->willReturn($result);

        $this->assertFalse($this->lockProvider->isValid('foo', ['name' => 'foo', 'sessionId' => 'bar']));
    }

    public function testALockIsValidIfItExistsAndHasTheSameSessionAsTheHandleIndicates()
    {
        $resultJson = json_encode([['Key' => 'foo', 'Session' => 'bar']]);

        $result = $this->getMock('Psr\Http\Message\ResponseInterface');
        $result->expects($this->once())->method('getBody')->willReturn($resultJson);

        $this->consulKeyValue->expects($this->once())->method('get')->with('foo')->willReturn($result);

        $this->assertTrue($this->lockProvider->isValid('foo', ['name' => 'foo', 'sessionId' => 'bar']));
    }
}
