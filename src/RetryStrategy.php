<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

namespace DamienDart\Kew;

/**
 * @psalm-api
 */
final readonly class RetryStrategy
{
    /** @var non-negative-int */
    public int $maxRetryAttempts;

    /** @var non-negative-int[] */
    public array $retryIntervals;

    /**
     * @psalm-suppress DocblockTypeContradiction
     *
     * @param non-negative-int $maxRetries The maximum number of times a job can be retried
     * @param non-negative-int ...$retryIntervals Time intervals, given in seconds, between job retries
     */
    public function __construct(
        int $maxRetries = 2,
        int ...$retryIntervals,
    ) {
        // @phpstan-ignore-next-line greater.alwaysFalse
        if (0 > $maxRetries) {
            throw new \InvalidArgumentException(
                'The number of retry attempts must be equal to or greater than zero.',
            );
        }

        foreach ($retryIntervals as $interval) {
            // @phpstan-ignore-next-line greater.alwaysFalse
            if (0 > $interval) {
                throw new \InvalidArgumentException(
                    'A retry interval must be equal to or greater than zero seconds.',
                );
            }
        }

        $this->maxRetryAttempts = $maxRetries;
        $this->retryIntervals = $retryIntervals;
    }

    /**
     * @param non-negative-int $retryCount
     *
     * @return ?non-negative-int
     */
    public function getRetryInterval(int $retryCount): ?int
    {
        if ($retryCount >= $this->maxRetryAttempts) {
            return null;
        }

        if (0 === \count($this->retryIntervals)) {
            return 0;
        }

        return $retryCount <= \count($this->retryIntervals)
            ? $this->retryIntervals[$retryCount]
            : $this->retryIntervals[\count($this->retryIntervals) - 1];
    }
}
