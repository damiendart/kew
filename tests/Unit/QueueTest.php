<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew\Tests\Unit;

use DamienDart\Kew\Clocks\FrozenClock;
use DamienDart\Kew\Events\AbstractEvent;
use DamienDart\Kew\Events\ExhaustedJobEvent;
use DamienDart\Kew\Queue;
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
    public function test_provides_a_notification_when_a_job_has_exhausted_its_attempts(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable());

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
            $clock,
            new UuidFactory(),
            $eventDispatcher,
        );

        $jobId = $queue->addJob(new ExampleQueueable());

        for ($i = 0; $i < 3; ++$i) {
            $job = $queue->getNextJob();
            $queue->markJobAsUnreserved($job);
            $clock->setTo($clock->now()->modify('+1 minute'));
        }

        $latestEvent = array_pop($eventDispatcher->events);

        $this->assertInstanceOf(ExhaustedJobEvent::class, $latestEvent);
        $this->assertEquals(
            $latestEvent->job->id->toString(),
            $jobId->toString(),
        );
    }
}
