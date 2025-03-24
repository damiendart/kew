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
abstract class AbstractExampleWorker
{
    abstract protected function handleJob(Job $job): void;

    abstract protected function handleFailedJob(
        Job $job,
        \Throwable $throwable,
    ): void;

    public function __construct(protected Queue $queue) {}

    public function processJobs(): void
    {
        while ($this->canProcessJobs()) {
            $job = $this->queue->getNextJob();

            if (null === $job) {
                break;
            }

            try {
                $this->handleJob($job);
                $this->queue->acknowledgeJob($job->id);
            } catch (\Throwable $e) {
                $this->queue->failJob($job->id);
                $this->handleFailedJob($job, $e);
            }
        }
    }

    protected function canProcessJobs(): bool
    {
        return true;
    }
}
