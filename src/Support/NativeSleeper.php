<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Support;

use Slimad\ColnectApi\Laravel\Contracts\Sleeper;

final class NativeSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep(max(0, $seconds));
    }
}
