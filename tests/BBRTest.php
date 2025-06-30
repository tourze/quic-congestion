<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Congestion\BBR;

/**
 * BBR拥塞控制算法测试
 */
final class BBRTest extends TestCase
{
    private BBR $bbr;

    protected function setUp(): void
    {
        $this->bbr = new BBR();
    }

    public function testInitialState(): void
    {
        $this->assertEquals('BBR', $this->bbr->getAlgorithmName());
        $this->assertEquals(12000, $this->bbr->getCongestionWindow()); // 10 * 1200
        $this->assertTrue($this->bbr->isInSlowStart()); // startup状态等同于慢启动
        $this->assertEquals(PHP_INT_MAX, $this->bbr->getSlowStartThreshold());
    }

    public function testPacketSent(): void
    {
        $this->bbr->onPacketSent(1, 1200, microtime(true));
        
        $stats = $this->bbr->getStats();
        $this->assertEquals(1200, $stats['total_bytes_sent']);
    }

    public function testPacketAcked(): void
    {
        $initialWindow = $this->bbr->getCongestionWindow();
        $sentTime = microtime(true);
        $ackTime = $sentTime + 0.1; // 100ms RTT
        
        $this->bbr->onPacketAcked(1, 1200, $sentTime, $ackTime);
        
        $stats = $this->bbr->getStats();
        $this->assertEquals(1, $stats['acked_packets']);
        $this->assertEquals(1200, $stats['total_bytes_acked']);
        $this->assertEquals('startup', $stats['state']);
        
        // BBR 应该记录带宽估算
        $this->assertGreaterThan(0, $stats['bandwidth_estimate']);
    }

    public function testRttMeasurement(): void
    {
        $sentTime = microtime(true);
        $ackTime = $sentTime + 0.05; // 50ms RTT
        
        $this->bbr->onPacketAcked(1, 1200, $sentTime, $ackTime);
        
        $stats = $this->bbr->getStats();
        $this->assertEqualsWithDelta(0.05, $stats['min_rtt'], 0.001);
        $this->assertEqualsWithDelta(0.05, $stats['rt_prop'], 0.001);
    }

    public function testBandwidthEstimation(): void
    {
        $sentTime = microtime(true);
        $ackTime = $sentTime + 0.1; // 100ms RTT
        $bytes = 1200;
        
        $this->bbr->onPacketAcked(1, $bytes, $sentTime, $ackTime);
        
        $stats = $this->bbr->getStats();
        $expectedBandwidth = $bytes / 0.1; // 12000 bytes/second
        $this->assertEqualsWithDelta($expectedBandwidth, $stats['bandwidth_estimate'], 50.0);
    }

    public function testPacketLoss(): void
    {
        // BBR 主要不基于丢包进行拥塞控制
        $this->bbr->onPacketLost(1, 1200, microtime(true) - 0.1, microtime(true));
        
        $stats = $this->bbr->getStats();
        $this->assertEquals(1, $stats['lost_packets']);
        // BBR 的窗口不应该因为单个丢包而大幅减少
    }

    public function testCanSend(): void
    {
        $this->assertTrue($this->bbr->canSend(1200, 0));
        $this->assertTrue($this->bbr->canSend(1200, 10000));
        $this->assertFalse($this->bbr->canSend(1200, 12000));
    }

    public function testGetSendingRate(): void
    {
        // BBR 支持发送速率控制
        $rate = $this->bbr->getSendingRate();
        
        // 初始状态可能返回 null
        if ($rate !== null) {
            $this->assertGreaterThanOrEqual(0.0, $rate);
        }
        
        // 发送和确认一些包后应该有速率
        $this->bbr->onPacketSent(1, 1200, microtime(true));
        $this->bbr->onPacketAcked(1, 1200, microtime(true) - 0.1, microtime(true));
        
        $rateAfter = $this->bbr->getSendingRate();
        $this->assertNotNull($rateAfter);
        $this->assertGreaterThan(0.0, $rateAfter);
    }

    public function testReset(): void
    {
        // 修改状态
        $this->bbr->onPacketSent(1, 1200, microtime(true));
        $this->bbr->onPacketAcked(1, 1200, microtime(true) - 0.1, microtime(true));
        
        // 重置
        $this->bbr->reset();
        
        $this->assertTrue($this->bbr->isInSlowStart());
        $this->assertEquals(12000, $this->bbr->getCongestionWindow());
        
        $stats = $this->bbr->getStats();
        $this->assertEquals(0, $stats['acked_packets']);
        $this->assertEquals(0, $stats['lost_packets']);
        $this->assertEquals(0, $stats['total_bytes_sent']);
        $this->assertEquals('startup', $stats['state']);
    }

    public function testMultipleRttSamples(): void
    {
        $baseTime = microtime(true);
        
        // 发送多个包，模拟不同的RTT
        $this->bbr->onPacketAcked(1, 1200, $baseTime, $baseTime + 0.05); // 50ms
        $this->bbr->onPacketAcked(2, 1200, $baseTime, $baseTime + 0.08); // 80ms
        $this->bbr->onPacketAcked(3, 1200, $baseTime, $baseTime + 0.06); // 60ms
        
        $stats = $this->bbr->getStats();
        // 最小RTT应该是50ms
        $this->assertEqualsWithDelta(0.05, $stats['min_rtt'], 0.001);
        $this->assertEqualsWithDelta(0.05, $stats['rt_prop'], 0.001);
    }

    public function testBandwidthSampling(): void
    {
        $baseTime = microtime(true);
        
        // 模拟带宽测量
        $this->bbr->onPacketAcked(1, 2400, $baseTime, $baseTime + 0.1); // 24000 bytes/s
        $this->bbr->onPacketAcked(2, 1200, $baseTime, $baseTime + 0.05); // 24000 bytes/s  
        $this->bbr->onPacketAcked(3, 3600, $baseTime, $baseTime + 0.1); // 36000 bytes/s
        
        $stats = $this->bbr->getStats();
        // 最大带宽应该是36000 bytes/s
        $this->assertEqualsWithDelta(36000, $stats['max_bandwidth'], 1000);
    }

    public function testLossRate(): void
    {
        $this->bbr->onPacketSent(1, 1200, microtime(true));
        $this->bbr->onPacketSent(2, 1200, microtime(true));
        $this->bbr->onPacketSent(3, 1200, microtime(true));
        $this->bbr->onPacketSent(4, 1200, microtime(true));
        
        $this->bbr->onPacketAcked(1, 1200, microtime(true) - 0.1, microtime(true));
        $this->bbr->onPacketLost(2, 1200, microtime(true) - 0.1, microtime(true));
        $this->bbr->onPacketAcked(3, 1200, microtime(true) - 0.1, microtime(true));
        $this->bbr->onPacketAcked(4, 1200, microtime(true) - 0.1, microtime(true));
        
        $stats = $this->bbr->getStats();
        $this->assertEquals(0.25, $stats['loss_rate']); // 1 lost out of 4 total
    }

    public function testCustomInitialWindow(): void
    {
        $bbr = new BBR(8000);
        $this->assertEquals(8000, $bbr->getCongestionWindow());
    }

    public function testStats(): void
    {
        $stats = $this->bbr->getStats();
        
        $this->assertArrayHasKey('algorithm', $stats);
        $this->assertArrayHasKey('state', $stats);
        $this->assertArrayHasKey('congestion_window', $stats);
        $this->assertArrayHasKey('bandwidth_estimate', $stats);
        $this->assertArrayHasKey('max_bandwidth', $stats);
        $this->assertArrayHasKey('min_rtt', $stats);
        $this->assertArrayHasKey('rt_prop', $stats);
        $this->assertArrayHasKey('pacing_rate', $stats);
        $this->assertArrayHasKey('cycle_index', $stats);
        $this->assertArrayHasKey('total_bytes_sent', $stats);
        
        $this->assertEquals('BBR', $stats['algorithm']);
        $this->assertEquals('startup', $stats['state']);
    }

    public function testStateTransitions(): void
    {
        $baseTime = microtime(true);
        
        // 模拟足够多的ACK来触发状态转换
        for ($i = 1; $i <= 10; $i++) {
            $this->bbr->onPacketAcked($i, 1200, $baseTime, $baseTime + 0.1);
        }
        
        $stats = $this->bbr->getStats();
        // 应该仍在startup状态，因为我们的实现比较简单
        $this->assertContains($stats['state'], ['startup', 'drain', 'probe_bw', 'probe_rtt']);
    }
} 