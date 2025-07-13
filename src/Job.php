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
readonly class Job
{
    public function __construct(
        public string $id,
        public string $type,
        public mixed $arguments,
    ) {}
}
