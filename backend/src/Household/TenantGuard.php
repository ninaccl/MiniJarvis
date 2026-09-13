<?php

declare(strict_types=1);

namespace App\Household;

use App\Auth\AuthContext;
use App\Http\ApiException;

final class TenantGuard
{
    public function requireMembership(AuthContext $context): int
    {
        if ($context->householdId === null || $context->role === null) {
            throw new ApiException(403, 'HOUSEHOLD_MEMBERSHIP_REQUIRED', 'Household membership is required.');
        }
        return $context->householdId;
    }

    public function requireOwner(AuthContext $context): int
    {
        $householdId = $this->requireMembership($context);
        if ($context->role !== 'owner') {
            throw new ApiException(403, 'HOUSEHOLD_OWNER_REQUIRED', 'Household owner access is required.');
        }
        return $householdId;
    }

    public function requireTenant(AuthContext $context, int $resourceHouseholdId): int
    {
        $householdId = $this->requireMembership($context);
        if ($householdId !== $resourceHouseholdId) {
            throw new ApiException(404, 'RESOURCE_NOT_FOUND', 'The requested resource was not found.');
        }
        return $householdId;
    }
}
