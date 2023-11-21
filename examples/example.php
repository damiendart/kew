<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

use DamienDart\Kew\AbstractExampleWorker;
use DamienDart\Kew\Job;
use DamienDart\Kew\Queue;
use DamienDart\Kew\QueueableInterface;
use DamienDart\Kew\SystemClock;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidFactory;

require \dirname(__DIR__) . '/vendor/autoload.php';

class UnhandledJobException extends \Exception {}

class ExampleQueueable implements QueueableInterface
{
    public function getPayload(): string
    {
        return 'Hey!';
    }
}

class ExampleWorker extends AbstractExampleWorker
{
    public function __construct(
        protected Queue $queue,
        private LoggerInterface $logger,
    ) {
        parent::__construct($queue);
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
        $this->logger->info('Processing job {id}', ['id' => $job->id]);

        $queueable = $job->queueable;

        echo match ($queueable::class) {
            ExampleQueueable::class => $queueable->getPayload() . PHP_EOL,
            default => throw new UnhandledJobException((string) $job->id),
        };
    }

    /**
     * @throws Throwable
     */
    protected function handleFailedJob(Job $job, Throwable $throwable): void
    {
        $this->logger->error('Job {id} failed!', ['id' => $job->id]);
    }
}

$logger = new Logger('worker');
$queue = new Queue(':memory:', new SystemClock(), new UuidFactory());
$worker = new ExampleWorker($queue, $logger);

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
