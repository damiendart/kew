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
    private ?\PDO $sqliteDatabase;

    /**
     * @psalm-api
     */
    public function __construct(
        private readonly string $databaseFilepath,
        private readonly ClockInterface $clock,
        private readonly UuidFactory $uuidFactory,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->sqliteDatabase = null;
    }

    /**
     * Removes the job from the queue.
     *
     * @throws JobNotFoundException
     */
    public function acknowledgeJob(Job $job): void
    {
        $statement = $this->getDatabase()->prepare('DELETE FROM jobs WHERE id = :id');

        $statement->bindValue(':id', $job->id);
        $statement->execute();

        if (0 === $statement->rowCount()) {
            throw new JobNotFoundException($job->id);
        }
    }

    /**
     * Creates a job and adds it to the queue.
     *
     * A job's `availableAt` parameter is normalised to UTC before it is
     * saved to the database. The number of times a job can be attempted
     * is inferred from how many attempt intervals are provided.
     *
     * @psalm-api
     *
     * @psalm-suppress TypeDoesNotContainType
     * @psalm-suppress DocblockTypeContradiction
     *
     * @param non-empty-string $type A job identifier
     * @param mixed $arguments JSON-serialisable arguments associated with the job
     * @param ?\DateTimeInterface $availableAt The date and time when a job can be released
     * @param non-negative-int ...$attemptIntervals The number of seconds to wait between attempts
     */
    public function createJob(
        string $type,
        mixed $arguments,
        ?\DateTimeInterface $availableAt = null,
        int ...$attemptIntervals,
    ): void {
        // @phpstan-ignore-next-line identical.alwaysFalse
        if ('' === $type) {
            throw new \InvalidArgumentException('A job type cannot be an empty string.');
        }

        foreach ($attemptIntervals as $interval) {
            // @phpstan-ignore-next-line greater.alwaysFalse
            if (0 > $interval) {
                throw new \InvalidArgumentException('An attempt interval must be equal to or greater than zero seconds.');
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

        $statement = $this->getDatabase()->prepare(
            'INSERT INTO jobs (id, created_at, available_at, attempt_intervals, payload)
                VALUES (:id, :created_at, :available_at, :attempt_intervals, :payload)',
        );

        $statement->bindValue(':available_at', $this->makeNormalisedTimestamp($availableAt ?? $createdAt));
        $statement->bindValue(':created_at', $this->makeNormalisedTimestamp($createdAt));
        $statement->bindValue(':id', $uuid);
        $statement->bindValue(':payload', $payload);
        $statement->bindValue(':attempt_intervals', json_encode($attemptIntervals));

        $statement->execute();
    }

    /**
     * Returns the job to the queue.
     *
     * If a job has exhausted all of its allowed attempts, it is marked
     * as killed and will not be attempted again.
     *
     * @see JobKilledEvent Emitted when a job is killed.
     *
     * @param null|\Throwable $throwable The Throwable that caused the job to fail. Added to JobKilledEvent.
     *
     * @throws JobAlreadyRescheduledException
     * @throws JobNotFoundException
     * @throws FailingKilledJobException
     */
    public function failJob(Job $job, ?\Throwable $throwable = null): void
    {
        $this->getDatabase()->exec('BEGIN IMMEDIATE');

        try {
            $selectStatement = $this->getDatabase()->prepare(
                'SELECT attempts, available_at, reserved_at, attempt_intervals FROM jobs WHERE id = :id',
            );

            $selectStatement->bindValue(':id', $job->id);
            $selectStatement->execute();

            /** @var array{ attempts: positive-int, available_at: ?string, reserved_at: ?string, attempt_intervals: string }[] $results */
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
                $results[0]['attempt_intervals'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if ($results[0]['attempts'] > \count($intervals)) {
                $statement = $this->getDatabase()->prepare(
                    'UPDATE jobs
                    SET available_at = NULL, reserved_at = NULL
                    WHERE id = :id',
                );

                $statement->bindValue(':id', $job->id);
                $statement->execute();

                $this->getDatabase()->exec('COMMIT');

                $this->eventDispatcher?->dispatch(new JobKilledEvent($job->id, $throwable));

                return;
            }

            $interval = $intervals[$results[0]['attempts'] - 1];

            $updateStatement = $this->getDatabase()->prepare(
                'UPDATE jobs
                    SET available_at = :available_at, reserved_at = NULL
                    WHERE id = :id',
            );

            $updateStatement->bindValue(
                ':available_at',
                $this->makeNormalisedTimestamp(
                    $this->clock
                        ->now()
                        ->add(new \DateInterval("PT{$interval}S")),
                ),
            );
            $updateStatement->bindValue(':id', $job->id);
            $updateStatement->execute();
        } catch (\Throwable $e) {
            $this->getDatabase()->exec('ROLLBACK');

            throw $e;
        }

        $this->getDatabase()->exec('COMMIT');
    }

    /**
     * Gets the next job if one is available.
     */
    public function getNextJob(): ?Job
    {
        $this->getDatabase()->exec('BEGIN IMMEDIATE');

        try {
            $now = $this->clock->now();
            $selectStatement = $this->getDatabase()->prepare(
                'SELECT id, payload FROM jobs
                    WHERE reserved_at IS NULL
                        AND available_at IS NOT NULL
                        AND available_at <= :available_at
                    LIMIT 1',
            );

            $selectStatement->bindValue(':available_at', $this->makeNormalisedTimestamp($now));
            $selectStatement->execute();

            /** @var array{ 'id': string, 'payload': string }[] $results */
            $results = $selectStatement->fetchAll();

            if (0 === \count($results)) {
                $this->getDatabase()->exec('COMMIT');

                return null;
            }

            /** @var array{ 'arguments': mixed, 'type': non-empty-string } $data */
            $data = json_decode($results[0]['payload'], true);

            $job = new Job(
                $results[0]['id'],
                $data['type'],
                $data['arguments'],
            );

            $updateStatement = $this->getDatabase()->prepare(
                'UPDATE jobs
                    SET attempts = attempts + 1, reserved_at = :reserved_at
                    WHERE id = :id',
            );

            $updateStatement->bindValue(':id', $job->id);
            $updateStatement->bindValue(':reserved_at', $this->makeNormalisedTimestamp($now));
            $updateStatement->execute();
        } catch (\Throwable $e) {
            $this->getDatabase()->exec('ROLLBACK');

            throw $e;
        }

        $this->getDatabase()->exec('COMMIT');

        return $job;
    }

    private function getDatabase(): \PDO
    {
        if (null === $this->sqliteDatabase) {
            $this->sqliteDatabase = new \PDO('sqlite:' . $this->databaseFilepath);

            $this->sqliteDatabase->exec('PRAGMA busy_timeout=5000');
            $this->sqliteDatabase->exec('PRAGMA journal_mode=WAL');
            $this->sqliteDatabase->exec('PRAGMA synchronous=NORMAL');
            $this->sqliteDatabase->exec(
                'CREATE TABLE IF NOT EXISTS jobs(
                    id TEXT PRIMARY KEY UNIQUE,
                    created_at TEXT,
                    available_at TEXT,
                    reserved_at TEXT DEFAULT NULL,
                    attempts INT DEFAULT 0,
                    attempt_intervals TEXT DEFAULT NULL,
                    payload TEXT
                )',
            );
        }

        return $this->sqliteDatabase;
    }

    private function makeNormalisedTimestamp(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }
}
