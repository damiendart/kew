<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew;

use Ramsey\Uuid\UuidInterface;

/**
 * @psalm-api
 */
class RetryingKilledJobException extends \Exception
{
    public function __construct(
        readonly public UuidInterface $jobId,
    ) {
        parent::__construct("Job {$this->jobId->toString()} is killed and cannot be retried.");
    }
}
