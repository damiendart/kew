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
     */
    public function createJob(
        QueueableInterface $queueable,
        ?RetryStrategy $retryStrategy = null,
        ?\DateTimeInterface $availableAt = null,
    ): UuidInterface {
        $createdAt = $this->clock->now();
        $data = serialize(
            [
                'queueable' => clone $queueable,
                'retryStrategy' => $retryStrategy,
            ],
        );
        $uuid = $this->uuidFactory->uuid4();

        $statement = $this->sqliteDatabase->prepare(
            'INSERT INTO jobs (id, created_at, available_at, data)
                VALUES (:id, :created_at, :available_at, :data)',
        );

        $statement->bindValue(':available_at', ($availableAt ?? $createdAt)->getTimestamp());
        $statement->bindValue(':created_at', $createdAt->getTimestamp());
        $statement->bindParam(':data', $data);
        $statement->bindValue(':id', $uuid->toString());
        $statement->execute();

        return $uuid;
    }

    public function getNextJob(): ?Job
    {
        $statement = $this->sqliteDatabase->prepare(
            'SELECT id, attempts, data FROM jobs
                WHERE reserved_at IS NULL
                    AND available_at IS NOT NULL
                    AND available_at <= :available_at
                LIMIT 1',
        );

        $statement->bindValue(':available_at', $this->clock->now()->getTimestamp());
        $statement->execute();

        $results = $statement->fetchAll();

        if (0 === \count($results)) {
            return null;
        }

        /** @var array{ id: string, attempts: int, data: string } $result */
        $result = $results[0];

        /** @var array{ queueable: QueueableInterface, retryStrategy: RetryStrategy } $data */
        $data = unserialize($result['data']);

        $job = new Job(
            $this->uuidFactory->fromString($result['id']),
            $data['queueable'],
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
            'SELECT data FROM jobs WHERE id = :id',
        );

        $statement->bindValue(':id', $id);
        $statement->execute();

        /** @var array{ data: string }[] $results */
        $results = $statement->fetchAll();

        if (0 === \count($results)) {
            throw new \RuntimeException("Cannot find job {$id}");
        }

        /** @var array{ id: string, attempts: int, data: string } $result */
        $result = $results[0];

        /** @var array{ queueable: QueueableInterface, retryStrategy: ?RetryStrategy } $data */
        $data = unserialize($result['data']);

        return $data['retryStrategy'];
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
                data TEXT
            )',
        );
    }
}
