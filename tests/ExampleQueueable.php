<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests;

use DamienDart\Kew\QueueableInterface;

final readonly class ExampleQueueable implements QueueableInterface
{
    public function __construct(public mixed $payload = null) {}
}
