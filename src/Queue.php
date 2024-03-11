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
        $this->sqliteDatabase = new \PDO("sqlite:{$databaseFilepath}");

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

        // See <https://uuid.ramsey.dev/en/stable/rfc4122/version7.html>
        // for more information about version 7 UUIDs and the advantages
        // over versions 1 (and 6).
        $uuid = $this->uuidFactory->uuid7();

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
        $now = $this->clock->now();
        $selectStatement = $this->sqliteDatabase->prepare(
            'SELECT id, payload FROM jobs
                WHERE reserved_at IS NULL
                    AND available_at IS NOT NULL
                    AND available_at <= :available_at
                LIMIT 1',
        );

        $selectStatement->bindValue(':available_at', $now->getTimestamp());
        $selectStatement->execute();

        /** @var array{ 'id': string, 'payload': string }[] $results */
        $results = $selectStatement->fetchAll();

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

        $updateStatement = $this->sqliteDatabase->prepare(
            'UPDATE jobs
                SET attempts = attempts + 1, reserved_at = :reserved_at
                WHERE id = :id',
        );

        $updateStatement->bindValue(':id', $job->id->toString());
        $updateStatement->bindValue(':reserved_at', $now->getTimestamp());
        $updateStatement->execute();

        return $job;
    }

    /**
     * @throws JobNotFoundException
     */
    public function acknowledgeJob(UuidInterface $jobId): void
    {
        $this->deleteJob($jobId);
    }

    /**
     * @throws JobAlreadyRescheduledException
     * @throws JobNotFoundException
     */
    public function retryJob(UuidInterface $jobId): void
    {
        $selectStatement = $this->sqliteDatabase->prepare(
            'SELECT attempts, reserved_at, retry_strategy FROM jobs WHERE id = :id',
        );

        $selectStatement->bindValue(':id', $jobId->toString());
        $selectStatement->execute();

        /** @var array{ attempts: positive-int, reserved_at: ?string, retry_strategy: ?string }[] $results */
        $results = $selectStatement->fetchAll();

        if (0 === \count($results)) {
            throw new JobNotFoundException($jobId);
        }

        if (null === $results[0]['reserved_at']) {
            throw new JobAlreadyRescheduledException($jobId);
        }

        if (null === $results[0]['retry_strategy']) {
            $this->killJob($jobId);

            return;
        }

        /** @var array{ 'maxRetries': non-negative-int, 'retryIntervals': non-negative-int[] } $data */
        $data = json_decode($results[0]['retry_strategy'], true);

        $interval = (new RetryStrategy($data['maxRetries'], ...$data['retryIntervals']))
            ->getRetryInterval($results[0]['attempts'] - 1);

        if (null === $interval) {
            $this->killJob($jobId);

            return;
        }

        $updateStatement = $this->sqliteDatabase->prepare(
            'UPDATE jobs
                SET available_at = :available_at, reserved_at = NULL
                WHERE id = :id',
        );

        $updateStatement->bindValue(
            ':available_at',
            $this->clock
                ->now()
                ->add(new \DateInterval("PT{$interval}S"))
                ->getTimestamp(),
        );
        $updateStatement->bindValue(':id', $jobId->toString());
        $updateStatement->execute();
    }

    /**
     * @throws JobNotFoundException
     */
    private function deleteJob(UuidInterface $jobId): void
    {
        $statement = $this->sqliteDatabase->prepare('DELETE FROM jobs WHERE id = :id');

        $statement->bindValue(':id', $jobId->toString());
        $statement->execute();

        if (0 === $statement->rowCount()) {
            throw new JobNotFoundException($jobId);
        }
    }

    private function killJob(UuidInterface $jobId): void
    {
        $statement = $this->sqliteDatabase->prepare(
            'UPDATE jobs
                SET available_at = NULL, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindValue(':id', $jobId->toString());
        $statement->execute();

        $this->eventDispatcher?->dispatch(new JobKilledEvent($jobId));
    }
}
