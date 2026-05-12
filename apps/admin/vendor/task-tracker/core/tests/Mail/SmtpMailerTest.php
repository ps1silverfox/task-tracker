<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TaskTracker\Config\Env;
use TaskTracker\Mail\SmtpMailer;

#[CoversClass(SmtpMailer::class)]
final class SmtpMailerTest extends TestCase
{
    public function testNoOpsWhenSmtpHostIsUnset(): void
    {
        $env = $this->env(smtpHost: null);
        $mailer = new SmtpMailer($env);

        self::assertFalse($mailer->isEnabled());
        self::assertFalse($mailer->send('alice@example.test', 'subject', 'body'));
    }

    public function testNoOpsOnEmptyRecipientEvenWhenEnabled(): void
    {
        $spy = $this->spy();
        $mailer = new SmtpMailer($this->env(smtpHost: 'smtp.test'), $spy);

        self::assertTrue($mailer->isEnabled());
        self::assertFalse($mailer->send('', 'subject', 'body'));
        self::assertCount(0, $spy->sent);
    }

    public function testDelegatesToInjectedMailerWithEnvFromAddress(): void
    {
        $spy = $this->spy();
        $mailer = new SmtpMailer($this->env(smtpFrom: 'tracker@corp.test'), $spy);

        self::assertTrue($mailer->send('alice@example.test', 'Subject A', 'Body A'));
        self::assertCount(1, $spy->sent);

        $email = $spy->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Subject A', $email->getSubject());
        self::assertSame('Body A', $email->getTextBody());
        self::assertSame('alice@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('tracker@corp.test', $email->getFrom()[0]->getAddress());
    }

    public function testTransportFailureIsSwallowedAndReturnsFalse(): void
    {
        $failing = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('relay down');
            }
        };

        $mailer = new SmtpMailer($this->env(smtpHost: 'smtp.test'), $failing);

        // suppress error_log noise from the swallowed alarm — we're asserting on it indirectly
        $log = tempnam(sys_get_temp_dir(), 'smtp-mailer-log-');
        $prev = ini_set('error_log', $log);
        try {
            self::assertFalse($mailer->send('bob@example.test', 'subject', 'body'));
            $logged = (string) file_get_contents($log);
            self::assertStringContainsString('SMTP send failed to bob@example.test', $logged);
            self::assertStringContainsString('relay down', $logged);
        } finally {
            if ($prev !== false) {
                ini_set('error_log', $prev);
            }
            @unlink($log);
        }
    }

    /**
     * Implicit second-call cache: once buildMailer() runs against the DSN, the
     * resolved transport is retained. Without the cache we'd re-resolve on
     * every send() — acceptable, but spec §9 implies notification path is hot.
     */
    public function testInjectedMailerInstanceIsReusedAcrossCalls(): void
    {
        $spy = $this->spy();
        $mailer = new SmtpMailer($this->env(smtpHost: 'smtp.test'), $spy);

        $mailer->send('alice@example.test', 'one', 'b1');
        $mailer->send('bob@example.test',   'two', 'b2');

        self::assertCount(2, $spy->sent);
        self::assertSame('one', $spy->sent[0]->getSubject());
        self::assertSame('two', $spy->sent[1]->getSubject());
    }

    private function env(
        ?string $smtpHost = 'smtp.test',
        int $smtpPort = 25,
        string $smtpFrom = 'noreply@localhost',
    ): Env {
        return new Env(
            dataDir: '/tmp/data',
            logDir:  '/tmp/logs',
            smtpHost: $smtpHost,
            smtpPort: $smtpPort,
            smtpFrom: $smtpFrom,
            baseUrl:  'http://localhost',
            timezone: 'UTC',
        );
    }

    /**
     * @return object{sent: list<Email>}
     */
    private function spy(): object
    {
        return new class implements MailerInterface {
            /** @var list<Email> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($message instanceof Email) {
                    $this->sent[] = $message;
                }
            }
        };
    }
}
