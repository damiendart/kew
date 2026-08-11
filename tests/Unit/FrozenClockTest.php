<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FrozenClock::class)]
final class FrozenClockTest extends TestCase
{
    public function test_returns_the_initially_provided_date_and_time(): void
    {
        $now = new \DateTimeImmutable();
        $clock = new FrozenClock($now);

        usleep(1);

        $this->assertEquals($now, $clock->now());
        $this->assertNotSame($now, $clock->now());
    }

    public function test_can_be_constructed_without_providing_a_date_and_time(): void
    {
        $clock = new FrozenClock();
        $now = $clock->now();

        usleep(1);

        $this->assertEquals($now, $clock->now());
        $this->assertNotSame($now, $clock->now());
    }

    public function test_can_be_set_to_return_a_different_date_and_time(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());

        usleep(1);

        $now = new \DateTimeImmutable();

        $this->assertNotEquals($now, $clock->now());

        $clock->setTo($now);

        $this->assertEquals($now, $clock->now());
        $this->assertNotSame($now, $clock->now());
    }
}
