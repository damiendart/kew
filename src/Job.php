<?php

/*
 * Copyright (C) 2023 Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace Kew;

class Job
{
    private bool $hasFailed = false;

    public function __construct(
        private int $id,
        private QueueableInterface $queueable,
        private int $attempts,
    ) {
    }

    public function addAttempt(): int
    {
        ++$this->attempts;

        return $this->attempts;
    }

    public function failJob(): self
    {
        $this->hasFailed = true;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getQueueable(): QueueableInterface
    {
        return $this->queueable;
    }

    public function hasFailed(): bool
    {
        return $this->hasFailed;
    }
}
