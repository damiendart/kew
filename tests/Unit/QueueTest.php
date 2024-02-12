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
use DamienDart\Kew\Queue;
use DamienDart\Kew\RetryStrategy;
use DamienDart\Kew\Tests\ExampleQueueable;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\UuidFactory;

/**
 * @covers \DamienDart\Kew\Queue
 *
 * @internal
 */
class QueueTest extends TestCase
{
    public function test_provides_a_notification_when_a_job_can_no_longer_be_retried(): void
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

        $jobId = $queue->createJob(new ExampleQueueable());
        $job = $queue->getNextJob();

        $queue->markJobAsUnreserved($job);

        $latestEvent = array_pop($eventDispatcher->events);

        $this->assertInstanceOf(JobKilledEvent::class, $latestEvent);
        $this->assertEquals($latestEvent->jobId->toString(), $jobId->toString());
    }

    public function test_can_schedule_jobs(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());
        $queue = new Queue(':memory:', $clock, new UuidFactory());

        $jobId = $queue->createJob(
            new ExampleQueueable(),
            null,
            $clock->now()->modify('+5 minutes'),
        );

        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+5 minutes'));
        $job = $queue->getNextJob();
        $this->assertEquals($jobId->toString(), $job->id->toString());
    }

    public function test_honours_retry_intervals(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());
        $queue = new Queue(':memory:', $clock, new UuidFactory());

        $jobId = $queue->createJob(
            new ExampleQueueable(),
            new RetryStrategy(2, 60, 120),
        );

        $job = $queue->getNextJob();
        $queue->markJobAsUnreserved($job);
        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+1 minute'));
        $job = $queue->getNextJob();
        $this->assertEquals($jobId->toString(), $job->id->toString());

        $queue->markJobAsUnreserved($job);
        $clock->setTo($clock->now()->modify('+1 minute'));
        $this->assertNull($queue->getNextJob());

        $clock->setTo($clock->now()->modify('+1 minute'));
        $job = $queue->getNextJob();
        $this->assertEquals($jobId->toString(), $job->id->toString());
    }
}
