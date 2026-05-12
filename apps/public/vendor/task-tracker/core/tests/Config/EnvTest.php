<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Config;

use PHPUnit\Framework\TestCase;
use TaskTracker\Config\Env;

final class EnvTest extends TestCase
{
    /** Keys the Env class touches — snapshotted in setUp, restored in tearDown. */
    private const KEYS = [
        'DATA_DIR',
        'LOG_DIR',
        'SMTP_HOST',
        'SMTP_PORT',
        'SMTP_FROM',
        'BASE_URL',
        'TIMEZONE',
    ];

    /** @var array<string,string|false> */
    private array $snapshot = [];

    private string $tmpDir;

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) {
            $this->snapshot[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        $this->tmpDir = sys_get_temp_dir() . '/tt-env-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
            $orig = $this->snapshot[$k] ?? false;
            if ($orig !== false) {
                putenv($k . '=' . $orig);
                $_ENV[$k] = $orig;
                $_SERVER[$k] = $orig;
            }
        }
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testDefaultsWhenNoEnvSet(): void
    {
        $env = Env::fromEnvironment('/project');

        self::assertSame('/project/data', $env->dataDir);
        self::assertSame('/project/logs', $env->logDir);
        self::assertNull($env->smtpHost);
        self::assertSame(25, $env->smtpPort);
        self::assertSame('task-tracker@localhost', $env->smtpFrom);
        self::assertSame('http://localhost', $env->baseUrl);
        self::assertSame('UTC', $env->timezone);
    }

    public function testEnvVarsOverrideDefaults(): void
    {
        putenv('DATA_DIR=/var/data');
        putenv('LOG_DIR=/var/log');
        putenv('SMTP_HOST=smtp.example.com');
        putenv('SMTP_PORT=587');
        putenv('SMTP_FROM=noreply@example.com');
        putenv('BASE_URL=https://tasks.example.com');
        putenv('TIMEZONE=America/Chicago');

        $env = Env::fromEnvironment('/project');

        self::assertSame('/var/data', $env->dataDir);
        self::assertSame('/var/log', $env->logDir);
        self::assertSame('smtp.example.com', $env->smtpHost);
        self::assertSame(587, $env->smtpPort);
        self::assertSame('noreply@example.com', $env->smtpFrom);
        self::assertSame('https://tasks.example.com', $env->baseUrl);
        self::assertSame('America/Chicago', $env->timezone);
    }

    public function testDotenvFileLoadedWhenEnvAbsent(): void
    {
        file_put_contents(
            $this->tmpDir . '/.env',
            "DATA_DIR=/dotenv/data\nTIMEZONE=Europe/Berlin\n",
        );

        $env = Env::fromEnvironment('/project', $this->tmpDir);

        self::assertSame('/dotenv/data', $env->dataDir);
        self::assertSame('Europe/Berlin', $env->timezone);
        self::assertSame('/project/logs', $env->logDir, 'unset keys still fall through to defaults');
    }

    public function testRealEnvOverridesDotenv(): void
    {
        putenv('DATA_DIR=/from-environment');
        file_put_contents($this->tmpDir . '/.env', "DATA_DIR=/from-dotenv\n");

        $env = Env::fromEnvironment('/project', $this->tmpDir);

        self::assertSame('/from-environment', $env->dataDir);
    }

    public function testMissingDotenvFileIsNotFatal(): void
    {
        $env = Env::fromEnvironment('/project', $this->tmpDir);

        self::assertSame('/project/data', $env->dataDir);
    }

    public function testEmptyStringIsTreatedAsUnset(): void
    {
        putenv('SMTP_HOST=');
        putenv('TIMEZONE=');

        $env = Env::fromEnvironment('/project');

        self::assertNull($env->smtpHost);
        self::assertSame('UTC', $env->timezone);
    }
}
