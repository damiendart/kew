<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Events;

use DamienDart\Kew\Job;

/**
 * @psalm-api
 */
class ExhaustedJobEvent extends AbstractEvent
{
    public function __construct(
        public Job $job,
        public int $attempts,
    ) {}
}
