# QUIC Congestion Control Package

[English](README.md) | [中文](README.zh-CN.md)

[![Latest Version](https://img.shields.io/packagist/v/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![Total Downloads](https://img.shields.io/packagist/dt/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![PHP Version Require](https://img.shields.io/packagist/php-v/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![License](https://img.shields.io/packagist/l/tourze/quic-congestion.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-congestion)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg?style=flat-square)](#testing)

A PHP implementation of QUIC protocol congestion control algorithms, supporting NewReno and BBR algorithms for high-performance network communication.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Testing](#testing)
- [Performance Features](#performance-features)
- [API Documentation](#api-documentation)
- [Dependencies](#dependencies)
- [Contributing](#contributing)
- [License](#license)
- [Development Status](#development-status)

## Features

### Supported Congestion Control Algorithms

1. **NewReno Algorithm**
    - Traditional loss-based congestion control
    - Slow start and congestion avoidance mechanisms
    - Fast retransmit and fast recovery
    - Ideal for stable network environments

2. **BBR Algorithm**
    - Google's model-based congestion control
    - Bandwidth estimation and RTT measurement
    - Four states: startup, drain, probe_bw, probe_rtt
    - Optimized for high-bandwidth networks

### Core Components

- **CongestionControlInterface**: Standard interface for congestion control algorithms
- **CongestionWindow**: Congestion window management class
- **CongestionController**: Congestion control coordinator
- **NewReno**: NewReno algorithm implementation
- **BBR**: BBR algorithm implementation

## Installation

### Requirements

- PHP 8.1 or higher
- `tourze/quic-core` package
- `tourze/quic-recovery` package

### Using Composer

```bash
composer require tourze/quic-congestion
```

## Quick Start

### Basic Usage

```php
<?php

use Tourze\QUIC\Congestion\NewReno;
use Tourze\QUIC\Congestion\BBR;
use Tourze\QUIC\Congestion\CongestionController;

// Using NewReno algorithm
$newReno = new NewReno();
$controller = new CongestionController($newReno);

// Sending a packet
$controller->onPacketSent(1, 1200, microtime(true));

// Receiving ACK
$controller->onPacketAcked(1, 1200, $sentTime, microtime(true));

// Check if can send
if ($controller->canSend(1200)) {
    // Can send packet
}

// Switch to BBR algorithm
$bbr = new BBR();
$controller->switchAlgorithm($bbr);
```

### Using BBR Algorithm

```php
<?php

use Tourze\QUIC\Congestion\BBR;

$bbr = new BBR(12000); // Initial congestion window 12KB

// Handle packet acknowledgment
$bbr->onPacketAcked($packetNumber, $bytes, $sentTime, $ackTime);

// Get sending rate
$pacingRate = $bbr->getSendingRate();

// Get detailed statistics
$stats = $bbr->getStats();
echo "Current state: " . $stats['state']; // startup, drain, probe_bw, probe_rtt
echo "Bandwidth estimate: " . $stats['bandwidth_estimate'] . " bytes/s";
echo "Min RTT: " . $stats['min_rtt'] . " seconds";
```

### Congestion Window Management

```php
<?php

use Tourze\QUIC\Congestion\CongestionWindow;

$window = new CongestionWindow();

// Slow start growth
$window->onPacketAcked(1200, true); // In slow start phase

// Congestion avoidance growth
$window->onPacketAcked(1200, false); // In congestion avoidance phase

// Handle packet loss
$window->onPacketLost();

// Get current window size
$size = $window->getWindowSize();
```

## Testing

The package includes comprehensive test coverage with 58 unit tests and 163 assertions covering:

### BBR Algorithm Tests (17 tests)
- Initial state validation
- Packet sending and acknowledgment
- RTT measurement and bandwidth estimation
- State transition mechanisms
- Packet loss handling
- Statistics accuracy

### NewReno Algorithm Tests (13 tests)
- Slow start and congestion avoidance
- Fast retransmit and fast recovery
- Packet loss detection and window adjustment
- Duplicate ACK handling
- Timeout retransmission

### Congestion Window Tests (11 tests)
- Window initialization and growth
- Minimum/maximum window limits
- Window reduction after packet loss
- Statistics collection

### Congestion Controller Tests (14 tests)
- Algorithm switching functionality
- Batch ACK and loss handling
- RTT calculation and statistics
- Edge case handling
- Performance metrics collection

### Running Tests

```bash
./vendor/bin/phpunit packages/quic-congestion/tests
```

## Performance Features

- High-frequency packet processing support
- Memory usage optimization with automatic history cleanup
- Precise bandwidth and RTT measurement
- Configurable algorithm parameters
- Detailed performance statistics

## API Documentation

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

## Dependencies

- `tourze/quic-core`: QUIC protocol core components
- `tourze/quic-recovery`: Packet loss detection and RTT estimation

## Contributing

Please see [CONTRIBUTING.md](../../CONTRIBUTING.md) for details on how to contribute to this project.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Development Status

✅ **Complete** - All core features implemented and tested
- NewReno congestion control algorithm
- BBR congestion control algorithm  
- Congestion window management
- Congestion controller coordination
- Complete unit test coverage
