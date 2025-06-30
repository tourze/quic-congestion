<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion\Exception;

class InvalidReductionFactorException extends \InvalidArgumentException
{
    public static function outOfRange(float $value): self
    {
        return new self(sprintf('减少因子必须在0和1之间，给定值：%f', $value));
    }
}