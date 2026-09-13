<?php

declare(strict_types=1);

namespace App\Household;

use PDOException;

final class PdoConstraintMapper
{
    public static function rethrow(PDOException $exception): never
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $details = (string) ($exception->errorInfo[2] ?? '') . ' ' . $exception->getMessage();

        if ($sqlState === '23000' && $driverCode === 1062) {
            if (
                str_contains($details, 'uq_household_members_user')
                || str_contains($details, 'uq_household_members_household_user')
            ) {
                throw new MembershipAlreadyExists('The user already belongs to a household.', 0, $exception);
            }
            if (str_contains($details, 'uq_households_invite_hash')) {
                throw new InviteCodeCollision('The generated invite code collided.', 0, $exception);
            }
        }

        throw $exception;
    }
}
