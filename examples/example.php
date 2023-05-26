<?php

/*
 * Copyright (C) 2023 Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

use Kew\AbstractWorker;
use Kew\Job;
use Kew\Queue;
use Kew\QueueableInterface;
use Kew\SystemClock;

require \dirname(__DIR__) . '/vendor/autoload.php';

class UnhandledJobException extends \Exception
{
}

class ExampleQueueable implements QueueableInterface
{
    public function getPayload(): string
    {
        return 'Hey!';
    }
}

class ExampleWorker extends AbstractWorker
{
    /**
     * @throws UnhandledJobException
     */
    protected function handleJob(Job $job): void
    {
        $queueable = $job->getQueueable();

        echo match ($queueable::class) {
            ExampleQueueable::class => $queueable->getPayload() . PHP_EOL,
            default => throw new UnhandledJobException((string) $job->getId()),
        };
    }

    /**
     * @throws Throwable
     */
    protected function handleFailedJob(Job $job, Throwable $throwable): void
    {
        throw $throwable;
    }
}

$queue = new Queue(':memory:', new SystemClock());
$worker = new ExampleWorker($queue, new SystemClock());

$queue->addJob(new ExampleQueueable());
$queue->addJob(
    new ExampleQueueable(),
    new DateTimeImmutable('+2 seconds'),
);

$worker->processJobs();

sleep(2);

$worker->processJobs();
