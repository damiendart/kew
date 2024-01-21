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
    /** @var \DateInterval[] */
    public array $retryIntervals;

    /**
     * @param int $maxRetryAttempts The maximum number of times a job should be retried
     * @param \DateInterval ...$retryIntervals Time periods between job retries
     */
    public function __construct(
        public int $maxRetryAttempts = 2,
        \DateInterval ...$retryIntervals,
    ) {
        if (0 > $this->maxRetryAttempts) {
            throw new \InvalidArgumentException(
                'The number of retry attempts must be equal to or greater than zero.',
            );
        }

        foreach ($retryIntervals as $interval) {
            $now = new \DateTimeImmutable();

            if ($now >= $now->add($interval)) {
                throw new \InvalidArgumentException(
                    'Negative time periods cannot be used as retry intervals.',
                );
            }
        }

        $this->retryIntervals = $retryIntervals;
    }

    /** @param int<0, max> $retryCount */
    public function getRetryInterval(int $retryCount): \DateInterval|false
    {
        if ($retryCount >= $this->maxRetryAttempts) {
            return false;
        }

        if (0 === \count($this->retryIntervals)) {
            return \DateInterval::createFromDateString('+0 seconds');
        }

        return $retryCount <= \count($this->retryIntervals)
            ? $this->retryIntervals[$retryCount]
            : $this->retryIntervals[\count($this->retryIntervals) - 1];
    }
}
