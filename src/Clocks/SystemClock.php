<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Clocks;

use Psr\Clock\ClockInterface;

/**
 * @psalm-api
 */
class SystemClock implements ClockInterface
{
    private ?\DateTimeZone $timezone;

    public function __construct(?\DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone;
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone);
    }
}
