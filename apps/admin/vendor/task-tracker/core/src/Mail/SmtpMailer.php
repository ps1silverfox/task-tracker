<?php

declare(strict_types=1);

namespace TaskTracker\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use TaskTracker\Config\Env;

/**
 * Thin wrapper over symfony/mailer that resolves transport from the SMTP_*
 * env vars and degrades to a no-op when SMTP_HOST is unset (spec §4 config:
 * "If unset, email features no-op").
 *
 * Two construction modes:
 *   - Production: pass only $env; transport is built lazily from smtp://host:port
 *     on first send(), reused on subsequent calls.
 *   - Tests: pass $env plus an injected MailerInterface (NullMailer, in-memory
 *     spy, etc.) — the env's smtp host is ignored for routing but its smtpFrom
 *     is still used as the From: header so callers don't have to hardcode it.
 *
 * The injected-mailer override is the only reason this class isn't a static
 * helper: composition lets tests assert on captured Email instances without
 * standing up a real SMTP relay.
 */
final class SmtpMailer
{
    private ?MailerInterface $resolved;

    public function __construct(
        private readonly Env $env,
        ?MailerInterface $mailer = null,
    ) {
        $this->resolved = $mailer;
    }

    public function isEnabled(): bool
    {
        return $this->resolved !== null || $this->env->smtpHost !== null;
    }

    /**
     * Send one plain-text email. Returns true on dispatched-to-transport,
     * false on no-op (SMTP disabled, empty $to) or transport failure.
     *
     * Transport errors are swallowed and forwarded to error_log() per spec §9's
     * "log an alarm — do NOT roll back the CSV write" wording. The caller has
     * already committed the state change; a failed notification must not
     * unwind the audit trail.
     */
    public function send(string $to, string $subject, string $textBody): bool
    {
        if ($to === '' || !$this->isEnabled()) {
            return false;
        }

        $mailer = $this->resolved ?? $this->buildMailer();
        $email = (new Email())
            ->from($this->env->smtpFrom)
            ->to($to)
            ->subject($subject)
            ->text($textBody);

        try {
            $mailer->send($email);
            return true;
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf(
                'task-tracker: SMTP send failed to %s: %s',
                $to,
                $e->getMessage(),
            ));
            return false;
        }
    }

    private function buildMailer(): MailerInterface
    {
        $dsn = sprintf('smtp://%s:%d', $this->env->smtpHost, $this->env->smtpPort);
        $this->resolved = new Mailer(Transport::fromDsn($dsn));
        return $this->resolved;
    }
}
