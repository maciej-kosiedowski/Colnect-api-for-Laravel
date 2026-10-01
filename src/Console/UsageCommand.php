<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Console;

use Illuminate\Console\Command;
use Saloon\Exceptions\Request\FatalRequestException;
use Slimad\ColnectApi\Exceptions\InvalidArgumentException;
use Slimad\ColnectApi\Laravel\ColnectManager;
use Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException;
use Slimad\ColnectApi\Requests\General\GetRequestCountRequest;

final class UsageCommand extends Command
{
    /** @var string */
    protected $signature = 'colnect:usage
                            {--days=7 : How many days to report on, 1 to 200}';

    /** @var string */
    protected $description = 'Ask Colnect how many API requests the application made per day';

    public function handle(ColnectManager $manager): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if ($days === false) {
            $this->components->error('The --days option has to be a whole number between 1 and 200.');

            return self::FAILURE;
        }

        try {
            $response = $manager->send(new GetRequestCountRequest($days));
        } catch (InvalidArgumentException|RateLimitExceededException|FatalRequestException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->components->error(\sprintf(
                'Colnect answered HTTP %d: %s',
                $response->status(),
                mb_strimwidth(trim($response->body()), 0, 200, '...'),
            ));

            return self::FAILURE;
        }

        // Decoded by hand: Saloon's json() insists on an array, and CAPI answers
        // a bare string such as "private" in some situations.
        $rows = self::rows(json_decode($response->body(), true));

        if ($rows === []) {
            $this->components->info('Colnect reported no requests.');

            return self::SUCCESS;
        }

        $this->table(['Day', 'Requests'], $rows);

        return self::SUCCESS;
    }

    /**
     * CAPI answers with [day, request_count] pairs; anything else in the
     * payload is skipped rather than guessed at.
     *
     * @return list<array{int|string, int|string}>
     */
    private static function rows(mixed $payload): array
    {
        if (! \is_array($payload)) {
            return [];
        }

        if (self::isPair($payload)) {
            $payload = [$payload];
        }

        $rows = [];

        foreach ($payload as $pair) {
            if (\is_array($pair) && self::isPair($pair)) {
                $rows[] = [$pair[0], $pair[1]];
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @phpstan-assert-if-true array{0: int|string, 1: int|string} $value
     */
    private static function isPair(array $value): bool
    {
        return array_keys($value) === [0, 1] && self::isCell($value[0]) && self::isCell($value[1]);
    }

    private static function isCell(mixed $value): bool
    {
        return \is_int($value) || \is_string($value);
    }
}
