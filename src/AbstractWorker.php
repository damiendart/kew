<?php

/*
 * Copyright (C) 2023 Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace Kew;

use Psr\Clock\ClockInterface;

abstract class AbstractWorker
{
    abstract protected function handleJob(Job $job): void;

    abstract protected function handleFailedJob(Job $job, \Throwable $throwable): void;

    public function __construct(
        private Queue $queue,
        private ClockInterface $clock,
    ) {
    }

    public function processJobs(): void
    {
        while ($this->canProcessJobs()) {
            $job = $this->queue->getNextJob($this->clock->now());

            if (null === $job) {
                break;
            }

            try {
                $this->queue->markJobAsReserved($job);
                $this->handleJob($job);
                $this->queue->markJobAsCompleted($job);
            } catch (\Throwable $e) {
                $this->queue->markJobAsUnreserved($job);

                if ($job->hasFailed()) {
                    $this->handleFailedJob($job, $e);
                }
            }
        }
    }

    protected function canProcessJobs(): bool
    {
        return true;
    }
}
