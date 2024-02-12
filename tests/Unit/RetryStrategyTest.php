<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\RetryStrategy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DamienDart\Kew\RetryStrategy
 *
 * @internal
 */
class RetryStrategyTest extends TestCase
{
    public function test_cannot_be_instantiated_with_a_negative_number_of_retries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetryStrategy(-1);
    }

    public function test_cannot_be_instantiated_with_negative_retry_intervals(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetryStrategy(2, -10);
    }

    public function test_defaults_to_an_immediate_retry_interval_if_none_have_been_provided(): void
    {
        $retryStrategy = new RetryStrategy(3);

        $this->assertEquals(0, $retryStrategy->getRetryInterval(1));
    }

    public function test_returns_the_last_retry_interval_if_more_retries_have_been_made_than_available_retry_intervals(): void
    {
        $retryStrategy = new RetryStrategy(4, 30, 20);

        $this->assertEquals(20, $retryStrategy->getRetryInterval(3));
    }
}
