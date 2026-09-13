<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Auth\AuthContext;
use App\Household\PdoHouseholdStore;
use App\Household\TenantGuard;
use App\Notification\PdoNotificationRepository;
use App\Notification\ReminderService;
use App\Task\PdoTaskRepository;
use App\Task\TaskService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryTransactionManager;
use Tests\Support\QueueNotificationSender;

final class TaskNotificationMysqlTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private int $householdId;
    private PdoTaskRepository $tasks;
    private PdoNotificationRepository $notifications;
    private bool $committedFixture = false;

    protected function setUp(): void
    {
        if (!getenv('JARVIS_TEST_MYSQL_DSN')) self::markTestSkipped('Set JARVIS_TEST_MYSQL_DSN to run real MySQL regressions.');
        $this->pdo = new PDO(getenv('JARVIS_TEST_MYSQL_DSN'), getenv('JARVIS_TEST_MYSQL_USER') ?: 'root', getenv('JARVIS_TEST_MYSQL_PASSWORD') ?: '');
        new Connection($this->pdo);
        $this->pdo->beginTransaction();
        $insert = $this->pdo->prepare('INSERT INTO users (openid) VALUES (?)');
        $insert->execute(['regression:' . bin2hex(random_bytes(12))]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->householdId = (new PdoHouseholdStore($this->pdo))->create('Regression fixture', $this->userId, hash('sha256', random_bytes(32)));
        $this->tasks = new PdoTaskRepository($this->pdo);
        $this->notifications = new PdoNotificationRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->committedFixture) {
            $this->pdo->exec('DELETE FROM tasks WHERE household_id = ' . $this->householdId);
            $this->pdo->exec('DELETE FROM households WHERE id = ' . $this->householdId);
            $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->userId);
        }
    }

    public function testTaskCompletionAndReopenExecuteWithNativePreparedStatements(): void
    {
        $id = $this->task();
        $this->tasks->setStatus($this->householdId, $id, 'completed');
        self::assertSame('completed', $this->tasks->findTask($this->householdId, $id)['status']);
        self::assertNotNull($this->tasks->findTask($this->householdId, $id)['completed_at']);
        $this->tasks->setStatus($this->householdId, $id, 'pending');
        self::assertNull($this->tasks->findTask($this->householdId, $id)['completed_at']);
    }

    public function testChildrenCompletionExecutesWithNativePreparedStatements(): void
    {
        $parent = $this->task();
        $child = $this->task($parent);
        $this->tasks->setChildrenStatus($this->householdId, $parent, 'completed');
        self::assertSame('completed', $this->tasks->findTask($this->householdId, $child)['status']);
        self::assertNotNull($this->tasks->findTask($this->householdId, $child)['completed_at']);
    }

    public function testTransientRetryExecutesWithNativePreparedStatements(): void
    {
        $this->notifications->upsertJob($this->householdId, $this->userId, 'regression:' . $this->householdId, 'inventory_expiry', '2020-01-01 00:00:00', ['summary' => 'Milk']);
        $job = $this->notifications->claimDueJob('2099-01-01 00:00:00');
        $this->notifications->addGrant($this->householdId, $this->userId, 'inventory_expiry');
        $grant = $this->notifications->claimGrant($this->householdId, $this->userId, 'inventory_expiry', $job['id']);
        $this->notifications->recordSendAttempt($job['id']);
        $this->notifications->markTransientFailure($job['id'], $grant, 1, 'temporary network failure');
        $row = $this->pdo->query('SELECT status, last_error FROM notification_jobs WHERE id = ' . $job['id'])->fetch();
        self::assertSame('pending', $row['status']);
        self::assertSame('temporary network failure', $row['last_error']);
    }

    private function task(?int $parent = null): int
    {
        return $this->tasks->createTask($this->householdId, $this->userId, ['title' => 'Task', 'due_at' => null, 'parent_id' => $parent, 'assignee_user_id' => null]);
    }

    public function testFourExpiryCronRoundsWithoutGrantCancelOnceWithoutCountingSendAttempts(): void
    {
        $ingredient = $this->pdo->prepare("INSERT INTO ingredients (household_id, name, normalized_name, default_unit_code, created_by) VALUES (?, 'Milk', 'milk', 'ml', ?)");
        $ingredient->execute([$this->householdId, $this->userId]);
        $ingredientId = (int) $this->pdo->lastInsertId();
        $batch = $this->pdo->prepare("INSERT INTO inventory_batches (household_id, ingredient_id, quantity, unit_code, display_quantity, display_unit_code, expires_on, created_by) VALUES (?, ?, 100, 'ml', 100, 'ml', '2026-09-15', ?)");
        $batch->execute([$this->householdId, $ingredientId, $this->userId]);
        $sender = new QueueNotificationSender();
        $service = new ReminderService(new InMemoryTransactionManager(), $this->notifications, $sender, new TenantGuard());
        $skipped = 0;
        for ($round = 0; $round < 4; ++$round) {
            $now = new \DateTimeImmutable('2026-09-14T02:00:00Z');
            $service->materializeExpiry($now);
            $skipped += $service->deliverDue($now)['skipped_no_grant'];
        }
        $statement = $this->pdo->prepare('SELECT status, attempts FROM notification_jobs WHERE household_id = ?');
        $statement->execute([$this->householdId]);
        self::assertSame([['status' => 'cancelled', 'attempts' => 0]], $statement->fetchAll());
        self::assertSame(1, $skipped);
        self::assertSame([], $sender->sent);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedJobStates')]
    public function testMaterializationPreservesClaimedAndTerminalJobs(string $status): void
    {
        $key = 'regression:protected:' . $this->householdId;
        $this->notifications->upsertJob($this->householdId, $this->userId, $key, 'inventory_expiry', '2020-01-01 00:00:00', ['summary' => 'Original']);
        $state = $this->pdo->prepare('UPDATE notification_jobs SET status = ? WHERE event_key = ?');
        $state->execute([$status, $key]);
        $query = $this->pdo->prepare('SELECT status, scheduled_at, payload_snapshot, updated_at FROM notification_jobs WHERE event_key = ?');
        $query->execute([$key]);
        $before = $query->fetch();
        $this->notifications->upsertJob($this->householdId, $this->userId, $key, 'inventory_expiry', '2021-02-03 00:00:00', ['summary' => 'Changed']);
        $query->execute([$key]);
        self::assertSame($before, $query->fetch());
        self::assertNull($this->notifications->claimDueJob('2099-01-01 00:00:00'));
    }

    public static function protectedJobStates(): array
    {
        return [['sending'], ['sent'], ['permanent_failed'], ['cancelled']];
    }

    public function testCancelledTaskReminderCanBeRequeuedByAnExplicitTaskUpdate(): void
    {
        $key = 'regression:task:' . $this->householdId;
        $this->notifications->upsertJob($this->householdId, $this->userId, $key, 'task_due', '2020-01-01 00:00:00', ['task_id' => 123]);
        $this->notifications->cancelTaskJobs($this->householdId, 123);
        $this->notifications->upsertJob($this->householdId, $this->userId, $key, 'task_due', '2020-01-01 00:00:00', ['task_id' => 123]);
        self::assertSame($key, $this->notifications->claimDueJob('2099-01-01 00:00:00')['event_key']);
    }

    public function testCompletionCheckReadsLatestChildStateDespiteAnEarlierRepeatableReadSnapshot(): void
    {
        $parent = $this->task();
        $child = $this->task($parent);
        $this->pdo->commit();
        $this->committedFixture = true;
        $this->pdo->beginTransaction();
        self::assertSame('pending', $this->tasks->findTask($this->householdId, $child)['status']);
        $other = new PDO(getenv('JARVIS_TEST_MYSQL_DSN'), getenv('JARVIS_TEST_MYSQL_USER') ?: 'root', getenv('JARVIS_TEST_MYSQL_PASSWORD') ?: '');
        new Connection($other);
        (new PdoTaskRepository($other))->setStatus($this->householdId, $child, 'completed');
        self::assertTrue($this->tasks->allChildrenCompleted($this->householdId, $parent));
    }

    public function testHouseholdLockBlocksASecondTaskMutationUntilTheFirstTransactionFinishes(): void
    {
        $parent = $this->task();
        $one = $this->task($parent);
        $two = $this->task($parent);
        $this->pdo->commit();
        $this->committedFixture = true;
        $this->pdo->beginTransaction();
        $this->tasks->lockHousehold($this->householdId);
        $other = new PDO(getenv('JARVIS_TEST_MYSQL_DSN'), getenv('JARVIS_TEST_MYSQL_USER') ?: 'root', getenv('JARVIS_TEST_MYSQL_PASSWORD') ?: '');
        $connection = new Connection($other);
        $other->exec('SET innodb_lock_wait_timeout = 1');
        $service = new TaskService($connection, new PdoTaskRepository($other), new PdoHouseholdStore($other), new PdoNotificationRepository($other), new TenantGuard());
        $owner = new AuthContext($this->userId, $this->householdId, 'owner');
        try {
            $service->update($owner, $one, ['status' => 'completed']);
            self::fail('A second household mutation must wait for the first transaction.');
        } catch (\PDOException $exception) {
            self::assertSame(1205, $exception->errorInfo[1]);
        }
        self::assertSame('pending', $this->tasks->findTask($this->householdId, $one)['status']);
        $this->pdo->rollBack();
        $service->update($owner, $one, ['status' => 'completed']);
        $service->update($owner, $two, ['status' => 'completed']);
        self::assertSame('completed', $this->tasks->findTask($this->householdId, $parent)['status']);
    }
}
