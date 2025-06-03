<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Congestion\CongestionWindow;
use Tourze\QUIC\Congestion\NewReno;

/**
 * NewReno拥塞控制算法测试
 */
final class NewRenoTest extends TestCase
{
    private NewReno $newReno;

    protected function setUp(): void
    {
        $this->newReno = new NewReno();
    }

    public function testInitialState(): void
    {
        $this->assertEquals('NewReno', $this->newReno->getAlgorithmName());
        $this->assertEquals(12000, $this->newReno->getCongestionWindow());
        $this->assertTrue($this->newReno->isInSlowStart());
        $this->assertFalse($this->newReno->isInRecovery());
        $this->assertEquals('slow_start', $this->newReno->getCongestionState());
    }

    public function testPacketSent(): void
    {
        $this->newReno->onPacketSent(1, 1200, microtime(true));
        
        $stats = $this->newReno->getStats();
        $this->assertEquals(1200, $stats['total_bytes_sent']);
    }

    public function testPacketAckedInSlowStart(): void
    {
        $initialWindow = $this->newReno->getCongestionWindow();
        $sentTime = microtime(true);
        $ackTime = $sentTime + 0.1;
        
        $this->newReno->onPacketAcked(1, 1200, $sentTime, $ackTime);
        
        // 慢启动阶段，窗口应该增加
        $this->assertGreaterThan($initialWindow, $this->newReno->getCongestionWindow());
        $this->assertTrue($this->newReno->isInSlowStart());
        
        $stats = $this->newReno->getStats();
        $this->assertEquals(1, $stats['acked_packets']);
        $this->assertEquals(1200, $stats['total_bytes_acked']);
    }

    public function testPacketAckedInCongestionAvoidance(): void
    {
        // 设置到拥塞避免阶段
        $this->newReno->setSlowStartThreshold(10000);
        
        $initialWindow = $this->newReno->getCongestionWindow();
        $sentTime = microtime(true);
        $ackTime = $sentTime + 0.1;
        
        $this->newReno->onPacketAcked(1, 1200, $sentTime, $ackTime);
        
        // 拥塞避免阶段，窗口增长应该更慢
        $this->assertGreaterThan($initialWindow, $this->newReno->getCongestionWindow());
        $this->assertFalse($this->newReno->isInSlowStart());
        $this->assertEquals('congestion_avoidance', $this->newReno->getCongestionState());
    }

    public function testPacketLoss(): void
    {
        $initialWindow = $this->newReno->getCongestionWindow();
        $lossTime = microtime(true);
        
        $this->newReno->onPacketLost(1, 1200, $lossTime - 0.1, $lossTime);
        
        // 丢包后窗口应该减少，并进入快速恢复
        $this->assertLessThan($initialWindow, $this->newReno->getCongestionWindow());
        $this->assertTrue($this->newReno->isInRecovery());
        $this->assertEquals('fast_recovery', $this->newReno->getCongestionState());
        
        $stats = $this->newReno->getStats();
        $this->assertEquals(1, $stats['lost_packets']);
        $this->assertEquals(1200, $stats['total_bytes_lost']);
    }

    public function testRecoveryExit(): void
    {
        // 先进入快速恢复
        $this->newReno->onPacketLost(1, 1200, microtime(true) - 0.1, microtime(true));
        $this->assertTrue($this->newReno->isInRecovery());
        
        // 确认更高包号的包应该退出快速恢复
        $this->newReno->onPacketAcked(5, 1200, microtime(true) - 0.1, microtime(true));
        $this->assertFalse($this->newReno->isInRecovery());
    }

    public function testCanSend(): void
    {
        $this->assertTrue($this->newReno->canSend(1200, 0));
        $this->assertTrue($this->newReno->canSend(1200, 10000));
        $this->assertFalse($this->newReno->canSend(1200, 12000));
    }

    public function testGetSendingRate(): void
    {
        // NewReno是基于窗口的算法，不提供发送速率
        $this->assertNull($this->newReno->getSendingRate());
    }

    public function testReset(): void
    {
        // 修改状态
        $this->newReno->onPacketSent(1, 1200, microtime(true));
        $this->newReno->onPacketLost(1, 1200, microtime(true) - 0.1, microtime(true));
        
        $this->assertTrue($this->newReno->isInRecovery());
        
        // 重置
        $this->newReno->reset();
        
        $this->assertFalse($this->newReno->isInRecovery());
        $this->assertTrue($this->newReno->isInSlowStart());
        $this->assertEquals(12000, $this->newReno->getCongestionWindow());
        
        $stats = $this->newReno->getStats();
        $this->assertEquals(0, $stats['acked_packets']);
        $this->assertEquals(0, $stats['lost_packets']);
        $this->assertEquals(0, $stats['total_bytes_sent']);
    }

    public function testMultipleLossesInRecovery(): void
    {
        $initialWindow = $this->newReno->getCongestionWindow();
        
        // 第一个丢包
        $this->newReno->onPacketLost(1, 1200, microtime(true) - 0.1, microtime(true));
        $windowAfterFirstLoss = $this->newReno->getCongestionWindow();
        
        // 同一快速恢复期间的第二个丢包（包号更小）
        $this->newReno->onPacketLost(0, 1200, microtime(true) - 0.1, microtime(true));
        $windowAfterSecondLoss = $this->newReno->getCongestionWindow();
        
        // 窗口不应该再次减少
        $this->assertEquals($windowAfterFirstLoss, $windowAfterSecondLoss);
    }

    public function testLossRate(): void
    {
        $this->newReno->onPacketSent(1, 1200, microtime(true));
        $this->newReno->onPacketSent(2, 1200, microtime(true));
        $this->newReno->onPacketSent(3, 1200, microtime(true));
        
        $this->newReno->onPacketAcked(1, 1200, microtime(true) - 0.1, microtime(true));
        $this->newReno->onPacketLost(2, 1200, microtime(true) - 0.1, microtime(true));
        $this->newReno->onPacketAcked(3, 1200, microtime(true) - 0.1, microtime(true));
        
        $stats = $this->newReno->getStats();
        $this->assertEquals(1200.0 / 3600.0, $stats['loss_rate']);
    }

    public function testCustomCongestionWindow(): void
    {
        $customWindow = new CongestionWindow(8000, 16000);
        $newReno = new NewReno($customWindow);
        
        $this->assertEquals(8000, $newReno->getCongestionWindow());
        $this->assertEquals(16000, $newReno->getSlowStartThreshold());
    }

    public function testStats(): void
    {
        $stats = $this->newReno->getStats();
        
        $this->assertArrayHasKey('algorithm', $stats);
        $this->assertArrayHasKey('congestion_window', $stats);
        $this->assertArrayHasKey('slow_start_threshold', $stats);
        $this->assertArrayHasKey('in_recovery', $stats);
        $this->assertArrayHasKey('acked_packets', $stats);
        $this->assertArrayHasKey('lost_packets', $stats);
        $this->assertArrayHasKey('loss_rate', $stats);
        
        $this->assertEquals('NewReno', $stats['algorithm']);
        $this->assertFalse($stats['in_recovery']);
    }
} 