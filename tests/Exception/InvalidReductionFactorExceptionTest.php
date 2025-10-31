<?php

declare(strict_types=1);

namespace Tourze\QUIC\Congestion\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\QUIC\Congestion\Exception\InvalidReductionFactorException;

/**
 * @internal
 */
#[CoversClass(InvalidReductionFactorException::class)]
final class InvalidReductionFactorExceptionTest extends AbstractExceptionTestCase
{
    public function testOutOfRange(): void
    {
        $exception = InvalidReductionFactorException::outOfRange(1.5);

        $this->assertInstanceOf(InvalidReductionFactorException::class, $exception);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('减少因子必须在0和1之间', $exception->getMessage());
        $this->assertStringContainsString('1.5', $exception->getMessage());
    }

    public function testOutOfRangeWithZero(): void
    {
        $exception = InvalidReductionFactorException::outOfRange(0.0);

        $this->assertStringContainsString('0.0', $exception->getMessage());
    }

    public function testOutOfRangeWithNegative(): void
    {
        $exception = InvalidReductionFactorException::outOfRange(-0.5);

        $this->assertStringContainsString('-0.5', $exception->getMessage());
    }
}
