# QUIC 拥塞控制包

[English](README.md) | [中文](README.zh-CN.md)

[![Latest Version](https://img.shields.io/packagist/v/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![Total Downloads](https://img.shields.io/packagist/dt/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![PHP Version Require](https://img.shields.io/packagist/php-v/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![License](https://img.shields.io/packagist/l/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg?style=flat-square)](#测试)

QUIC 协议拥塞控制算法的 PHP 实现，支持 NewReno 和 BBR 算法，专为高性能网络通信而设计。

## 目录

- [功能特性](#功能特性)
- [安装](#安装)
- [快速开始](#快速开始)
- [测试](#测试)
- [性能特性](#性能特性)
- [API 文档](#api-文档)
- [依赖项](#依赖项)
- [贡献](#贡献)
- [许可证](#许可证)
- [开发状态](#开发状态)

## 功能特性

### 支持的拥塞控制算法

1. **NewReno 算法**
    - 基于丢包的传统拥塞控制
    - 慢启动和拥塞避免机制
    - 快速重传和快速恢复
    - 适用于稳定网络环境

2. **BBR 算法**
    - Google 开发的基于模型的拥塞控制
    - 带宽估算和 RTT 测量
    - 四个状态：startup、drain、probe_bw、probe_rtt
    - 针对高带宽网络优化

### 核心组件

- **CongestionControlInterface**: 拥塞控制算法标准接口
- **CongestionWindow**: 拥塞窗口管理类
- **CongestionController**: 拥塞控制协调器
- **NewReno**: NewReno 算法实现
- **BBR**: BBR 算法实现

## 安装

### 系统要求

- PHP 8.1 或更高版本
- `tourze/quic-core` 包
- `tourze/quic-recovery` 包

### 使用 Composer

```bash
composer require tourze/quic-congestion
```

## 快速开始

### 基本使用

```php
<?php

use Tourze\QUIC\Congestion\NewReno;
use Tourze\QUIC\Congestion\BBR;
use Tourze\QUIC\Congestion\CongestionController;

// 使用 NewReno 算法
$newReno = new NewReno();
$controller = new CongestionController($newReno);

// 发送数据包
$controller->onPacketSent(1, 1200, microtime(true));

// 收到 ACK
$controller->onPacketAcked(1, 1200, $sentTime, microtime(true));

// 检查是否可以发送
if ($controller->canSend(1200)) {
    // 可以发送数据包
}

// 切换到 BBR 算法
$bbr = new BBR();
$controller->switchAlgorithm($bbr);
```

### BBR 算法使用

```php
<?php

use Tourze\QUIC\Congestion\BBR;

$bbr = new BBR(12000); // 初始拥塞窗口 12KB

// 处理数据包确认
$bbr->onPacketAcked($packetNumber, $bytes, $sentTime, $ackTime);

// 获取发送速率
$pacingRate = $bbr->getSendingRate();

// 获取详细统计信息
$stats = $bbr->getStats();
echo "当前状态: " . $stats['state']; // startup, drain, probe_bw, probe_rtt
echo "带宽估算: " . $stats['bandwidth_estimate'] . " bytes/s";
echo "最小 RTT: " . $stats['min_rtt'] . " 秒";
```

### 拥塞窗口管理

```php
<?php

use Tourze\QUIC\Congestion\CongestionWindow;

$window = new CongestionWindow();

// 慢启动增长
$window->onPacketAcked(1200, true); // 在慢启动阶段

// 拥塞避免增长
$window->onPacketAcked(1200, false); // 在拥塞避免阶段

// 处理丢包
$window->onPacketLost();

// 获取当前窗口大小
$size = $window->getWindowSize();
```

## 测试

包含 58 个单元测试，共 163 个断言，覆盖以下方面：

### BBR 算法测试 (17 个测试)
- 初始状态验证
- 数据包发送和确认
- RTT 测量和带宽估算
- 状态转换机制
- 丢包处理
- 统计信息准确性

### NewReno 算法测试 (13 个测试)
- 慢启动和拥塞避免
- 快速重传和快速恢复
- 丢包检测和窗口调整
- 重复 ACK 处理
- 超时重传

### 拥塞窗口测试 (11 个测试)
- 窗口初始化和增长
- 最小/最大窗口限制
- 丢包后的窗口缩减
- 统计信息收集

### 拥塞控制器测试 (14 个测试)
- 算法切换功能
- 批量 ACK 和丢包处理
- RTT 计算和统计
- 边界条件处理
- 性能指标收集

### 运行测试

```bash
./vendor/bin/phpunit packages/quic-congestion/tests
```

## 性能特性

- 支持高频率的数据包处理
- 内存使用优化，自动清理历史记录
- 精确的带宽和 RTT 测量
- 可配置的算法参数
- 详细的性能统计信息

## API 文档

### CongestionControlInterface

```php
interface CongestionControlInterface
{
    public function onPacketAcked(int $packetNumber, int $bytes, float $sentTime, float $ackTime): void;
    public function onPacketLost(int $packetNumber, int $bytes, float $sentTime, float $lossTime): void;
    public function onPacketSent(int $packetNumber, int $bytes, float $sentTime): void;
    public function getCongestionWindow(): int;
    public function getSlowStartThreshold(): int;
    public function canSend(int $bytes, int $bytesInFlight): bool;
    public function getSendingRate(): ?float;
    public function isInSlowStart(): bool;
    public function reset(): void;
    public function getStats(): array;
    public function getAlgorithmName(): string;
}
```

## 依赖项

- `tourze/quic-core`: QUIC 协议核心组件
- `tourze/quic-recovery`: 丢包检测和 RTT 估算

## 贡献

有关如何为此项目做出贡献的详细信息，请参阅 [CONTRIBUTING.md](../../CONTRIBUTING.md)。

## 许可证

MIT 许可证 (MIT)。更多信息请参阅 [许可证文件](LICENSE)。

## 开发状态

✅ **已完成** - 所有核心功能已实现并通过测试
- NewReno 拥塞控制算法
- BBR 拥塞控制算法  
- 拥塞窗口管理
- 拥塞控制器协调
- 完整的单元测试覆盖