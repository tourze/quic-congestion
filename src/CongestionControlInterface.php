<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion;

/**
 * 拥塞控制接口
 * 
 * 定义QUIC拥塞控制算法的标准接口
 * 支持NewReno、BBR等不同的拥塞控制算法
 */
interface CongestionControlInterface
{
    /**
     * 当数据包被确认时调用
     *
     * @param int $packetNumber 数据包号
     * @param int $bytes 确认的字节数
     * @param float $sentTime 发送时间
     * @param float $ackTime 确认时间
     */
    public function onPacketAcked(int $packetNumber, int $bytes, float $sentTime, float $ackTime): void;

    /**
     * 当检测到丢包时调用
     *
     * @param int $packetNumber 丢失的数据包号
     * @param int $bytes 丢失的字节数
     * @param float $sentTime 发送时间
     * @param float $lossTime 检测到丢失的时间
     */
    public function onPacketLost(int $packetNumber, int $bytes, float $sentTime, float $lossTime): void;

    /**
     * 当发送数据包时调用
     *
     * @param int $packetNumber 数据包号
     * @param int $bytes 发送的字节数
     * @param float $sentTime 发送时间
     */
    public function onPacketSent(int $packetNumber, int $bytes, float $sentTime): void;

    /**
     * 获取当前拥塞窗口大小（字节）
     */
    public function getCongestionWindow(): int;

    /**
     * 获取慢启动阈值（字节）
     */
    public function getSlowStartThreshold(): int;

    /**
     * 检查是否可以发送数据包
     *
     * @param int $bytes 要发送的字节数
     * @param int $bytesInFlight 当前在传输中的字节数
     */
    public function canSend(int $bytes, int $bytesInFlight): bool;

    /**
     * 获取发送速率（字节/秒）
     * 对于基于速率的算法（如BBR）
     */
    public function getSendingRate(): ?float;

    /**
     * 检查是否处于慢启动阶段
     */
    public function isInSlowStart(): bool;

    /**
     * 重置拥塞控制状态
     */
    public function reset(): void;

    /**
     * 获取拥塞控制统计信息
     */
    public function getStats(): array;

    /**
     * 获取算法名称
     */
    public function getAlgorithmName(): string;
} 