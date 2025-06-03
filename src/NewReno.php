<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion;

/**
 * NewReno拥塞控制算法实现
 * 
 * 基于RFC 5681和RFC 9002实现的TCP NewReno算法的QUIC版本
 * 包含慢启动、拥塞避免、快速重传和快速恢复机制
 */
final class NewReno implements CongestionControlInterface
{
    private const ALGORITHM_NAME = 'NewReno';
    
    // 拥塞减少因子
    private const REDUCTION_FACTOR = 0.5;
    
    // 最大数据包大小
    private const MAX_DATAGRAM_SIZE = 1200;

    private CongestionWindow $window;
    private bool $inRecovery = false;
    private int $recoveryPacketNumber = 0;
    private int $ackedPackets = 0;
    private int $lostPackets = 0;
    private float $lastLossTime = 0.0;
    private int $totalBytesSent = 0;
    private int $totalBytesAcked = 0;
    private int $totalBytesLost = 0;

    public function __construct(?CongestionWindow $window = null)
    {
        $this->window = $window ?? new CongestionWindow();
    }

    public function onPacketAcked(int $packetNumber, int $bytes, float $sentTime, float $ackTime): void
    {
        $this->ackedPackets++;
        $this->totalBytesAcked += $bytes;

        // 检查是否退出快速恢复
        if ($this->inRecovery && $packetNumber > $this->recoveryPacketNumber) {
            $this->inRecovery = false;
        }

        // 如果处于快速恢复阶段，不增加窗口
        if ($this->inRecovery) {
            return;
        }

        // 根据当前阶段增加拥塞窗口
        if ($this->window->isInSlowStart()) {
            $this->window->slowStartIncrease($bytes);
        } else {
            $this->window->congestionAvoidanceIncrease($bytes);
        }
    }

    public function onPacketLost(int $packetNumber, int $bytes, float $sentTime, float $lossTime): void
    {
        $this->lostPackets++;
        $this->totalBytesLost += $bytes;
        $this->lastLossTime = $lossTime;

        // 避免同一个拥塞事件多次减少窗口
        if ($this->inRecovery && $packetNumber <= $this->recoveryPacketNumber) {
            return;
        }

        // 进入快速恢复状态
        $this->enterRecovery($packetNumber);

        // 减少拥塞窗口
        $this->window->reduceCongestion(self::REDUCTION_FACTOR);
    }

    public function onPacketSent(int $packetNumber, int $bytes, float $sentTime): void
    {
        $this->totalBytesSent += $bytes;
    }

    public function getCongestionWindow(): int
    {
        return $this->window->getSize();
    }

    public function getSlowStartThreshold(): int
    {
        return $this->window->getSlowStartThreshold();
    }

    public function canSend(int $bytes, int $bytesInFlight): bool
    {
        return $this->window->canSend($bytes, $bytesInFlight);
    }

    public function getSendingRate(): ?float
    {
        // NewReno是基于窗口的算法，不提供发送速率
        return null;
    }

    public function isInSlowStart(): bool
    {
        return $this->window->isInSlowStart();
    }

    public function reset(): void
    {
        $this->window->reset();
        $this->inRecovery = false;
        $this->recoveryPacketNumber = 0;
        $this->ackedPackets = 0;
        $this->lostPackets = 0;
        $this->lastLossTime = 0.0;
        $this->totalBytesSent = 0;
        $this->totalBytesAcked = 0;
        $this->totalBytesLost = 0;
    }

    public function getStats(): array
    {
        $windowStats = $this->window->getStats();
        
        return array_merge($windowStats, [
            'algorithm' => self::ALGORITHM_NAME,
            'in_recovery' => $this->inRecovery,
            'recovery_packet_number' => $this->recoveryPacketNumber,
            'acked_packets' => $this->ackedPackets,
            'lost_packets' => $this->lostPackets,
            'loss_rate' => $this->calculateLossRate(),
            'total_bytes_sent' => $this->totalBytesSent,
            'total_bytes_acked' => $this->totalBytesAcked,
            'total_bytes_lost' => $this->totalBytesLost,
            'last_loss_time' => $this->lastLossTime,
        ]);
    }

    public function getAlgorithmName(): string
    {
        return self::ALGORITHM_NAME;
    }

    /**
     * 检查是否处于快速恢复阶段
     */
    public function isInRecovery(): bool
    {
        return $this->inRecovery;
    }

    /**
     * 获取拥塞窗口对象
     */
    public function getCongestionWindowObject(): CongestionWindow
    {
        return $this->window;
    }

    /**
     * 进入快速恢复状态
     */
    private function enterRecovery(int $packetNumber): void
    {
        $this->inRecovery = true;
        $this->recoveryPacketNumber = $packetNumber;
    }

    /**
     * 计算丢包率
     */
    private function calculateLossRate(): float
    {
        if ($this->totalBytesSent === 0) {
            return 0.0;
        }

        return $this->totalBytesLost / $this->totalBytesSent;
    }

    /**
     * 检查是否应该减少拥塞窗口
     * 
     * @param float $currentTime 当前时间
     */
    public function shouldReduceCongestionWindow(float $currentTime): bool
    {
        // 如果已经在快速恢复中，不再减少
        if ($this->inRecovery) {
            return false;
        }

        // 如果最近没有丢包，不需要减少
        if ($this->lastLossTime === 0.0) {
            return false;
        }

        // 其他检查逻辑可以在这里添加
        return true;
    }

    /**
     * 获取当前拥塞状态描述
     */
    public function getCongestionState(): string
    {
        if ($this->inRecovery) {
            return 'fast_recovery';
        }

        if ($this->window->isInSlowStart()) {
            return 'slow_start';
        }

        return 'congestion_avoidance';
    }

    /**
     * 设置拥塞窗口大小（用于测试和调试）
     */
    public function setCongestionWindow(int $size): void
    {
        $this->window->setSize($size);
    }

    /**
     * 设置慢启动阈值（用于测试和调试）
     */
    public function setSlowStartThreshold(int $threshold): void
    {
        $this->window->setSlowStartThreshold($threshold);
    }
} 