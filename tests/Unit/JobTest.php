<?php

/*
 * Copyright (c) 2023 Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace Kew\Tests\Unit;

use Kew\ExampleQueueable;
use Kew\Job;
use Kew\QueueableInterface;
use PHPUnit\Framework\TestCase;

class JobTest extends TestCase
{
    public function testAddingAttempts(): void
    {
        $job = new Job(
            1,
            new class implements QueueableInterface {
                public function getPayload(): string
                {
                    return 'Hey!';
                }
            },
            0,
        );

        $this->assertEquals(0, $job->getAttempts());

        $job->addAttempt();
        $this->assertEquals(1, $job->getAttempts());

        $job->addAttempt();
        $this->assertEquals(2, $job->getAttempts());
    }

    public function testSettingAJobAsFailed(): void
    {
        $job = new Job(
            1,
            new class implements QueueableInterface {
                public function getPayload(): string
                {
                    return 'Hey!';
                }
            },
            0,
        );

        $this->assertFalse($job->hasFailed());

        $job->failJob();
        $this->assertTrue($job->hasFailed());
    }
}
