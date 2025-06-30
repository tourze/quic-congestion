<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion;

use Tourze\QUIC\Recovery\LossDetection;
use Tourze\QUIC\Recovery\RTTEstimator;

/**
 * 拥塞控制器
 *
 * 协调拥塞控制、丢包检测和RTT估算
 * 提供统一的接口来管理QUIC连接的拥塞控制
 */
final class CongestionController
{
    private CongestionControlInterface $congestionControl;
    private RTTEstimator $rttEstimator;
    private ?LossDetection $lossDetection;
    private int $bytesInFlight = 0;
    private array $sentPackets = [];
    private float $lastStatsTime = 0.0;
    private array $statisticsHistory = [];

    public function __construct(
        CongestionControlInterface $congestionControl,
        ?RTTEstimator $rttEstimator = null,
        ?LossDetection $lossDetection = null
    ) {
        $this->congestionControl = $congestionControl;
        $this->rttEstimator = $rttEstimator ?? new RTTEstimator();
        
        // 注意：这里需要PacketTracker，但由于依赖复杂，简化处理
        // 在实际使用中应该注入正确配置的LossDetection
        $this->lossDetection = $lossDetection;
        $this->lastStatsTime = microtime(true);
    }

    /**
     * 当数据包发送时调用
     */
    public function onPacketSent(int $packetNumber, int $bytes, float $sentTime): void
    {
        $this->sentPackets[$packetNumber] = [
            'bytes' => $bytes,
            'sent_time' => $sentTime,
            'acked' => false,
            'lost' => false,
        ];
        
        $this->bytesInFlight += $bytes;
        $this->congestionControl->onPacketSent($packetNumber, $bytes, $sentTime);
    }

    /**
     * 当收到ACK时调用
     */
    public function onAckReceived(array $ackedPackets, float $ackTime): void
    {
        $totalAckedBytes = 0;
        
        foreach ($ackedPackets as $packetNumber) {
            if (!isset($this->sentPackets[$packetNumber]) || 
                $this->sentPackets[$packetNumber]['acked']) {
                continue;
            }
            
            $packet = $this->sentPackets[$packetNumber];
            $this->sentPackets[$packetNumber]['acked'] = true;
            $this->bytesInFlight -= $packet['bytes'];
            $totalAckedBytes += $packet['bytes'];
            
            // 更新RTT
            $rtt = $ackTime - $packet['sent_time'];
            $this->rttEstimator->updateRtt($rtt);
            
            // 通知拥塞控制算法
            $this->congestionControl->onPacketAcked(
                $packetNumber,
                $packet['bytes'],
                $packet['sent_time'],
                $ackTime
            );
        }
        
        // 检测丢包
        if ($this->lossDetection !== null) {
            $lossResult = $this->lossDetection->detectLostPackets($ackTime);
            $this->handleLostPackets($lossResult['lost_packets'], $ackTime);
        }
    }

    /**
     * 当单个数据包被确认时调用
     */
    public function onPacketAcked(int $packetNumber, int $bytes, float $sentTime, float $ackTime): void
    {
        if (!isset($this->sentPackets[$packetNumber]) || 
            $this->sentPackets[$packetNumber]['acked']) {
            return;
        }
        
        $this->sentPackets[$packetNumber]['acked'] = true;
        $this->bytesInFlight -= $bytes;
        
        // 更新RTT - 确保RTT为正数
        $rtt = $ackTime - $sentTime;
        if ($rtt > 0.0) {
            $this->rttEstimator->updateRtt($rtt);
        }
        
        // 通知拥塞控制算法
        $this->congestionControl->onPacketAcked($packetNumber, $bytes, $sentTime, $ackTime);
    }

    /**
     * 当单个数据包丢失时调用
     */
    public function onPacketLost(int $packetNumber, int $bytes, float $sentTime, float $lossTime): void
    {
        if (!isset($this->sentPackets[$packetNumber]) || 
            $this->sentPackets[$packetNumber]['lost']) {
            return;
        }
        
        $this->sentPackets[$packetNumber]['lost'] = true;
        
        if (!$this->sentPackets[$packetNumber]['acked']) {
            $this->bytesInFlight -= $bytes;
        }
        
        $this->congestionControl->onPacketLost($packetNumber, $bytes, $sentTime, $lossTime);
    }

    /**
     * 批量确认数据包
     */
    public function batchAck(array $packetNumbers, float $ackTime): void
    {
        foreach ($packetNumbers as $packetNumber) {
            if (isset($this->sentPackets[$packetNumber])) {
                $packet = $this->sentPackets[$packetNumber];
                $this->onPacketAcked($packetNumber, $packet['bytes'], $packet['sent_time'], $ackTime);
            }
        }
    }

    /**
     * 批量标记数据包丢失
     */
    public function batchLoss(array $packetNumbers): void
    {
        $lossTime = microtime(true);
        
        foreach ($packetNumbers as $packetNumber) {
            if (isset($this->sentPackets[$packetNumber])) {
                $packet = $this->sentPackets[$packetNumber];
                $this->onPacketLost($packetNumber, $packet['bytes'], $packet['sent_time'], $lossTime);
            }
        }
    }

    /**
     * 处理丢失的数据包
     */
    private function handleLostPackets(array $lostPackets, float $lossTime): void
    {
        foreach ($lostPackets as $packetNumber) {
            if (!isset($this->sentPackets[$packetNumber]) || 
                $this->sentPackets[$packetNumber]['lost']) {
                continue;
            }
            
            $packet = $this->sentPackets[$packetNumber];
            $this->sentPackets[$packetNumber]['lost'] = true;
            
            if (!$packet['acked']) {
                $this->bytesInFlight -= $packet['bytes'];
            }
            
            $this->congestionControl->onPacketLost(
                $packetNumber,
                $packet['bytes'],
                $packet['sent_time'],
                $lossTime
            );
        }
    }

    /**
     * 检查是否可以发送数据包
     */
    public function canSend(int $bytes, ?int $bytesInFlight = null): bool
    {
        $inFlight = $bytesInFlight ?? $this->bytesInFlight;
        return $this->congestionControl->canSend($bytes, $inFlight);
    }

    /**
     * 获取可用的发送窗口大小
     */
    public function getAvailableWindow(): int
    {
        $congestionWindow = $this->congestionControl->getCongestionWindow();
        return max(0, $congestionWindow - $this->bytesInFlight);
    }

    /**
     * 获取当前拥塞窗口大小
     */
    public function getCongestionWindow(): int
    {
        return $this->congestionControl->getCongestionWindow();
    }

    /**
     * 获取发送速率（如果支持）
     */
    public function getSendingRate(): ?float
    {
        return $this->congestionControl->getSendingRate();
    }

    /**
     * 检查是否处于慢启动阶段
     */
    public function isInSlowStart(): bool
    {
        return $this->congestionControl->isInSlowStart();
    }

    /**
     * 获取在传输中的字节数
     */
    public function getBytesInFlight(): int
    {
        return $this->bytesInFlight;
    }

    /**
     * 获取RTT统计信息
     */
    public function getRttStats(): array
    {
        return $this->rttEstimator->getStats();
    }

    /**
     * 重置拥塞控制状态
     */
    public function reset(): void
    {
        $this->congestionControl->reset();
        $this->rttEstimator->reset();
        $this->bytesInFlight = 0;
        $this->sentPackets = [];
        $this->statisticsHistory = [];
        $this->lastStatsTime = microtime(true);
    }

    /**
     * 获取完整的统计信息
     */
    public function getStats(): array
    {
        $congestionStats = $this->congestionControl->getStats();
        $rttStats = $this->rttEstimator->getStats();
        
        // 合并统计信息，使用平铺结构
        $stats = array_merge($congestionStats, [
            'bytes_in_flight' => $this->bytesInFlight,
            'available_window' => $this->getAvailableWindow(),
            'sent_packets_count' => count($this->sentPackets),
            'unacked_packets' => $this->countUnackedPackets(),
            'lost_packets_total' => $this->countLostPackets(),
            'utilization' => $this->calculateUtilization(),
        ]);
        
        // 添加RTT相关统计
        if (isset($rttStats['min_rtt'])) {
            $stats['min_rtt'] = $rttStats['min_rtt'];
        }
        if (isset($rttStats['smoothed_rtt'])) {
            $stats['smoothed_rtt'] = $rttStats['smoothed_rtt'];
        }
        if (isset($rttStats['rtt_var'])) {
            $stats['rtt_var'] = $rttStats['rtt_var'];
        }
        
        return $stats;
    }

    /**
     * 计算窗口利用率
     */
    private function calculateUtilization(): float
    {
        $congestionWindow = $this->congestionControl->getCongestionWindow();
        if ($congestionWindow === 0) {
            return 0.0;
        }
        
        return $this->bytesInFlight / $congestionWindow;
    }

    /**
     * 统计未确认的数据包数量
     */
    private function countUnackedPackets(): int
    {
        return count(array_filter($this->sentPackets, function ($packet) {
            return !$packet['acked'] && !$packet['lost'];
        }));
    }

    /**
     * 统计丢失的数据包数量
     */
    private function countLostPackets(): int
    {
        return count(array_filter($this->sentPackets, function ($packet) {
            return $packet['lost'];
        }));
    }

    /**
     * 获取拥塞控制算法名称
     */
    public function getAlgorithmName(): string
    {
        return $this->congestionControl->getAlgorithmName();
    }

    /**
     * 切换拥塞控制算法
     */
    public function switchAlgorithm(CongestionControlInterface $newAlgorithm): void
    {
        // 保存当前状态
        $oldStats = $this->congestionControl->getStats();
        
        // 切换算法
        $this->congestionControl = $newAlgorithm;
        
        // 记录切换事件
        $this->recordAlgorithmSwitch($oldStats, $newAlgorithm->getAlgorithmName());
    }

    /**
     * 记录算法切换事件
     */
    private function recordAlgorithmSwitch(array $oldStats, string $newAlgorithm): void
    {
        $currentTime = microtime(true);
        
        $this->statisticsHistory[] = [
            'timestamp' => $currentTime,
            'event' => 'algorithm_switch',
            'old_algorithm' => $oldStats['algorithm'] ?? 'unknown',
            'new_algorithm' => $newAlgorithm,
            'old_stats' => $oldStats,
        ];
    }

    /**
     * 定期收集统计信息
     */
    public function collectPeriodicStats(float $currentTime): void
    {
        if ($currentTime - $this->lastStatsTime >= 1.0) { // 每秒收集一次
            $stats = $this->getStats();
            $stats['timestamp'] = $currentTime;
            
            $this->statisticsHistory[] = $stats;
            $this->lastStatsTime = $currentTime;
            
            // 限制历史记录长度
            if (count($this->statisticsHistory) > 300) { // 保留5分钟历史
                array_shift($this->statisticsHistory);
            }
        }
    }

    /**
     * 获取统计历史
     */
    public function getStatisticsHistory(): array
    {
        return $this->statisticsHistory;
    }

    /**
     * 清理已确认和丢失的数据包记录
     */
    public function cleanupPacketHistory(float $currentTime): void
    {
        $cutoffTime = $currentTime - 60.0; // 清理1分钟前的记录
        
        foreach ($this->sentPackets as $packetNumber => $packet) {
            if (($packet['acked'] || $packet['lost']) && 
                $packet['sent_time'] < $cutoffTime) {
                unset($this->sentPackets[$packetNumber]);
            }
        }
    }

    /**
     * 获取当前拥塞控制实例（用于测试和调试）
     */
    public function getCongestionControlInstance(): CongestionControlInterface
    {
        return $this->congestionControl;
    }

    /**
     * 获取RTT估算器实例（用于测试和调试）
     */
    public function getRttEstimatorInstance(): RTTEstimator
    {
        return $this->rttEstimator;
    }

    /**
     * 获取慢启动阈值
     */
    public function getSlowStartThreshold(): int
    {
        return $this->congestionControl->getSlowStartThreshold();
    }
} 