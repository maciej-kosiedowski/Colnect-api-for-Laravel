<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Console;

use Illuminate\Console\Command;
use Slimad\ColnectApi\Laravel\ColnectManager;
use Slimad\ColnectApi\Laravel\Config\ConnectorOptions;
use Slimad\ColnectApi\Laravel\Config\HttpOptions;
use Slimad\ColnectApi\Laravel\Exceptions\ColnectConfigurationException;

final class StatusCommand extends Command
{
    /** @var string */
    protected $signature = 'colnect:status';

    /** @var string */
    protected $description = 'Show the Colnect API configuration and how much of the rate limit is used up';

    public function handle(ColnectManager $manager, ConnectorOptions $options, HttpOptions $http): int
    {
        try {
            $baseUrl = $manager->connector()->resolveBaseUrl();
            $error = null;
        } catch (ColnectConfigurationException $exception) {
            $baseUrl = '<unavailable>';
            $error = $exception->getMessage();
        }

        $rateLimiter = $manager->rateLimiter();
        $rateLimit = $rateLimiter->options();

        /** @var list<array{string, int|string}> $rows */
        $rows = [
            ['App ID', $options->appId ?? '<not set>'],
            // The secret is never printed, not even partially.
            ['App secret', $options->appSecret === null ? '<not set>' : 'set'],
            ['Language', $options->language],
            ['User agent', $options->userAgent],
            ['Base URL', $baseUrl],
            ['Timeout', \sprintf('%d s (connect: %d s)', $http->timeout, $http->connectTimeout)],
            ['Tries', $http->tries],
            ['Rate limiting', $rateLimit->enabled ? 'enabled' : 'disabled'],
        ];

        if ($rateLimit->enabled) {
            $rows[] = ['Cache store', $rateLimit->cacheStore ?? '<default>'];

            foreach ($rateLimiter->usage() as $usage) {
                $rows[] = [
                    \sprintf('Limit: %s', $usage['limit']->name),
                    \sprintf('%d / %d used', $usage['used'], $usage['limit']->requests)
                        .($usage['resets_in'] > 0 ? \sprintf(', resets in %d s', $usage['resets_in']) : ''),
                ];
            }

            $cooldown = $rateLimiter->cooldown();

            $rows[] = ['Retry-After pause', $cooldown > 0 ? \sprintf('%d s left', $cooldown) : 'none'];
            $rows[] = ['Max wait', \sprintf('%d s', $rateLimit->maxWait)];
        }

        $this->table(['Setting', 'Value'], $rows);

        if ($error !== null) {
            $this->components->error($error);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
