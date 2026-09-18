<?php

declare(strict_types=1);

namespace Tests\Household;

use App\Household\PdoHouseholdStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoHouseholdStoreTest extends TestCase
{
    public function testRemoveMemberClearsOptionalReferencesWithinTenantBeforeDeletingMembership(): void
    {
        $pdo = new RecordingHouseholdPdo();
        $store = new PdoHouseholdStore($pdo);

        self::assertTrue($store->removeMember(17, 29));

        self::assertCount(4, $pdo->statements);
        self::assertSame(
            'UPDATE jarvis_shopping_list_items SET checked_household_id = NULL, checked_by = NULL WHERE household_id = :scope_household_id AND checked_household_id = :member_household_id AND checked_by = :user_id',
            $pdo->statements[0]->normalizedSql(),
        );
        self::assertSame(
            'UPDATE jarvis_shopping_list_items SET stocked_household_id = NULL, stocked_by = NULL WHERE household_id = :scope_household_id AND stocked_household_id = :member_household_id AND stocked_by = :user_id',
            $pdo->statements[1]->normalizedSql(),
        );
        self::assertSame(
            'UPDATE jarvis_tasks SET assigned_household_id = NULL, assigned_to = NULL WHERE household_id = :scope_household_id AND assigned_household_id = :member_household_id AND assigned_to = :user_id',
            $pdo->statements[2]->normalizedSql(),
        );
        self::assertSame(
            "DELETE FROM jarvis_household_members WHERE household_id = :household_id AND user_id = :user_id AND role <> 'owner'",
            $pdo->statements[3]->normalizedSql(),
        );

        foreach (array_slice($pdo->statements, 0, 3) as $statement) {
            self::assertSame(
                ['scope_household_id' => 17, 'member_household_id' => 17, 'user_id' => 29],
                $statement->parameters,
            );
        }
        self::assertSame(['household_id' => 17, 'user_id' => 29], $pdo->statements[3]->parameters);
    }
}

final class RecordingHouseholdPdo extends PDO
{
    /** @var list<RecordingHouseholdStatement> */
    public array $statements = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $statement = new RecordingHouseholdStatement($query, str_starts_with(ltrim($query), 'DELETE'));
        $this->statements[] = $statement;
        return $statement;
    }
}

final class RecordingHouseholdStatement extends PDOStatement
{
    /** @var array<string,int>|null */
    public ?array $parameters = null;

    public function __construct(private readonly string $sql, private readonly bool $deletesMember)
    {
    }

    public function execute(?array $params = null): bool
    {
        /** @var array<string,int>|null $params */
        $this->parameters = $params;
        return true;
    }

    public function rowCount(): int
    {
        return $this->deletesMember ? 1 : 0;
    }

    public function normalizedSql(): string
    {
        return preg_replace('/\s+/', ' ', trim($this->sql)) ?? '';
    }
}