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
final readonly class RetryStrategy implements \JsonSerializable
{
    /** @var non-negative-int[] */
    public array $retryIntervals;

    /**
     * @param non-negative-int ...$retryIntervals Time intervals, given in seconds, between job attempts
     *
     * @psalm-suppress DocblockTypeContradiction
     */
    public function __construct(int ...$retryIntervals)
    {
        foreach ($retryIntervals as $interval) {
            // @phpstan-ignore-next-line greater.alwaysFalse
            if (0 > $interval) {
                throw new \InvalidArgumentException(
                    'A retry interval must be equal to or greater than zero seconds.',
                );
            }
        }

        $this->retryIntervals = $retryIntervals;
    }

    /**
     * @param non-negative-int $attemptCount
     *
     * @return ?non-negative-int
     */
    public function getRetryInterval(int $attemptCount): ?int
    {
        if ($attemptCount > \count($this->retryIntervals)) {
            return null;
        }

        return $this->retryIntervals[$attemptCount - 1];
    }

    /**
     * @return array{
     *     'retryIntervals': non-negative-int[],
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'retryIntervals' => $this->retryIntervals,
        ];
    }
}
