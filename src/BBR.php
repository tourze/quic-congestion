<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion;

/**
 * BBR拥塞控制算法实现
 *
 * 基于Google的BBR v1算法实现，专注于带宽估算和RTT测量
 * 使用基于模型的方法而非丢包作为拥塞信号
 */
final class BBR implements CongestionControlInterface
{
    private const ALGORITHM_NAME = 'BBR';
    
    // BBR算法常量
    private const BBR_STARTUP_GROWTH_TARGET = 1.25;   // 启动阶段增长目标
    private const BBR_GAIN_CYCLE_LENGTH = 8;          // 增益循环长度
    private const BBR_HIGH_GAIN = 2.885;              // 高增益
    private const BBR_PROBE_RTT_DURATION = 200.0;    // ProbeRTT持续时间（毫秒）
    private const BBR_MIN_PIPE_CWND = 4;              // 最小管道拥塞窗口（包数）
    
    // 状态常量
    private const STATE_STARTUP = 'startup';
    private const STATE_DRAIN = 'drain';
    private const STATE_PROBE_BW = 'probe_bw';
    private const STATE_PROBE_RTT = 'probe_rtt';

    // 增益循环
    private const PACING_GAIN_CYCLE = [1.25, 0.75, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0];

    private string $state = self::STATE_STARTUP;
    private float $bandwidthEstimate = 0.0;      // 当前带宽估算（字节/秒）
    private float $maxBandwidth = 0.0;           // 最大带宽
    private float $minRtt = INF;                 // 最小RTT
    private float $rtProp = INF;                 // RTT传播延迟
    private int $congestionWindow;
    private float $pacingRate = 0.0;             // 发送速率
    
    // 状态追踪
    private int $cycleIndex = 0;                 // 当前增益循环索引
    private float $cycleStart = 0.0;             // 循环开始时间
    private bool $packetConservation = false;   // 包守恒模式
    private float $probeRttStart = 0.0;          // ProbeRTT开始时间
    private bool $probeRttDone = false;          // ProbeRTT完成标志
    private float $priorCwnd = 0.0;              // 进入ProbeRTT前的窗口大小
    
    // 带宽采样
    private array $bandwidthSamples = [];        // 带宽样本
    private int $maxBandwidthFilter = 10;        // 最大带宽过滤器长度
    
    // 统计信息
    private int $totalBytesSent = 0;
    private int $totalBytesAcked = 0;
    private int $ackedPackets = 0;
    private int $lostPackets = 0;

    public function __construct(int $initialCwnd = 10 * 1200)
    {
        $this->congestionWindow = $initialCwnd;
        $this->state = self::STATE_STARTUP;
        $this->cycleStart = microtime(true);
    }

    public function onPacketAcked(int $packetNumber, int $bytes, float $sentTime, float $ackTime): void
    {
        $this->ackedPackets++;
        $this->totalBytesAcked += $bytes;
        
        $rtt = $ackTime - $sentTime;
        $this->updateRtt($rtt);
        $this->updateBandwidth($bytes, $rtt);
        $this->updateState($ackTime);
        $this->updateCongestionWindow();
        $this->updatePacingRate();
    }

    public function onPacketLost(int $packetNumber, int $bytes, float $sentTime, float $lossTime): void
    {
        $this->lostPackets++;
        // BBR主要不基于丢包进行拥塞控制，但记录统计信息
    }

    public function onPacketSent(int $packetNumber, int $bytes, float $sentTime): void
    {
        $this->totalBytesSent += $bytes;
    }

    public function getCongestionWindow(): int
    {
        return $this->congestionWindow;
    }

    public function getSlowStartThreshold(): int
    {
        // BBR没有传统的慢启动阈值概念
        return PHP_INT_MAX;
    }

    public function canSend(int $bytes, int $bytesInFlight): bool
    {
        return $bytesInFlight + $bytes <= $this->congestionWindow;
    }

    public function getSendingRate(): ?float
    {
        return $this->pacingRate > 0.0 ? $this->pacingRate : null;
    }

    public function isInSlowStart(): bool
    {
        return $this->state === self::STATE_STARTUP;
    }

    public function reset(): void
    {
        $this->state = self::STATE_STARTUP;
        $this->bandwidthEstimate = 0.0;
        $this->maxBandwidth = 0.0;
        $this->minRtt = INF;
        $this->rtProp = INF;
        $this->congestionWindow = 10 * 1200;
        $this->pacingRate = 0.0;
        $this->cycleIndex = 0;
        $this->cycleStart = microtime(true);
        $this->packetConservation = false;
        $this->probeRttStart = 0.0;
        $this->probeRttDone = false;
        $this->priorCwnd = 0.0;
        $this->bandwidthSamples = [];
        $this->totalBytesSent = 0;
        $this->totalBytesAcked = 0;
        $this->ackedPackets = 0;
        $this->lostPackets = 0;
    }

    public function getStats(): array
    {
        $totalPackets = $this->ackedPackets + $this->lostPackets;
        $lossRate = $totalPackets > 0 ? $this->lostPackets / $totalPackets : 0.0;
        
        return [
            'algorithm' => self::ALGORITHM_NAME,
            'state' => $this->state,
            'congestion_window' => $this->congestionWindow,
            'bandwidth_estimate' => $this->bandwidthEstimate,
            'max_bandwidth' => $this->maxBandwidth,
            'min_rtt' => $this->minRtt === INF ? 0.0 : $this->minRtt,
            'rt_prop' => $this->rtProp === INF ? 0.0 : $this->rtProp,
            'pacing_rate' => $this->pacingRate,
            'cycle_index' => $this->cycleIndex,
            'packet_conservation' => $this->packetConservation,
            'total_bytes_sent' => $this->totalBytesSent,
            'total_bytes_acked' => $this->totalBytesAcked,
            'acked_packets' => $this->ackedPackets,
            'lost_packets' => $this->lostPackets,
            'loss_rate' => $lossRate,
        ];
    }

    public function getAlgorithmName(): string
    {
        return self::ALGORITHM_NAME;
    }

    /**
     * 更新RTT测量
     */
    private function updateRtt(float $rtt): void
    {
        if ($rtt > 0) {
            $this->minRtt = min($this->minRtt, $rtt);
            
            // 更新RTT传播延迟
            if ($this->rtProp === INF || $rtt < $this->rtProp) {
                $this->rtProp = $rtt;
            }
        }
    }

    /**
     * 更新带宽估算
     */
    private function updateBandwidth(int $bytes, float $rtt): void
    {
        if ($rtt <= 0.0) {
            return;
        }
        
        $bandwidth = $bytes / $rtt;
        $this->bandwidthSamples[] = $bandwidth;
        
        // 保持固定长度的样本窗口
        if (count($this->bandwidthSamples) > $this->maxBandwidthFilter) {
            array_shift($this->bandwidthSamples);
        }
        
        // 计算最大带宽
        if (!empty($this->bandwidthSamples)) {
            $this->maxBandwidth = max($this->bandwidthSamples);
            $this->bandwidthEstimate = $this->maxBandwidth;
        }
    }

    /**
     * 更新BBR状态机
     */
    private function updateState(float $currentTime): void
    {
        switch ($this->state) {
            case self::STATE_STARTUP:
                $this->updateStartup();
                break;
                
            case self::STATE_DRAIN:
                $this->updateDrain();
                break;
                
            case self::STATE_PROBE_BW:
                $this->updateProbeBw($currentTime);
                break;
                
            case self::STATE_PROBE_RTT:
                $this->updateProbeRtt($currentTime);
                break;
        }
    }

    /**
     * 更新启动状态
     */
    private function updateStartup(): void
    {
        // 检查是否应该退出启动阶段
        if ($this->shouldExitStartup()) {
            $this->state = self::STATE_DRAIN;
        }
    }

    /**
     * 检查是否应该退出启动阶段
     */
    private function shouldExitStartup(): bool
    {
        // 简单实现：当带宽增长放缓时退出启动
        if (count($this->bandwidthSamples) < 3) {
            return false;
        }
        
        $recent = array_slice($this->bandwidthSamples, -3);
        $growth = end($recent) / $recent[0];
        
        return $growth < self::BBR_STARTUP_GROWTH_TARGET;
    }

    /**
     * 更新排空状态
     */
    private function updateDrain(): void
    {
        // 当拥塞窗口降到合理水平时，进入ProbeBW
        $targetCwnd = $this->calculateTargetCwnd(1.0);
        if ($this->congestionWindow <= $targetCwnd) {
            $this->state = self::STATE_PROBE_BW;
            $this->cycleStart = microtime(true);
        }
    }

    /**
     * 更新带宽探测状态
     */
    private function updateProbeBw(float $currentTime): void
    {
        // 循环增益控制
        $cycleDuration = 1.0; // 1秒循环
        if ($currentTime - $this->cycleStart >= $cycleDuration) {
            $this->cycleIndex = ($this->cycleIndex + 1) % self::BBR_GAIN_CYCLE_LENGTH;
            $this->cycleStart = $currentTime;
        }
        
        // 检查是否需要进入ProbeRTT
        if ($this->shouldEnterProbeRtt($currentTime)) {
            $this->state = self::STATE_PROBE_RTT;
            $this->priorCwnd = $this->congestionWindow;
            $this->probeRttStart = $currentTime;
            $this->probeRttDone = false;
        }
    }

    /**
     * 更新RTT探测状态
     */
    private function updateProbeRtt(float $currentTime): void
    {
        if ($currentTime - $this->probeRttStart >= self::BBR_PROBE_RTT_DURATION) {
            $this->probeRttDone = true;
        }
        
        if ($this->probeRttDone) {
            $this->state = self::STATE_PROBE_BW;
            $this->congestionWindow = (int) $this->priorCwnd;
            $this->cycleStart = $currentTime;
        }
    }

    /**
     * 检查是否应该进入ProbeRTT
     */
    private function shouldEnterProbeRtt(float $currentTime): bool
    {
        // 简单实现：定期进入ProbeRTT
        return ($currentTime - $this->cycleStart) > 10.0; // 每10秒
    }

    /**
     * 更新拥塞窗口
     */
    private function updateCongestionWindow(): void
    {
        $gain = $this->getCurrentCwndGain();
        $targetCwnd = $this->calculateTargetCwnd($gain);
        
        if ($this->state === self::STATE_PROBE_RTT) {
            $this->congestionWindow = max(
                self::BBR_MIN_PIPE_CWND * 1200,
                (int) ($targetCwnd * 0.5)
            );
        } else {
            $this->congestionWindow = (int) $targetCwnd;
        }
    }

    /**
     * 获取当前拥塞窗口增益
     */
    private function getCurrentCwndGain(): float
    {
        switch ($this->state) {
            case self::STATE_STARTUP:
                return self::BBR_HIGH_GAIN;
                
            case self::STATE_DRAIN:
                return 1.0 / self::BBR_HIGH_GAIN;
                
            case self::STATE_PROBE_BW:
                return self::PACING_GAIN_CYCLE[$this->cycleIndex];
                
            case self::STATE_PROBE_RTT:
                return 1.0;
                
            default:
                return 1.0;
        }
    }

    /**
     * 计算目标拥塞窗口
     */
    private function calculateTargetCwnd(float $gain): float
    {
        if ($this->bandwidthEstimate <= 0 || $this->rtProp === INF) {
            return 10 * 1200; // 默认窗口
        }
        
        $bdp = $this->bandwidthEstimate * $this->rtProp; // 带宽延迟积
        return max(self::BBR_MIN_PIPE_CWND * 1200, $bdp * $gain);
    }

    /**
     * 更新发送速率
     */
    private function updatePacingRate(): void
    {
        if ($this->bandwidthEstimate > 0) {
            $gain = $this->getCurrentPacingGain();
            $this->pacingRate = $this->bandwidthEstimate * $gain;
        }
    }

    /**
     * 获取当前发送速率增益
     */
    private function getCurrentPacingGain(): float
    {
        return $this->getCurrentCwndGain();
    }
}
