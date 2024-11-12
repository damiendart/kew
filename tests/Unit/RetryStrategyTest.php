<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\RetryStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RetryStrategy::class)]
class RetryStrategyTest extends TestCase
{
    public function test_can_be_instantiated_with_no_retry_intervals(): void
    {
        $this->assertInstanceOf(RetryStrategy::class, new RetryStrategy());
    }

    public function test_cannot_be_instantiated_with_negative_retry_intervals(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetryStrategy(-10);
    }

    public function test_returns_the_correct_retry_interval(): void
    {
        $retryStrategy = new RetryStrategy(30, 20, 10);

        $this->assertEquals(30, $retryStrategy->getRetryInterval(1));
        $this->assertEquals(20, $retryStrategy->getRetryInterval(2));
        $this->assertEquals(10, $retryStrategy->getRetryInterval(3));
        $this->assertEquals(null, $retryStrategy->getRetryInterval(4));
    }
}
