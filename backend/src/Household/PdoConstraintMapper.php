<?php

namespace App\Household;

use PDOException;
final class PdoConstraintMapper
{
    public static function rethrow(PDOException $exception)
    {
        $sqlState = (string) (isset($exception->errorInfo[0]) ? $exception->errorInfo[0] : $exception->getCode());
        $driverCode = (int) (isset($exception->errorInfo[1]) ? $exception->errorInfo[1] : 0);
        $details = (string) (isset($exception->errorInfo[2]) ? $exception->errorInfo[2] : '') . ' ' . $exception->getMessage();
        if ($sqlState === '23000' && $driverCode === 1062) {
            if (strpos($details, 'uq_household_members_user') !== false || strpos($details, 'uq_household_members_household_user') !== false) {
                throw new MembershipAlreadyExists('The user already belongs to a household.', 0, $exception);
            }
            if (strpos($details, 'uq_households_invite_hash') !== false) {
                throw new InviteCodeCollision('The generated invite code collided.', 0, $exception);
            }
        }
        throw $exception;
    }
}
