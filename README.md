# QUIC 拥塞控制包

这个包实现了 QUIC 协议的拥塞控制算法，包括 NewReno 和 BBR 算法。

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
   - 适用于高带宽网络

### 核心组件

- **CongestionControlInterface**: 拥塞控制算法接口
- **CongestionWindow**: 拥塞窗口管理类
- **CongestionController**: 拥塞控制协调器
- **NewReno**: NewReno 算法实现
- **BBR**: BBR 算法实现

## 安装

```bash
composer require tourze/quic-congestion
```

## 使用示例

### 基本使用

```php
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

## 测试覆盖

包含 55 个单元测试，共 156 个断言，覆盖以下方面：

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

## 运行测试

```bash
./vendor/bin/phpunit packages/quic-congestion/tests
```

## 性能特性

- 支持高频率的数据包处理
- 内存使用优化，自动清理历史记录
- 精确的带宽和 RTT 测量
- 可配置的算法参数
- 详细的性能统计信息

## 依赖项

- `tourze/quic-core`: QUIC 协议核心组件
- `tourze/quic-recovery`: 丢包检测和 RTT 估算

## 许可证

MIT License

## 开发状态

✅ **已完成** - 所有核心功能已实现并通过测试
- NewReno 拥塞控制算法
- BBR 拥塞控制算法  
- 拥塞窗口管理
- 拥塞控制器协调
- 完整的单元测试覆盖
