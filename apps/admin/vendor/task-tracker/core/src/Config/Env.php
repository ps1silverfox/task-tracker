<?php

declare(strict_types=1);

namespace TaskTracker\Config;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

final class Env
{
    public function __construct(
        public readonly string $dataDir,
        public readonly string $logDir,
        public readonly ?string $smtpHost,
        public readonly int $smtpPort,
        public readonly string $smtpFrom,
        public readonly string $baseUrl,
        public readonly string $timezone,
    ) {
    }

    public static function fromEnvironment(string $projectRoot, ?string $dotenvDir = null): self
    {
        if ($dotenvDir !== null && is_file($dotenvDir . DIRECTORY_SEPARATOR . '.env')) {
            $repository = RepositoryBuilder::createWithDefaultAdapters()
                ->addAdapter(PutenvAdapter::class)
                ->immutable()
                ->make();
            Dotenv::create($repository, $dotenvDir)->safeLoad();
        }

        return new self(
            dataDir:  self::read('DATA_DIR')  ?? $projectRoot . '/data',
            logDir:   self::read('LOG_DIR')   ?? $projectRoot . '/logs',
            smtpHost: self::read('SMTP_HOST'),
            smtpPort: (int) (self::read('SMTP_PORT') ?? '25'),
            smtpFrom: self::read('SMTP_FROM') ?? 'task-tracker@localhost',
            baseUrl:  self::read('BASE_URL')  ?? 'http://localhost',
            timezone: self::read('TIMEZONE')  ?? 'UTC',
        );
    }

    private static function read(string $key): ?string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return null;
        }
        return (string) $val;
    }
}
