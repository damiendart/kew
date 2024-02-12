<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * @psalm-api
 */
final class JobKilledEvent extends AbstractEvent
{
    public function __construct(readonly public UuidInterface $jobId) {}
}
