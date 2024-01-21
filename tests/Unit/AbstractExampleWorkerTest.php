<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\AbstractExampleWorker;
use DamienDart\Kew\Clocks\SystemClock;
use DamienDart\Kew\Job;
use DamienDart\Kew\Queue;
use DamienDart\Kew\QueueableInterface;
use DamienDart\Kew\RetryStrategy;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\UuidFactory;

/**
 * @covers \DamienDart\Kew\AbstractExampleWorker
 *
 * @internal
 */
class AbstractExampleWorkerTest extends TestCase
{
    public function test_can_handle_jobs(): void
    {
        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());
        $queueable = new ExampleQueueable();

        $worker = new class ($queue) extends AbstractExampleWorker {
            /** @var Job[] */
            public array $handledJobs;

            protected function handleJob(Job $job): void
            {
                $this->handledJobs[] = $job;
            }

            protected function handleFailedJob(
                Job $job,
                \Throwable $throwable,
            ): void {}
        };

        $queue->createJob($queueable, new RetryStrategy());
        $worker->processJobs();

        $this->assertCount(1, $worker->handledJobs);
        $this->assertEquals($worker->handledJobs[0]->queueable, $queueable);
    }

    public function test_can_handle_failed_jobs(): void
    {
        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());
        $queueable = new ExampleQueueable();

        $worker = new class ($queue) extends AbstractExampleWorker {
            /** @var stdClass{ 'job': Job, 'throwable': \Throwable }[] */
            public array $failedJobs;

            protected function handleJob(Job $job): void
            {
                throw new \Exception('Oops!');
            }

            protected function handleFailedJob(
                Job $job,
                \Throwable $throwable,
            ): void {
                $this->failedJobs[] = (object) [
                    'job' => $job,
                    'throwable' => $throwable,
                ];
            }
        };

        $queue->createJob($queueable, new RetryStrategy(0));
        $worker->processJobs();

        $this->assertCount(1, $worker->failedJobs);
        $this->assertEquals($worker->failedJobs[0]->job->queueable, $queueable);
        $this->assertEquals(
            $worker->failedJobs[0]->throwable,
            new \Exception('Oops!'),
        );
    }
}

class ExampleQueueable implements QueueableInterface
{
    public function getPayload(): string
    {
        return 'Hey!';
    }
}
