<?php

declare(strict_types=1);

namespace Tests\Household;

use App\Household\InviteCodeCollision;
use App\Household\MembershipAlreadyExists;
use App\Household\PdoConstraintMapper;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PdoConstraintMapperTest extends TestCase
{
    public function testMembershipUniqueConstraintMapsToDomainConflict(): void
    {
        $exception = $this->duplicate('uq_household_members_user');

        $this->expectException(MembershipAlreadyExists::class);
        PdoConstraintMapper::rethrow($exception);
    }

    public function testInviteUniqueConstraintMapsToRetryableCollision(): void
    {
        $exception = $this->duplicate('uq_households_invite_hash');

        $this->expectException(InviteCodeCollision::class);
        PdoConstraintMapper::rethrow($exception);
    }

    public function testUnrelatedDatabaseErrorIsRethrownUnchanged(): void
    {
        $exception = new PDOException('server went away');
        $exception->errorInfo = ['HY000', 2006, 'server went away'];

        try {
            PdoConstraintMapper::rethrow($exception);
            self::fail('Expected PDO failure to be rethrown.');
        } catch (PDOException $actual) {
            self::assertSame($exception, $actual);
        }
    }

    private function duplicate(string $constraint): PDOException
    {
        $message = "Duplicate entry 'x' for key 'jarvis_family.{$constraint}'";
        $exception = new PDOException($message);
        $exception->errorInfo = ['23000', 1062, $message];
        return $exception;
    }
}
