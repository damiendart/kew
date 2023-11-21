<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidFactory;

class Queue
{
    private const MAXIMUM_ATTEMPTS = 3;
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    private \PDO $database;

    public function __construct(
        string $databaseFilename,
        private readonly ClockInterface $clock,
        private readonly UuidFactory $uuidFactory,
    ) {
        $this->initialiseDatabase($databaseFilename);
    }

    public function addJob(
        QueueableInterface $queueable,
        ?\DateTimeImmutable $scheduledDateTime = null,
    ): void {
        $data = serialize(clone $queueable);
        $scheduledDateTime ??= $this->clock->now();
        $formattedScheduledDateTime = $scheduledDateTime->format(
            self::TIMESTAMP_FORMAT,
        );
        $statement = $this->database->prepare(
            'INSERT INTO jobs (id, created_at, available_at, data)
                VALUES (:id, :created_at, :available_at, :data)',
        );

        $uuid = $this->uuidFactory->uuid4()->toString();

        $statement->bindParam(':id', $uuid);
        $statement->bindParam(':available_at', $formattedScheduledDateTime);
        $statement->bindParam(':created_at', $formattedScheduledDateTime);
        $statement->bindParam(':data', $data);
        $statement->execute();
    }

    public function getNextJob(\DateTimeImmutable $timestamp): ?Job
    {
        $timestamp = $timestamp->format(self::TIMESTAMP_FORMAT);
        $statement = $this->database->prepare(
            'SELECT id, attempts, data FROM jobs
                WHERE reserved_at IS NULL
                    AND available_at IS NOT NULL
                    AND available_at <= :available_at
                LIMIT 1',
        );

        $statement->bindParam(':available_at', $timestamp);
        $statement->execute();

        $results = $statement->fetchAll();

        if (0 === \count($results)) {
            return null;
        }

        /** @var array{ id: string, attempts: int, data: string } $result */
        $result = $results[0];

        /** @var QueueableInterface $queueable */
        $queueable = unserialize($result['data']);

        $job = new Job(
            $this->uuidFactory->fromString($result['id']),
            $queueable,
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
        $jobId = $job->id;
        $statement = $this->database->prepare(
            'UPDATE jobs
                SET available_at = NULL, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindParam(':id', $jobId);
        $statement->execute();
    }

    public function markJobAsReserved(Job $job): void
    {
        $jobId = $job->id;
        $reservedTimestamp = $this->clock
            ->now()
            ->format(self::TIMESTAMP_FORMAT);
        $statement = $this->database->prepare(
            'UPDATE jobs
                SET attempts = attempts + 1, reserved_at = :reserved_at
                WHERE id = :id',
        );

        $statement->bindParam(':id', $jobId);
        $statement->bindParam(':reserved_at', $reservedTimestamp);
        $statement->execute();
    }

    public function markJobAsUnreserved(Job $job): void
    {
        if ($this->getJobAttempts($job) >= self::MAXIMUM_ATTEMPTS) {
            $this->markJobAsKilled($job);

            return;
        }

        $nextAttemptTimestamp = (new \DateTimeImmutable('+1 minute'))
            ->format(self::TIMESTAMP_FORMAT);
        $statement = $this->database->prepare(
            'UPDATE jobs
                SET available_at = :available_at, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindParam(':available_at', $nextAttemptTimestamp);
        $statement->bindValue(':id', $job->id);
        $statement->execute();
    }

    private function deleteJob(Job $job): void
    {
        $jobId = $job->id;
        $statement = $this->database->prepare('DELETE FROM jobs WHERE id = :id');

        $statement->bindParam(':id', $jobId);
        $statement->execute();
    }

    private function getJobAttempts(Job $job): int
    {
        $statement = $this->database->prepare(
            'SELECT attempts FROM jobs WHERE id = :id',
        );

        $statement->bindValue(':id', $job->id);
        $statement->execute();

        /** @var array{ attempts: int }[] $results */
        $results = $statement->fetchAll();

        if (0 === \count($results)) {
            throw new \RuntimeException("Cannot find job {$job->id}");
        }

        return $results[0]['attempts'];
    }

    private function initialiseDatabase(string $filename): void
    {
        $this->database = new \PDO('sqlite:' . $filename);

        $this->database->exec('PRAGMA journal_mode=WAL');
        $this->database->exec(
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
