<?php

declare(strict_types=1);

namespace Tests\Household;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class TenantGuardTest extends TestCase
{
    public function testMemberEditableResourceDeniesUserWithoutHousehold(): void
    {
        $guard = new TenantGuard();

        try {
            $guard->requireMembership(new AuthContext(10, null, null));
            self::fail('Expected tenant access to be denied.');
        } catch (ApiException $exception) {
            self::assertSame(403, $exception->status());
            self::assertSame('HOUSEHOLD_MEMBERSHIP_REQUIRED', $exception->errorCode());
        }
    }

    public function testResourceFromAnotherTenantIsHidden(): void
    {
        $guard = new TenantGuard();

        try {
            $guard->requireTenant(new AuthContext(10, 20, 'member'), 21);
            self::fail('Expected cross-tenant access to be denied.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status());
            self::assertSame('RESOURCE_NOT_FOUND', $exception->errorCode());
        }
    }
}
