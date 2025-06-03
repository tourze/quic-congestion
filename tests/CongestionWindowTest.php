<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Congestion\CongestionWindow;

/**
 * 拥塞窗口测试
 */
final class CongestionWindowTest extends TestCase
{
    private CongestionWindow $window;

    protected function setUp(): void
    {
        $this->window = new CongestionWindow();
    }

    public function testInitialState(): void
    {
        $this->assertEquals(12000, $this->window->getSize()); // 10 * 1200
        $this->assertTrue($this->window->isInSlowStart());
        $this->assertEquals(0, $this->window->getBytesInFlight());
    }

    public function testSlowStartIncrease(): void
    {
        $initialSize = $this->window->getSize();
        $this->window->slowStartIncrease(1200);
        
        $this->assertEquals($initialSize + 1200, $this->window->getSize());
        $this->assertTrue($this->window->isInSlowStart());
    }

    public function testCongestionAvoidance(): void
    {
        // 设置到拥塞避免阶段
        $this->window->setSlowStartThreshold(10000);
        
        $initialSize = $this->window->getSize();
        $this->window->congestionAvoidanceIncrease(1200);
        
        // 拥塞避免增长应该较慢
        $expectedIncrease = (1200 * 1200) / $initialSize;
        $this->assertEquals($initialSize + max(1, (int) $expectedIncrease), $this->window->getSize());
        $this->assertFalse($this->window->isInSlowStart());
    }

    public function testReduceCongestion(): void
    {
        $initialSize = $this->window->getSize();
        $this->window->reduceCongestion(0.5);
        
        $expectedSize = (int) ($initialSize * 0.5);
        $this->assertEquals($expectedSize, $this->window->getSlowStartThreshold());
        $this->assertEquals($expectedSize, $this->window->getSize());
    }

    public function testCanSend(): void
    {
        $this->assertTrue($this->window->canSend(1200, 0));
        $this->assertTrue($this->window->canSend(1200, 10000));
        $this->assertFalse($this->window->canSend(1200, 12000));
    }

    public function testAvailableWindow(): void
    {
        $this->assertEquals(12000, $this->window->getAvailableWindow(0));
        $this->assertEquals(2000, $this->window->getAvailableWindow(10000));
        $this->assertEquals(0, $this->window->getAvailableWindow(12000));
    }

    public function testWindowClamping(): void
    {
        // 测试最小窗口限制
        $this->window->setSize(100);
        $this->assertEquals(2400, $this->window->getSize()); // MIN_WINDOW_SIZE = 2 * 1200
        
        // 测试最大窗口限制
        $this->window->setSize(100 * 1024 * 1024);
        $this->assertEquals(64 * 1024 * 1024, $this->window->getSize()); // MAX_WINDOW_SIZE
    }

    public function testReset(): void
    {
        $this->window->setSize(20000);
        $this->window->setSlowStartThreshold(15000);
        $this->window->setBytesInFlight(5000);
        
        $this->window->reset();
        
        $this->assertEquals(12000, $this->window->getSize());
        $this->assertEquals(64 * 1024 * 1024, $this->window->getSlowStartThreshold());
        $this->assertEquals(0, $this->window->getBytesInFlight());
        $this->assertTrue($this->window->isInSlowStart());
    }

    public function testStats(): void
    {
        $stats = $this->window->getStats();
        
        $this->assertArrayHasKey('congestion_window', $stats);
        $this->assertArrayHasKey('slow_start_threshold', $stats);
        $this->assertArrayHasKey('bytes_in_flight', $stats);
        $this->assertArrayHasKey('is_slow_start', $stats);
        $this->assertArrayHasKey('available_window', $stats);
        $this->assertArrayHasKey('window_utilization', $stats);
        
        $this->assertEquals(12000, $stats['congestion_window']);
        $this->assertTrue($stats['is_slow_start']);
        $this->assertEquals(0.0, $stats['window_utilization']);
    }

    public function testInvalidReductionFactor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->window->reduceCongestion(1.5);
    }

    public function testUtilization(): void
    {
        $this->window->setBytesInFlight(6000);
        $stats = $this->window->getStats();
        
        $this->assertEquals(0.5, $stats['window_utilization']);
    }
} 