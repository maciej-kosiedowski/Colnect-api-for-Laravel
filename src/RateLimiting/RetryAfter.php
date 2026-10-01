<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\RateLimiting;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\InteractsWithTime;

/**
 * Reads the Retry-After header of an HTTP 429 response (RFC 9110, 10.2.3).
 */
final class RetryAfter
{
    use InteractsWithTime;

    /**
     * A misbehaving header must not be able to park every worker for weeks.
     */
    public const MAX_SECONDS = 86400;

    /**
     * The IMF-fixdate format, spelled out: DATE_RFC7231 is deprecated as of
     * PHP 8.5.
     */
    private const HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /**
     * @param  string|array<array-key, mixed>|null  $header  as returned by Saloon's Response::header()
     * @param  int  $fallback  used when the header is missing or unreadable
     */
    public static function seconds(string|array|null $header, int $fallback): int
    {
        return (new self)->parse($header, $fallback);
    }

    /**
     * @param  string|array<array-key, mixed>|null  $header
     */
    private function parse(string|array|null $header, int $fallback): int
    {
        $value = \is_array($header) ? reset($header) : $header;
        $value = \is_string($value) ? trim($value) : '';

        if (ctype_digit($value)) {
            return self::clamp((int) $value);
        }

        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new DateTimeZone('UTC'));

        if ($date === false) {
            return self::clamp($fallback);
        }

        return self::clamp($date->getTimestamp() - $this->currentTime());
    }

    /**
     * Always wait at least one second: "Retry-After: 0" on a 429 still means
     * the request was refused.
     */
    private static function clamp(int $seconds): int
    {
        return min(max(1, $seconds), self::MAX_SECONDS);
    }
}
