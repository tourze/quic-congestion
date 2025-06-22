<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion;

/**
 * 拥塞窗口管理器
 * 
 * 负责管理QUIC连接的拥塞窗口大小
 * 提供窗口增长、减少和验证功能
 */
final class CongestionWindow
{
    // 最大数据包大小（字节）
    private const MAX_DATAGRAM_SIZE = 1200;
    
    // 最小拥塞窗口（2个数据包）
    private const MIN_WINDOW_SIZE = 2 * self::MAX_DATAGRAM_SIZE;
    
    // 最大拥塞窗口（64MB）
    private const MAX_WINDOW_SIZE = 64 * 1024 * 1024;
    
    // 初始拥塞窗口（10个数据包）
    private const INITIAL_WINDOW_SIZE = 10 * self::MAX_DATAGRAM_SIZE;

    private int $congestionWindow;
    private int $slowStartThreshold;
    private int $bytesInFlight = 0;
    private float $lastUpdateTime = 0.0;

    public function __construct(
        int $initialWindow = self::INITIAL_WINDOW_SIZE,
        int $initialSSThresh = self::MAX_WINDOW_SIZE
    ) {
        $this->congestionWindow = max($initialWindow, self::MIN_WINDOW_SIZE);
        $this->slowStartThreshold = $initialSSThresh;
        $this->lastUpdateTime = microtime(true);
    }

    /**
     * 获取当前拥塞窗口大小
     */
    public function getSize(): int
    {
        return $this->congestionWindow;
    }

    /**
     * 获取慢启动阈值
     */
    public function getSlowStartThreshold(): int
    {
        return $this->slowStartThreshold;
    }

    /**
     * 设置拥塞窗口大小
     */
    public function setSize(int $size): void
    {
        $this->congestionWindow = $this->clampWindow($size);
        $this->lastUpdateTime = microtime(true);
    }

    /**
     * 设置慢启动阈值
     */
    public function setSlowStartThreshold(int $threshold): void
    {
        $this->slowStartThreshold = max($threshold, self::MIN_WINDOW_SIZE);
    }

    /**
     * 检查是否处于慢启动阶段
     */
    public function isInSlowStart(): bool
    {
        return $this->congestionWindow < $this->slowStartThreshold;
    }

    /**
     * 慢启动窗口增长
     *
     * @param int $ackedBytes 确认的字节数
     */
    public function slowStartIncrease(int $ackedBytes): void
    {
        if (!$this->isInSlowStart()) {
            return;
        }

        // 慢启动：每收到一个ACK，窗口增加一个MSS
        $this->congestionWindow += $ackedBytes;
        $this->congestionWindow = $this->clampWindow($this->congestionWindow);
        $this->lastUpdateTime = microtime(true);
    }

    /**
     * 拥塞避免窗口增长
     *
     * @param int $ackedBytes 确认的字节数
     */
    public function congestionAvoidanceIncrease(int $ackedBytes): void
    {
        if ($this->isInSlowStart()) {
            return;
        }

        // 拥塞避免：每个RTT增加一个MSS
        $increment = (self::MAX_DATAGRAM_SIZE * $ackedBytes) / $this->congestionWindow;
        $this->congestionWindow += max(1, (int) $increment);
        $this->congestionWindow = $this->clampWindow($this->congestionWindow);
        $this->lastUpdateTime = microtime(true);
    }

    /**
     * 检测到拥塞时减少窗口
     *
     * @param float $reductionFactor 减少因子（默认0.5）
     */
    public function reduceCongestion(float $reductionFactor = 0.5): void
    {
        if ($reductionFactor <= 0.0 || $reductionFactor >= 1.0) {
            throw new \InvalidArgumentException('减少因子必须在0和1之间');
        }

        // 更新慢启动阈值
        $this->slowStartThreshold = max(
            (int) ($this->congestionWindow * $reductionFactor),
            self::MIN_WINDOW_SIZE
        );

        // 减少拥塞窗口
        $this->congestionWindow = $this->slowStartThreshold;
        $this->lastUpdateTime = microtime(true);
    }

    /**
     * 检查是否可以发送数据包
     *
     * @param int $packetSize 数据包大小
     * @param int $bytesInFlight 当前在传输中的字节数
     */
    public function canSend(int $packetSize, int $bytesInFlight): bool
    {
        return $bytesInFlight + $packetSize <= $this->congestionWindow;
    }

    /**
     * 获取可用的发送窗口大小
     *
     * @param int $bytesInFlight 当前在传输中的字节数
     */
    public function getAvailableWindow(int $bytesInFlight): int
    {
        return max(0, $this->congestionWindow - $bytesInFlight);
    }

    /**
     * 更新在传输中的字节数
     */
    public function setBytesInFlight(int $bytes): void
    {
        $this->bytesInFlight = max(0, $bytes);
    }

    /**
     * 获取在传输中的字节数
     */
    public function getBytesInFlight(): int
    {
        return $this->bytesInFlight;
    }

    /**
     * 限制窗口大小在有效范围内
     */
    private function clampWindow(int $window): int
    {
        return max(self::MIN_WINDOW_SIZE, min($window, self::MAX_WINDOW_SIZE));
    }

    /**
     * 重置拥塞窗口状态
     */
    public function reset(): void
    {
        $this->congestionWindow = self::INITIAL_WINDOW_SIZE;
        $this->slowStartThreshold = self::MAX_WINDOW_SIZE;
        $this->bytesInFlight = 0;
        $this->lastUpdateTime = microtime(true);
    }

    /**
     * 获取窗口统计信息
     */
    public function getStats(): array
    {
        return [
            'congestion_window' => $this->congestionWindow,
            'slow_start_threshold' => $this->slowStartThreshold,
            'bytes_in_flight' => $this->bytesInFlight,
            'is_slow_start' => $this->isInSlowStart(),
            'available_window' => $this->getAvailableWindow($this->bytesInFlight),
            'last_update_time' => $this->lastUpdateTime,
            'window_utilization' => $this->bytesInFlight / $this->congestionWindow,
        ];
    }
} 