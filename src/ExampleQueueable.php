<?php

/*
 * Copyright (C) 2023 Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace Kew;

class ExampleQueueable implements QueueableInterface
{
    public function getName(): string
    {
        return $this::class;
    }

    public function getPayload(): string
    {
        return 'Hey!';
    }
}
