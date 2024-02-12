<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew;

use DamienDart\Kew\Events\JobKilledEvent;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidInterface;

class Queue
{
    private \PDO $sqliteDatabase;

    /**
     * @psalm-api
     */
    public function __construct(
        string $databaseFilepath,
        private readonly ClockInterface $clock,
        private readonly UuidFactory $uuidFactory,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->initialiseSqliteDatabase($databaseFilepath);
    }

    /**
     * @psalm-api
     *
     * @psalm-suppress TypeDoesNotContainType
     *
     * @param non-empty-string $type
     */
    public function createJob(
        string $type,
        mixed $arguments,
        ?RetryStrategy $retryStrategy = null,
        ?\DateTimeInterface $availableAt = null,
    ): UuidInterface {
        // @phpstan-ignore-next-line identical.alwaysFalse
        if ('' === $type) {
            throw new \InvalidArgumentException('A job name cannot be an empty string.');
        }

        $createdAt = $this->clock->now();
        $uuid = $this->uuidFactory->uuid4();

        $statement = $this->sqliteDatabase->prepare(
            'INSERT INTO jobs (id, created_at, available_at, retry_strategy, payload)
                VALUES (:id, :created_at, :available_at, :retry_strategy, :payload)',
        );

        $statement->bindValue(':available_at', ($availableAt ?? $createdAt)->getTimestamp());
        $statement->bindValue(':created_at', $createdAt->getTimestamp());
        $statement->bindValue(':id', $uuid);
        $statement->bindValue(':payload', json_encode(['arguments' => $arguments, 'type' => $type]));
        $statement->bindValue(':retry_strategy', (null === $retryStrategy) ? null : json_encode($retryStrategy));

        $statement->execute();

        return $uuid;
    }

    public function getNextJob(): ?Job
    {
        $statement = $this->sqliteDatabase->prepare(
            'SELECT id, payload FROM jobs
                WHERE reserved_at IS NULL
                    AND available_at IS NOT NULL
                    AND available_at <= :available_at
                LIMIT 1',
        );

        $statement->bindValue(':available_at', $this->clock->now()->getTimestamp());
        $statement->execute();

        /** @var array{ 'id': string, 'payload': string }[] $results */
        $results = $statement->fetchAll();

        if (0 === \count($results)) {
            return null;
        }

        /** @var array{ 'arguments': mixed, 'type': non-empty-string } $data */
        $data = json_decode($results[0]['payload'], true);

        $job = new Job(
            $this->uuidFactory->fromString($results[0]['id']),
            $data['type'],
            $data['arguments'],
        );

        $this->markJobAsReserved($job);

        return $job;
    }

    public function markJobAsCompleted(Job $job): void
    {
        $this->deleteJob($job);
    }

    public function markJobAsKilled(Job $job): void
    {
        $statement = $this->sqliteDatabase->prepare(
            'UPDATE jobs
                SET available_at = NULL, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindValue(':id', $job->id);
        $statement->execute();
    }

    public function markJobAsReserved(Job $job): void
    {
        $statement = $this->sqliteDatabase->prepare(
            'UPDATE jobs
                SET attempts = attempts + 1, reserved_at = :reserved_at
                WHERE id = :id',
        );

        $statement->bindValue(':id', $job->id);
        $statement->bindValue(':reserved_at', $this->clock->now()->getTimestamp());
        $statement->execute();
    }

    public function markJobAsUnreserved(Job $job): void
    {
        $numberOfAttempts = $this->getJobAttempts($job);

        $retryInterval = $this
            ->getRetryStrategyForJob($job->id)
            ?->getRetryInterval($numberOfAttempts - 1);

        if (null === $retryInterval) {
            $this->markJobAsKilled($job);
            $this->eventDispatcher?->dispatch(new JobKilledEvent($job->id));

            return;
        }

        $statement = $this->sqliteDatabase->prepare(
            'UPDATE jobs
                SET available_at = :available_at, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindValue(
            ':available_at',
            $this->clock
                ->now()
                ->add(new \DateInterval("PT{$retryInterval}S"))
                ->getTimestamp(),
        );
        $statement->bindValue(':id', $job->id);
        $statement->execute();
    }

    private function deleteJob(Job $job): void
    {
        $statement = $this->sqliteDatabase->prepare('DELETE FROM jobs WHERE id = :id');

        $statement->bindValue(':id', $job->id);
        $statement->execute();
    }

    /** @return positive-int */
    private function getJobAttempts(Job $job): int
    {
        $statement = $this->sqliteDatabase->prepare(
            'SELECT attempts FROM jobs WHERE id = :id',
        );

        $statement->bindValue(':id', $job->id);
        $statement->execute();

        /** @var array{ attempts: positive-int }[] $results */
        $results = $statement->fetchAll();

        if (0 === \count($results)) {
            throw new \RuntimeException("Cannot find job {$job->id}");
        }

        return $results[0]['attempts'];
    }

    private function getRetryStrategyForJob(UuidInterface $id): ?RetryStrategy
    {
        $statement = $this->sqliteDatabase->prepare(
            'SELECT retry_strategy FROM jobs WHERE id = :id',
        );

        $statement->bindValue(':id', $id);
        $statement->execute();

        /** @var array{ 'retry_strategy': ?string }[] $results */
        $results = $statement->fetchAll();

        if (0 === \count($results)) {
            throw new \RuntimeException("Cannot find job {$id}");
        }

        if (null === $results[0]['retry_strategy']) {
            return null;
        }

        /** @var array{ 'maxRetries': non-negative-int, 'retryIntervals': non-negative-int[] } $retryStrategy */
        $retryStrategy = json_decode($results[0]['retry_strategy'], true);

        return new RetryStrategy(
            $retryStrategy['maxRetries'],
            ...$retryStrategy['retryIntervals'],
        );
    }

    private function initialiseSqliteDatabase(string $filepath): void
    {
        $this->sqliteDatabase = new \PDO("sqlite:{$filepath}");

        $this->sqliteDatabase->exec('PRAGMA journal_mode=WAL');
        $this->sqliteDatabase->exec(
            'CREATE TABLE IF NOT EXISTS jobs(
                id TEXT PRIMARY KEY UNIQUE,
                created_at TEXT,
                available_at TEXT,
                reserved_at TEXT DEFAULT NULL,
                attempts INT DEFAULT 0,
                retry_strategy TEXT DEFAULT NULL,
                payload TEXT
            )',
        );
    }
}
