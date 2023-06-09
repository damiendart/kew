<?php

/*
 * Copyright (C) 2023 Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace Kew;

use Psr\Clock\ClockInterface;

class SystemClock implements ClockInterface
{
    private ?\DateTimeZone $timezone;

    public function __construct(?\DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone;
    }

    /**
     * @throws \Exception
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone);
    }
}
