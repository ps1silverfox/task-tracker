<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TaskTracker\Admin\Services\NotificationService;
use TaskTracker\Config\Env;
use TaskTracker\Mail\SmtpMailer;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

#[CoversClass(NotificationService::class)]
final class NotificationServiceTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private TaskRepository $tasks;
    private RosterRepository $roster;
    /** @var object{sent: list<Email>} */
    private object $spy;
    private SmtpMailer $mailer;
    private NotificationService $service;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-notif-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: 'smtp.test',
            smtpPort: 25,
            smtpFrom: 'tracker@corp.test',
            baseUrl:  'http://127.0.0.1:8080',
            timezone: 'UTC',
        );

        $csv = new CsvStore();
        $events = new EventLog($this->env->logDir);
        $this->tasks  = new TaskRepository($csv,  $events, $this->env->dataDir . DIRECTORY_SEPARATOR . 'tasks.csv');
        $this->roster = new RosterRepository($csv, $events, $this->env->dataDir . DIRECTORY_SEPARATOR . 'roster.csv');

        $this->spy = new class implements MailerInterface {
            /** @var list<Email> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($message instanceof Email) {
                    $this->sent[] = $message;
                }
            }
        };
        $this->mailer = new SmtpMailer($this->env, $this->spy);
        $this->service = new NotificationService($this->tasks, $this->roster, $this->mailer, $this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testNotifyAssignmentSendsToRosterEmail(): void
    {
        $alice = $this->roster->add(['name' => 'Alice', 'email' => 'alice@corp.test']);
        $task  = $this->tasks->create(['slug' => 'fix-bug', 'title' => 'Fix the bug', 'priority' => 'high']);

        self::assertTrue($this->service->notifyAssignment($task->id, $alice->id));
        self::assertCount(1, $this->spy->sent);

        $email = $this->spy->sent[0];
        self::assertSame('alice@corp.test', $email->getTo()[0]->getAddress());
        self::assertSame('tracker@corp.test', $email->getFrom()[0]->getAddress());
        self::assertStringContainsString('Fix the bug', $email->getSubject());
        $body = $email->getTextBody();
        self::assertIsString($body);
        self::assertStringContainsString('Fix the bug', $body);
        self::assertStringContainsString('high', $body);
        self::assertStringContainsString('http://127.0.0.1:8080/tasks/' . $task->id, $body);
    }

    public function testNotifyAssignmentSkipsWhenRosterMemberHasNoEmail(): void
    {
        $noEmail = $this->roster->add(['name' => 'No-Email']);
        $task    = $this->tasks->create(['slug' => 'silent', 'title' => 'Silent']);

        self::assertFalse($this->service->notifyAssignment($task->id, $noEmail->id));
        self::assertCount(0, $this->spy->sent);
    }

    public function testNotifyAssignmentSkipsDeactivatedAssignee(): void
    {
        $alex = $this->roster->add(['name' => 'Alex', 'email' => 'alex@corp.test']);
        $this->roster->deactivate($alex->id);
        $task = $this->tasks->create(['slug' => 'skip', 'title' => 'Skip']);

        self::assertFalse($this->service->notifyAssignment($task->id, $alex->id));
        self::assertCount(0, $this->spy->sent);
    }

    public function testNotifyAssignmentReturnsFalseWhenTaskMissing(): void
    {
        $alice = $this->roster->add(['name' => 'Alice', 'email' => 'alice@corp.test']);
        self::assertFalse($this->service->notifyAssignment('0192f000-0000-7000-8000-000000000000', $alice->id));
        self::assertCount(0, $this->spy->sent);
    }

    public function testNotifyDueSoonOnlyEmailsTasksInsideTheWindow(): void
    {
        $today = '2026-05-11';
        $alice = $this->roster->add(['name' => 'Alice', 'email' => 'alice@corp.test']);
        $bob   = $this->roster->add(['name' => 'Bob',   'email' => 'bob@corp.test']);

        // In window (today+1)
        $soon = $this->tasks->create(['slug' => 'soon', 'title' => 'Soon', 'dueDate' => '2026-05-12']);
        $this->tasks->update($soon->id, ['assigneeId' => $alice->id]);

        // Already overdue — must NOT fire here
        $past = $this->tasks->create(['slug' => 'past', 'title' => 'Past', 'dueDate' => '2026-05-01']);
        $this->tasks->update($past->id, ['assigneeId' => $alice->id]);

        // Outside window (today+10, withinDays=3)
        $far = $this->tasks->create(['slug' => 'far', 'title' => 'Far', 'dueDate' => '2026-05-21']);
        $this->tasks->update($far->id, ['assigneeId' => $bob->id]);

        $sent = $this->service->notifyDueSoon(3, $today);

        self::assertSame(['alice@corp.test'], $sent);
        self::assertCount(1, $this->spy->sent);
        self::assertStringContainsString('Due soon: Soon', $this->spy->sent[0]->getSubject());
    }

    public function testNotifyDueSoonSkipsDoneAndUnassigned(): void
    {
        $today = '2026-05-11';
        $alice = $this->roster->add(['name' => 'Alice', 'email' => 'alice@corp.test']);

        $done = $this->tasks->create(['slug' => 'done', 'title' => 'Done', 'dueDate' => '2026-05-12']);
        $this->tasks->update($done->id, ['assigneeId' => $alice->id]);
        $this->tasks->update($done->id, ['status' => 'done']);

        $this->tasks->create(['slug' => 'orphan', 'title' => 'Orphan', 'dueDate' => '2026-05-12']); // unassigned

        $sent = $this->service->notifyDueSoon(3, $today);

        self::assertSame([], $sent);
        self::assertCount(0, $this->spy->sent);
    }

    public function testNotifyOverdueOnlyEmailsTasksWithDueDateBeforeToday(): void
    {
        $today = '2026-05-11';
        $alice = $this->roster->add(['name' => 'Alice', 'email' => 'alice@corp.test']);

        $past = $this->tasks->create(['slug' => 'past', 'title' => 'Past', 'dueDate' => '2026-05-01']);
        $this->tasks->update($past->id, ['assigneeId' => $alice->id]);

        $today_task = $this->tasks->create(['slug' => 'today', 'title' => 'Today', 'dueDate' => $today]);
        $this->tasks->update($today_task->id, ['assigneeId' => $alice->id]);

        $future = $this->tasks->create(['slug' => 'future', 'title' => 'Future', 'dueDate' => '2026-06-01']);
        $this->tasks->update($future->id, ['assigneeId' => $alice->id]);

        $sent = $this->service->notifyOverdue($today);

        self::assertSame(['alice@corp.test'], $sent);
        self::assertCount(1, $this->spy->sent);
        self::assertStringContainsString('Overdue: Past', $this->spy->sent[0]->getSubject());
        $body = $this->spy->sent[0]->getTextBody();
        self::assertIsString($body);
        self::assertStringContainsString('2026-05-01', $body);
    }

    public function testNotifyOverdueSkipsDoneTasks(): void
    {
        $today = '2026-05-11';
        $alice = $this->roster->add(['name' => 'Alice', 'email' => 'alice@corp.test']);

        $past = $this->tasks->create(['slug' => 'past', 'title' => 'Past', 'dueDate' => '2026-05-01']);
        $this->tasks->update($past->id, ['assigneeId' => $alice->id]);
        $this->tasks->update($past->id, ['status' => 'done']);

        $sent = $this->service->notifyOverdue($today);

        self::assertSame([], $sent);
        self::assertCount(0, $this->spy->sent);
    }

    public function testNoEmailsWhenSmtpHostUnset(): void
    {
        $disabledEnv = new Env(
            dataDir:  $this->env->dataDir,
            logDir:   $this->env->logDir,
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'tracker@corp.test',
            baseUrl:  'http://127.0.0.1:8080',
            timezone: 'UTC',
        );
        $disabledMailer = new SmtpMailer($disabledEnv);
        $service = new NotificationService($this->tasks, $this->roster, $disabledMailer, $disabledEnv);

        $alice = $this->roster->add(['name' => 'Alice', 'email' => 'alice@corp.test']);
        $task  = $this->tasks->create(['slug' => 'noop', 'title' => 'NoOp']);

        self::assertFalse($service->notifyAssignment($task->id, $alice->id));
        self::assertCount(0, $this->spy->sent);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
