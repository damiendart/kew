<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Clocks;

use Psr\Clock\ClockInterface;

/** @psalm-api */
class FrozenClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $frozenAt,
    ) {}

    public function now(): \DateTimeImmutable
    {
        return clone $this->frozenAt;
    }

    public function setTo(\DateTimeImmutable $dateTime): self
    {
        $this->frozenAt = $dateTime;

        return $this;
    }
}
