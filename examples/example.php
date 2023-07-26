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
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidFactory;

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
    public function __construct(
        protected Queue $queue,
        protected ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
        parent::__construct($queue, $clock);
    }

    public function processJobs(): void
    {
        $this->logger->info('Processing jobs');

        parent::processJobs();

        $this->logger->info('Finished processing jobs');
    }

    /**
     * @throws UnhandledJobException
     */
    protected function handleJob(Job $job): void
    {
        $this->logger->info('Processing job {id}', ['id' => $job->getId()]);

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
        $this->logger->error('Job {id} failed!', ['id' => $job->getId()]);
    }
}

$logger = new Logger('worker');
$queue = new Queue(':memory:', new SystemClock(), new UuidFactory());
$worker = new ExampleWorker($queue, new SystemClock(), $logger);

$logger->pushHandler(new StreamHandler('php://stdout'));
$logger->pushProcessor(new PsrLogMessageProcessor());

$queue->addJob(new ExampleQueueable());
$queue->addJob(
    new ExampleQueueable(),
    new DateTimeImmutable('+2 seconds'),
);

$worker->processJobs();

sleep(2);

$worker->processJobs();
