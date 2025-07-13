<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew;

/**
 * @psalm-api
 */
class JobAlreadyRescheduledException extends \Exception
{
    public function __construct(
        public readonly string $jobId,
    ) {
        parent::__construct("Job {$this->jobId} has already been rescheduled.");
    }
}
