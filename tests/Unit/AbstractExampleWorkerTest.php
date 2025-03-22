<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\AbstractExampleWorker;
use DamienDart\Kew\Job;
use DamienDart\Kew\Queue;
use DamienDart\Kew\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\UuidFactory;

/**
 * @internal
 */
#[CoversClass(AbstractExampleWorker::class)]
#[UsesClass(Job::class)]
#[UsesClass(Queue::class)]
#[UsesClass(SystemClock::class)]
class AbstractExampleWorkerTest extends TestCase
{
    public function test_can_handle_jobs(): void
    {
        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());

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

        $queue->createJob('test', ['foo' => 'bar']);
        $worker->processJobs();

        $this->assertCount(1, $worker->handledJobs);
        $this->assertEquals('test', $worker->handledJobs[0]->type);
        $this->assertEquals(['foo' => 'bar'], $worker->handledJobs[0]->arguments);
    }

    public function test_can_handle_failed_jobs(): void
    {
        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());

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

        $queue->createJob('test', ['foo' => 'bar']);
        $worker->processJobs();

        $this->assertCount(1, $worker->failedJobs);
        $this->assertEquals('test', $worker->failedJobs[0]->job->type);
        $this->assertEquals(['foo' => 'bar'], $worker->failedJobs[0]->job->arguments);
        $this->assertEquals(new \Exception('Oops!'), $worker->failedJobs[0]->throwable);
    }
}
