<?php

declare(strict_types=1);

namespace Kode\Parallel\Sync;

function mutex(?string $name = null): Mutex
{
    return $name !== null ? Mutex::named($name) : new Mutex();
}

function semaphore(int $count = 1, ?string $name = null): Semaphore
{
    return $name !== null ? Semaphore::named($count, $name) : new Semaphore($count);
}

function cond(?string $name = null): Cond
{
    return $name !== null ? Cond::named($name) : new Cond();
}

function barrier(int $count, ?string $name = null): Barrier
{
    return $name !== null ? Barrier::named($count, $name) : new Barrier($count);
}
