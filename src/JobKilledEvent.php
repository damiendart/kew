<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * @psalm-api
 */
final class JobKilledEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    public function __construct(public readonly string $jobId) {}

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * @psalm-api
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
