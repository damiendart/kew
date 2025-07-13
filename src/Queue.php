<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\UuidFactory;

/**
 * @psalm-api
 */
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
                retry_intervals TEXT DEFAULT NULL,
                payload TEXT
            )',
        );
    }

    /**
     * Creates a job on the queue.
     *
     * The `availableAt` parameter is stored as UTC, and future time
     * zone rule changes are not handled. The number of times a job
     * can be retried is inferred from the number of retry intervals
     * provided.
     *
     * @psalm-api
     *
     * @psalm-suppress TypeDoesNotContainType
     * @psalm-suppress DocblockTypeContradiction
     *
     * @param non-empty-string $type An identifier used by workers to decide how to execute the job
     * @param mixed $arguments JSON-serialisable arguments used by workers when executing the job
     * @param ?\DateTimeInterface $availableAt The date and time when a job can be released for execution
     * @param non-negative-int ...$retryIntervals Retry intervals, in seconds, between job attempts
     */
    public function createJob(
        string $type,
        mixed $arguments,
        ?\DateTimeInterface $availableAt = null,
        int ...$retryIntervals,
    ): void {
        // @phpstan-ignore-next-line identical.alwaysFalse
        if ('' === $type) {
            throw new \InvalidArgumentException('A job type cannot be an empty string.');
        }

        foreach ($retryIntervals as $interval) {
            // @phpstan-ignore-next-line greater.alwaysFalse
            if (0 > $interval) {
                throw new \InvalidArgumentException('A retry interval must be equal to or greater than zero seconds.');
            }
        }

        try {
            $payload = json_encode(
                compact('arguments', 'type'),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Unable to JSON encode job payload.', previous: $e);
        }

        $createdAt = $this->clock->now();
        $uuid = $this->uuidFactory->uuid7();

        $statement = $this->sqliteDatabase->prepare(
            'INSERT INTO jobs (id, created_at, available_at, retry_intervals, payload)
                VALUES (:id, :created_at, :available_at, :retry_intervals, :payload)',
        );

        $statement->bindValue(':available_at', $this->formatTimestamp($availableAt ?? $createdAt));
        $statement->bindValue(':created_at', $this->formatTimestamp($createdAt));
        $statement->bindValue(':id', $uuid);
        $statement->bindValue(':payload', $payload);
        $statement->bindValue(':retry_intervals', json_encode($retryIntervals));

        $statement->execute();
    }

    public function getNextJob(): ?Job
    {
        $this->sqliteDatabase->exec('BEGIN IMMEDIATE');

        try {
            $now = $this->clock->now();
            $selectStatement = $this->sqliteDatabase->prepare(
                'SELECT id, payload FROM jobs
                    WHERE reserved_at IS NULL
                        AND available_at IS NOT NULL
                        AND available_at <= :available_at
                    LIMIT 1',
            );

            $selectStatement->bindValue(':available_at', $this->formatTimestamp($now));
            $selectStatement->execute();

            /** @var array{ 'id': string, 'payload': string }[] $results */
            $results = $selectStatement->fetchAll();

            if (0 === \count($results)) {
                $this->sqliteDatabase->exec('COMMIT');

                return null;
            }

            /** @var array{ 'arguments': mixed, 'type': non-empty-string } $data */
            $data = json_decode($results[0]['payload'], true);

            $job = new Job(
                $results[0]['id'],
                $data['type'],
                $data['arguments'],
            );

            $updateStatement = $this->sqliteDatabase->prepare(
                'UPDATE jobs
                    SET attempts = attempts + 1, reserved_at = :reserved_at
                    WHERE id = :id',
            );

            $updateStatement->bindValue(':id', $job->id);
            $updateStatement->bindValue(':reserved_at', $this->formatTimestamp($now));
            $updateStatement->execute();
        } catch (\Throwable $e) {
            $this->sqliteDatabase->exec('ROLLBACK');

            throw $e;
        }

        $this->sqliteDatabase->exec('COMMIT');

        return $job;
    }

    /**
     * @throws JobNotFoundException
     */
    public function acknowledgeJob(Job $job): void
    {
        $this->deleteJob($job);
    }

    /**
     * @throws JobAlreadyRescheduledException
     * @throws JobNotFoundException
     * @throws FailingKilledJobException
     */
    public function failJob(Job $job): void
    {
        $this->sqliteDatabase->exec('BEGIN IMMEDIATE');

        try {
            $selectStatement = $this->sqliteDatabase->prepare(
                'SELECT attempts, available_at, reserved_at, retry_intervals FROM jobs WHERE id = :id',
            );

            $selectStatement->bindValue(':id', $job->id);
            $selectStatement->execute();

            /** @var array{ attempts: positive-int, available_at: ?string, reserved_at: ?string, retry_intervals: string }[] $results */
            $results = $selectStatement->fetchAll();

            if (0 === \count($results)) {
                throw new JobNotFoundException($job->id);
            }

            if (null === $results[0]['available_at']) {
                throw new FailingKilledJobException($job->id);
            }

            if (null === $results[0]['reserved_at']) {
                throw new JobAlreadyRescheduledException($job->id);
            }

            /** @var non-negative-int[] $intervals */
            $intervals = json_decode(
                $results[0]['retry_intervals'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if ($results[0]['attempts'] > \count($intervals)) {
                $this->killJob($job);
                $this->sqliteDatabase->exec('COMMIT');

                return;
            }

            $interval = $intervals[$results[0]['attempts'] - 1];

            $updateStatement = $this->sqliteDatabase->prepare(
                'UPDATE jobs
                    SET available_at = :available_at, reserved_at = NULL
                    WHERE id = :id',
            );

            $updateStatement->bindValue(
                ':available_at',
                $this->formatTimestamp(
                    $this->clock
                        ->now()
                        ->add(new \DateInterval("PT{$interval}S")),
                ),
            );
            $updateStatement->bindValue(':id', $job->id);
            $updateStatement->execute();
        } catch (\Throwable $e) {
            $this->sqliteDatabase->exec('ROLLBACK');

            throw $e;
        }

        $this->sqliteDatabase->exec('COMMIT');
    }

    /**
     * @throws JobNotFoundException
     */
    private function deleteJob(Job $job): void
    {
        $statement = $this->sqliteDatabase->prepare('DELETE FROM jobs WHERE id = :id');

        $statement->bindValue(':id', $job->id);
        $statement->execute();

        if (0 === $statement->rowCount()) {
            throw new JobNotFoundException($job->id);
        }
    }

    private function killJob(Job $job): void
    {
        $statement = $this->sqliteDatabase->prepare(
            'UPDATE jobs
                SET available_at = NULL, reserved_at = NULL
                WHERE id = :id',
        );

        $statement->bindValue(':id', $job->id);
        $statement->execute();

        $this->eventDispatcher?->dispatch(new JobKilledEvent($job->id));
    }

    private function formatTimestamp(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }
}
