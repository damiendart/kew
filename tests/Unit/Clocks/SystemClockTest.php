<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit\Clocks;

use DamienDart\Kew\Clocks\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function test_returns_the_current_date_and_time(): void
    {
        $clock = new SystemClock();

        $this->assertNotSame($clock->now(), $clock->now());
    }

    public function test_defaults_to_current_timezone_if_not_specified(): void
    {
        $clock = new SystemClock();
        $now = $clock->now();

        $this->assertSame(
            date_default_timezone_get(),
            $now->getTimezone()->getName(),
        );
    }
}
