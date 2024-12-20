<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\Clocks\FrozenClock;
use DamienDart\Kew\Clocks\SystemClock;
use DamienDart\Kew\Events\AbstractEvent;
use DamienDart\Kew\Events\JobKilledEvent;
use DamienDart\Kew\Exceptions\JobAlreadyRescheduledException;
use DamienDart\Kew\Exceptions\RetryingKilledJobException;
use DamienDart\Kew\Job;
use DamienDart\Kew\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\UuidFactory;

/**
 * @internal
 */
#[CoversClass(Queue::class)]
#[UsesClass(FrozenClock::class)]
#[UsesClass(Job::class)]
#[UsesClass(JobAlreadyRescheduledException::class)]
#[UsesClass(JobKilledEvent::class)]
#[UsesClass(RetryingKilledJobException::class)]
#[UsesClass(SystemClock::class)]
class QueueTest extends TestCase
{
    public function test_can_schedule_jobs(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());
        $queue = new Queue(':memory:', $clock, new UuidFactory());

        $jobId = $queue->createJob(
            'test',
            null,
            $clock->now()->modify('+5 minutes'),
        );

        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+5 minutes'));
        $job = $queue->getNextJob();
        $this->assertEquals($jobId->toString(), $job->id->toString());
    }

    #[TestDox('Can schedule jobs using non-UTC timestamps')]
    public function test_can_schedule_jobs_using_non_utc_timestamps(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2024-07-17T12:00:00+00:00'));
        $queue = new Queue(':memory:', $clock, new UuidFactory());

        $jobId = $queue->createJob(
            'test',
            null,
            new \DateTimeImmutable('2024-07-17T14:05:00+02:00'),
        );

        $clock->setTo($clock->now()->modify('+5 minutes'));
        $job = $queue->getNextJob();

        $this->assertInstanceOf(Job::class, $job);
        $this->assertEquals($jobId->toString(), $job->id->toString());
    }

    public function test_honours_retry_intervals_when_a_job_is_retried(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());
        $queue = new Queue(':memory:', $clock, new UuidFactory());

        $jobId = $queue->createJob(
            'test',
            null,
            null,
            60,
            120,
        );

        $job = $queue->getNextJob();
        $queue->retryJob($job->id);
        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+1 minute'));
        $job = $queue->getNextJob();
        $this->assertInstanceOf(Job::class, $job);
        $this->assertEquals($jobId->toString(), $job->id->toString());

        $queue->retryJob($job->id);
        $clock->setTo($clock->now()->modify('+1 minute'));
        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+1 minute'));
        $job = $queue->getNextJob();
        $this->assertInstanceOf(Job::class, $job);
        $this->assertEquals($jobId->toString(), $job->id->toString());
    }

    public function test_cannot_create_a_job_with_negative_retry_intervals(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Queue(':memory:', new SystemClock(), new UuidFactory()))
            ->createJob('test', null, null, -20);
    }

    public function test_cannot_retry_a_job_that_is_already_rescheduled(): void
    {
        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());

        $jobId = $queue->createJob('test', null, null, 2, 5);

        $this->expectException(JobAlreadyRescheduledException::class);

        $queue->retryJob($jobId);
        $queue->retryJob($jobId);
    }

    public function test_cannot_retry_a_killed_job(): void
    {
        $this->expectException(RetryingKilledJobException::class);

        $queue = new Queue(':memory:', new SystemClock(), new UuidFactory());

        $queue->createJob('test', null);

        $job = $queue->getNextJob();

        $queue->retryJob($job->id);
        $queue->retryJob($job->id);
    }

    public function test_provides_a_notification_when_a_job_has_exhausted_its_retries(): void
    {
        $eventDispatcher = new class () implements EventDispatcherInterface {
            /** @var AbstractEvent[] */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };

        $queue = new Queue(
            ':memory:',
            new SystemClock(),
            new UuidFactory(),
            $eventDispatcher,
        );

        $jobId = $queue->createJob('test', null);
        $job = $queue->getNextJob();

        $queue->retryJob($job->id);

        $latestEvent = array_pop($eventDispatcher->events);

        $this->assertInstanceOf(JobKilledEvent::class, $latestEvent);
        $this->assertEquals($latestEvent->jobId->toString(), $jobId->toString());
    }
}
