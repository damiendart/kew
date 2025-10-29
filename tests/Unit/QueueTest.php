<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\FailingKilledJobException;
use DamienDart\Kew\FrozenClock;
use DamienDart\Kew\Job;
use DamienDart\Kew\JobAlreadyRescheduledException;
use DamienDart\Kew\Queue;
use DamienDart\Kew\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\UuidFactory;
use Random\Randomizer;

/**
 * @internal
 */
#[CoversClass(Queue::class)]
#[UsesClass(FailingKilledJobException::class)]
#[UsesClass(FrozenClock::class)]
#[UsesClass(Job::class)]
#[UsesClass(JobAlreadyRescheduledException::class)]
#[UsesClass(SystemClock::class)]
class QueueTest extends TestCase
{
    public function test_can_schedule_jobs(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());
        $queue = new Queue(':memory:', $clock, new UuidFactory());
        $scheduledJobType = $this->getRandomString(8);

        $queue->createJob(
            $scheduledJobType,
            null,
            $clock->now()->modify('+5 minutes'),
        );

        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+5 minutes'));
        $job = $queue->getNextJob();
        $this->assertEquals($scheduledJobType, $job->type);
    }

    #[TestDox('Can schedule jobs using non-UTC timestamps')]
    public function test_can_schedule_jobs_using_non_utc_timestamps(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2024-07-17T12:00:00+00:00'));
        $queue = new Queue(':memory:', $clock, new UuidFactory());
        $scheduledJobType = $this->getRandomString(8);

        $queue->createJob(
            $scheduledJobType,
            null,
            new \DateTimeImmutable('2024-07-17T14:05:00+02:00'),
        );

        $clock->setTo($clock->now()->modify('+5 minutes'));
        $job = $queue->getNextJob();
        $this->assertEquals($scheduledJobType, $job->type);
    }

    public function test_honours_retry_intervals_when_a_job_is_retried(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());
        $retryableJobType = $this->getRandomString(8);
        $queue = new Queue(':memory:', $clock, new UuidFactory());

        $queue->createJob($retryableJobType, null, null, 60, 120);

        $job = $queue->getNextJob();
        $queue->failJob($job);
        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+1 minute'));
        $job = $queue->getNextJob();
        $this->assertEquals($retryableJobType, $job->type);

        $queue->failJob($job);
        $clock->setTo($clock->now()->modify('+1 minute'));
        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+1 minute'));
        $job = $queue->getNextJob();
        $this->assertEquals($retryableJobType, $job->type);
    }

    public function test_cannot_create_a_job_with_negative_retry_intervals(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Queue(':memory:', new SystemClock(), new UuidFactory()))
            ->createJob($this->getRandomString(8), null, null, -20);
    }

    public function test_cannot_retry_a_job_that_is_already_rescheduled(): void
    {
        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());

        $queue->createJob($this->getRandomString(8), null, null, 2, 5);

        $job = $queue->getNextJob();
        $queue->failJob($job);

        $this->expectException(JobAlreadyRescheduledException::class);
        $queue->failJob($job);
    }

    public function test_cannot_retry_a_killed_job(): void
    {
        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());

        $queue->createJob($this->getRandomString(8), null);

        $job = $queue->getNextJob();
        $queue->failJob($job);

        $this->expectException(FailingKilledJobException::class);
        $queue->failJob($job);
    }

    private function getRandomString(int $length): string
    {
        return bin2hex((new Randomizer())->getBytes($length));
    }
}
