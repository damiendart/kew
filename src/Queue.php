<?php

/*
 * Copyright (C) 2023 Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace Kew;

use Psr\Clock\ClockInterface;

class Queue
{
    private const MAXIMUM_ATTEMPTS = 3;
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    private ClockInterface $clock;
    private \PDO $database;

    public function __construct(
        string $databaseFilename,
        ClockInterface $clock,
    ) {
        $this->clock = $clock;

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
            'INSERT INTO jobs (created_at, available_at, data)
                VALUES (:created_at, :available_at, :data)',
        );

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

        /** @var array{ id: int, attempts: int, data: string } $result */
        $result = $results[0];

        /** @var QueueableInterface $queueable */
        $queueable = unserialize($result['data']);

        return new Job($result['id'], $queueable, $result['attempts']);
    }

    public function markJobAsCompleted(Job $job): void
    {
        $this->deleteJob($job);
    }

    public function markJobAsFailed(Job $job): void
    {
        $job->failJob();
        $jobId = $job->getId();
        $statement = $this->database->prepare(
            'UPDATE jobs
                SET available_at = NULL, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindParam(':id', $jobId, \PDO::PARAM_INT);
        $statement->execute();
    }

    public function markJobAsReserved(Job $job): void
    {
        $attempts = $job->addAttempt();
        $jobId = $job->getId();
        $reservedTimestamp = $this->clock
            ->now()
            ->format(self::TIMESTAMP_FORMAT);
        $statement = $this->database->prepare(
            'UPDATE jobs
                SET attempts = :attempts, reserved_at = :reserved_at
                WHERE id = :id',
        );

        $statement->bindParam(':attempts', $attempts, \PDO::PARAM_INT);
        $statement->bindParam(':id', $jobId, \PDO::PARAM_INT);
        $statement->bindParam(':reserved_at', $reservedTimestamp);
        $statement->execute();
    }

    public function markJobAsUnreserved(Job $job): void
    {
        if ($job->getAttempts() >= self::MAXIMUM_ATTEMPTS) {
            $this->markJobAsFailed($job);

            return;
        }

        $nextAttemptTimestamp = (new \DateTimeImmutable('+1 minute'))
            ->format(self::TIMESTAMP_FORMAT);
        $jobId = $job->getId();
        $statement = $this->database->prepare(
            'UPDATE jobs
                SET available_at = :available_at, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindParam(':available_at', $nextAttemptTimestamp);
        $statement->bindParam(':id', $jobId, \PDO::PARAM_INT);
        $statement->execute();
    }

    private function deleteJob(Job $job): void
    {
        $jobId = $job->getId();
        $statement = $this->database->prepare('DELETE FROM jobs WHERE id = :id');

        $statement->bindParam(':id', $jobId, \PDO::PARAM_INT);
        $statement->execute();
    }

    private function initialiseDatabase(string $filename): void
    {
        $this->database = new \PDO('sqlite:' . $filename);

        $this->database->exec('PRAGMA journal_mode=WAL');
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS jobs(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT,
                available_at TEXT,
                reserved_at TEXT DEFAULT NULL,
                attempts INT DEFAULT 0,
                data TEXT
            )',
        );
    }
}
