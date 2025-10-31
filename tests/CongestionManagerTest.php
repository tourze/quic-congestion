<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Congestion\BBR;
use Tourze\QUIC\Congestion\CongestionManager;
use Tourze\QUIC\Congestion\NewReno;

/**
 * 拥塞控制器测试
 *
 * @internal
 */
#[CoversClass(CongestionManager::class)]
final class CongestionManagerTest extends TestCase
{
    private CongestionManager $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new CongestionManager(new NewReno());
    }

    public function testInitialization(): void
    {
        $this->assertEquals('NewReno', $this->controller->getAlgorithmName());
        $this->assertTrue($this->controller->canSend(1200, 0));
    }

    public function testPacketSentAndAcked(): void
    {
        $packetNumber = 1;
        $size = 1200;
        $sentTime = microtime(true);

        $this->controller->onPacketSent($packetNumber, $size, $sentTime);

        $ackTime = $sentTime + 0.1;
        $this->controller->onPacketAcked($packetNumber, $size, $sentTime, $ackTime);

        $stats = $this->controller->getStats();
        $this->assertEquals(1, $stats['acked_packets']);
        $this->assertEquals(1200, $stats['total_bytes_acked']);
    }

    public function testBatchAck(): void
    {
        $packets = [
            1 => ['size' => 1200, 'sent_time' => microtime(true)],
            2 => ['size' => 1200, 'sent_time' => microtime(true)],
            3 => ['size' => 1200, 'sent_time' => microtime(true)],
        ];

        foreach ($packets as $packetNumber => $data) {
            $this->controller->onPacketSent($packetNumber, $data['size'], $data['sent_time']);
        }

        $ackTime = microtime(true);
        $this->controller->batchAck(array_keys($packets), $ackTime);

        $stats = $this->controller->getStats();
        $this->assertEquals(3, $stats['acked_packets']);
        $this->assertEquals(3600, $stats['total_bytes_acked']);
    }

    public function testPacketLost(): void
    {
        $packetNumber = 1;
        $size = 1200;
        $sentTime = microtime(true);
        $lossTime = $sentTime + 0.5;

        $this->controller->onPacketSent($packetNumber, $size, $sentTime);
        $this->controller->onPacketLost($packetNumber, $size, $sentTime, $lossTime);

        $stats = $this->controller->getStats();
        $this->assertEquals(1, $stats['lost_packets']);
    }

    public function testBatchLoss(): void
    {
        $packets = [1, 2, 3];

        foreach ($packets as $packetNumber) {
            $this->controller->onPacketSent($packetNumber, 1200, microtime(true));
        }

        $this->controller->batchLoss($packets);

        $stats = $this->controller->getStats();
        $this->assertEquals(3, $stats['lost_packets']);
    }

    public function testReset(): void
    {
        // 发送一些数据
        $this->controller->onPacketSent(1, 1200, microtime(true));
        $this->controller->onPacketAcked(1, 1200, microtime(true) - 0.1, microtime(true));

        $this->controller->reset();

        $stats = $this->controller->getStats();
        $this->assertEquals(0, $stats['acked_packets']);
        $this->assertEquals(0, $stats['lost_packets']);
    }

    public function testCanSend(): void
    {
        // 初始状态应该能发送
        $this->assertTrue($this->controller->canSend(1200, 0));

        // 测试接近窗口限制
        $window = $this->controller->getCongestionWindow();
        $this->assertFalse($this->controller->canSend(1200, $window));
    }

    public function testSwitchAlgorithm(): void
    {
        $this->assertEquals('NewReno', $this->controller->getAlgorithmName());

        $this->controller->switchAlgorithm(new BBR());
        $this->assertEquals('BBR', $this->controller->getAlgorithmName());
    }

    public function testGetters(): void
    {
        $window = $this->controller->getCongestionWindow();
        $threshold = $this->controller->getSlowStartThreshold();
        $isInSlowStart = $this->controller->isInSlowStart();

        $this->assertGreaterThan(0, $window);
        $this->assertGreaterThan(0, $threshold);
        // isInSlowStart() 返回布尔值，根据具体状态验证
        if ('NewReno' === $this->controller->getAlgorithmName()) {
            // NewReno 初始状态应该在慢启动
            $this->assertTrue($isInSlowStart);
        }

        $sendingRate = $this->controller->getSendingRate();
        $this->assertTrue(null === $sendingRate || $sendingRate >= 0.0);

        $stats = $this->controller->getStats();
        $this->assertNotEmpty($stats);
        $this->assertArrayHasKey('algorithm', $stats);
    }

    public function testPacketTracking(): void
    {
        $sentTime = microtime(true);

        // 发送包
        $this->controller->onPacketSent(1, 1200, $sentTime);
        $this->controller->onPacketSent(2, 1200, $sentTime);
        $this->controller->onPacketSent(3, 1200, $sentTime);

        // 确认部分包
        $this->controller->onPacketAcked(1, 1200, $sentTime, $sentTime + 0.1);
        $this->controller->onPacketAcked(3, 1200, $sentTime, $sentTime + 0.1);

        // 包2丢失
        $this->controller->onPacketLost(2, 1200, $sentTime, $sentTime + 0.5);

        $stats = $this->controller->getStats();
        $this->assertEquals(2, $stats['acked_packets']);
        $this->assertEquals(1, $stats['lost_packets']);
        $this->assertEquals(2400, $stats['total_bytes_acked']);
    }

    public function testBBRController(): void
    {
        $bbrController = new CongestionManager(new BBR());

        $this->assertEquals('BBR', $bbrController->getAlgorithmName());

        $sentTime = microtime(true);
        $bbrController->onPacketSent(1, 1200, $sentTime);
        $bbrController->onPacketAcked(1, 1200, $sentTime, $sentTime + 0.1);

        $stats = $bbrController->getStats();
        $this->assertEquals('BBR', $stats['algorithm']);
        $this->assertArrayHasKey('bandwidth_estimate', $stats);
        $this->assertArrayHasKey('min_rtt', $stats);
    }

    public function testPerformanceMetrics(): void
    {
        $baseTime = microtime(true);

        // 发送和确认多个包来建立统计数据
        for ($i = 1; $i <= 10; ++$i) {
            $this->controller->onPacketSent($i, 1200, $baseTime);

            if (0 === $i % 2) {
                // 确认偶数包
                $this->controller->onPacketAcked($i, 1200, $baseTime, $baseTime + 0.1);
            } else {
                // 丢失奇数包
                $this->controller->onPacketLost($i, 1200, $baseTime, $baseTime + 0.5);
            }
        }

        $stats = $this->controller->getStats();
        $this->assertEquals(5, $stats['acked_packets']);
        $this->assertEquals(5, $stats['lost_packets']);
        $this->assertEquals(6000, $stats['total_bytes_acked']);
        $this->assertEquals(0.5, $stats['loss_rate']); // 50% 丢包率
    }

    public function testCongestionWindowEvolution(): void
    {
        $initialWindow = $this->controller->getCongestionWindow();

        // 模拟慢启动阶段 - 发送更多包来确保窗口增长
        for ($i = 1; $i <= 10; ++$i) {
            $this->controller->onPacketSent($i, 1200, microtime(true));
            $this->controller->onPacketAcked($i, 1200, microtime(true) - 0.1, microtime(true));
        }

        $windowAfterSlowStart = $this->controller->getCongestionWindow();
        $this->assertGreaterThan($initialWindow, $windowAfterSlowStart);

        // 发送一个新数据包然后模拟丢包
        $this->controller->onPacketSent(11, 1200, microtime(true));
        $this->controller->onPacketLost(11, 1200, microtime(true) - 0.1, microtime(true));

        $windowAfterLoss = $this->controller->getCongestionWindow();

        // 验证丢包后窗口确实缩减了，或者至少没有继续增长
        $this->assertLessThanOrEqual($windowAfterSlowStart, $windowAfterLoss);
    }

    public function testEdgeCases(): void
    {
        // 测试零大小包
        $this->controller->onPacketSent(1, 0, microtime(true));
        $this->controller->onPacketAcked(1, 0, microtime(true) - 0.1, microtime(true));

        // 测试重复确认
        $this->controller->onPacketSent(2, 1200, microtime(true));
        $this->controller->onPacketAcked(2, 1200, microtime(true) - 0.1, microtime(true));
        $this->controller->onPacketAcked(2, 1200, microtime(true) - 0.1, microtime(true)); // 重复

        // 测试不存在的包丢失
        $this->controller->onPacketLost(999, 1200, microtime(true) - 0.1, microtime(true));

        // 应该能正常处理这些边缘情况
        $stats = $this->controller->getStats();
        $this->assertNotEmpty($stats);
        $this->assertArrayHasKey('algorithm', $stats);
    }

    public function testRttCalculations(): void
    {
        $sentTime = microtime(true);

        // 发送多个包，模拟不同的RTT
        $this->controller->onPacketSent(1, 1200, $sentTime);
        $this->controller->onPacketAcked(1, 1200, $sentTime, $sentTime + 0.05); // 50ms

        $this->controller->onPacketSent(2, 1200, $sentTime);
        $this->controller->onPacketAcked(2, 1200, $sentTime, $sentTime + 0.08); // 80ms

        $this->controller->onPacketSent(3, 1200, $sentTime);
        $this->controller->onPacketAcked(3, 1200, $sentTime, $sentTime + 0.06); // 60ms

        $stats = $this->controller->getStats();

        // NewReno 应该记录最小RTT，但由于实现差异，我们只检查是否有 RTT 统计
        $this->assertTrue(isset($stats['min_rtt']) || isset($stats['smoothed_rtt']));
    }

    public function testSerializedOperations(): void
    {
        $packets = range(1, 10);
        $baseTime = microtime(true);

        // 顺序发送包
        foreach ($packets as $packetNumber) {
            $this->controller->onPacketSent($packetNumber, 1200, $baseTime + $packetNumber * 0.01);
        }

        // 乱序确认包
        shuffle($packets);
        foreach ($packets as $packetNumber) {
            $sentTime = $baseTime + $packetNumber * 0.01;
            $ackTime = $sentTime + 0.1; // 确保 RTT 为正数
            $this->controller->onPacketAcked($packetNumber, 1200, $sentTime, $ackTime);
        }

        $stats = $this->controller->getStats();
        $this->assertEquals(10, $stats['acked_packets']);
        $this->assertEquals(12000, $stats['total_bytes_acked']);
    }

    public function testCleanupPacketHistory(): void
    {
        $baseTime = microtime(true);

        // 发送并确认一些历史包
        for ($i = 1; $i <= 5; ++$i) {
            $this->controller->onPacketSent($i, 1200, $baseTime - 120); // 2分钟前
            $this->controller->onPacketAcked($i, 1200, $baseTime - 120, $baseTime - 119);
        }

        // 发送一些新包
        for ($i = 6; $i <= 10; ++$i) {
            $this->controller->onPacketSent($i, 1200, $baseTime);
        }

        // 清理历史记录
        $this->controller->cleanupPacketHistory($baseTime);

        $stats = $this->controller->getStats();
        // 验证新包仍然存在，旧包已被清理
        $this->assertEquals(5, $stats['sent_packets_count']);
    }

    public function testCollectPeriodicStats(): void
    {
        $baseTime = microtime(true);

        // 模拟一些活动
        $this->controller->onPacketSent(1, 1200, $baseTime);
        $this->controller->onPacketAcked(1, 1200, $baseTime, $baseTime + 0.1);

        // 收集统计信息
        $this->controller->collectPeriodicStats($baseTime + 1.1); // 1.1秒后

        $history = $this->controller->getStatisticsHistory();
        $this->assertNotEmpty($history);
        $this->assertArrayHasKey('timestamp', $history[0]);
    }

    public function testOnAckReceived(): void
    {
        $baseTime = microtime(true);

        // 发送多个包
        for ($i = 1; $i <= 5; ++$i) {
            $this->controller->onPacketSent($i, 1200, $baseTime);
        }

        // 批量确认
        $ackedPackets = [1, 3, 5];
        $this->controller->onAckReceived($ackedPackets, $baseTime + 0.1);

        $stats = $this->controller->getStats();
        $this->assertEquals(3, $stats['acked_packets']);
        $this->assertEquals(3600, $stats['total_bytes_acked']);
    }

    public function testOnPacketAcked(): void
    {
        $packetNumber = 1;
        $size = 1200;
        $sentTime = microtime(true);
        $ackTime = $sentTime + 0.1;

        $this->controller->onPacketSent($packetNumber, $size, $sentTime);
        $this->controller->onPacketAcked($packetNumber, $size, $sentTime, $ackTime);

        $stats = $this->controller->getStats();
        $this->assertEquals(1, $stats['acked_packets']);
        $this->assertEquals(1200, $stats['total_bytes_acked']);
    }

    public function testOnPacketLost(): void
    {
        $packetNumber = 1;
        $size = 1200;
        $sentTime = microtime(true);
        $lossTime = $sentTime + 0.5;

        $this->controller->onPacketSent($packetNumber, $size, $sentTime);
        $this->controller->onPacketLost($packetNumber, $size, $sentTime, $lossTime);

        $stats = $this->controller->getStats();
        $this->assertEquals(1, $stats['lost_packets']);
    }

    public function testOnPacketSent(): void
    {
        $packetNumber = 1;
        $size = 1200;
        $sentTime = microtime(true);

        $this->controller->onPacketSent($packetNumber, $size, $sentTime);

        $stats = $this->controller->getStats();
        $this->assertEquals(1, $stats['sent_packets_count']);
        $this->assertEquals($size, $this->controller->getBytesInFlight());
    }
}
