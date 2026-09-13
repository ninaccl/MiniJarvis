<?php

declare(strict_types=1);

namespace Tests\Household;

use App\Auth\AuthContext;
use App\Household\HouseholdService;
use App\Household\TenantGuard;
use App\Http\ApiException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InMemoryHouseholdStore;
use Tests\Support\InMemoryTransactionManager;

final class HouseholdServiceTest extends TestCase
{
    private InMemoryHouseholdStore $store;
    private HouseholdService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryHouseholdStore();
        $this->service = new HouseholdService(
            new InMemoryTransactionManager(),
            $this->store,
            new TenantGuard(),
        );
    }

    public function testUserAlreadyInHouseholdCannotCreateAnother(): void
    {
        $first = $this->service->create(new AuthContext(1, null, null), 'Home');
        $member = new AuthContext(2, null, null);
        $this->service->join($member, $first['invite_code']);

        $this->assertApiError(
            fn () => $this->service->create(new AuthContext(2, 1, 'member'), 'Other'),
            'HOUSEHOLD_ALREADY_JOINED',
            409,
        );
    }

    public function testUserAlreadyInHouseholdCannotJoinAnother(): void
    {
        $first = $this->service->create(new AuthContext(1, null, null), 'First');
        $second = $this->service->create(new AuthContext(3, null, null), 'Second');
        $this->service->join(new AuthContext(2, null, null), $first['invite_code']);

        $this->assertApiError(
            fn () => $this->service->join(new AuthContext(2, 1, 'member'), $second['invite_code']),
            'HOUSEHOLD_ALREADY_JOINED',
            409,
        );
    }

    public function testOwnerOnlyResetInvite(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');
        $this->service->join(new AuthContext(2, null, null), $created['invite_code']);

        $this->assertApiError(
            fn () => $this->service->resetInvite(new AuthContext(2, $created['household']['id'], 'member')),
            'HOUSEHOLD_OWNER_REQUIRED',
            403,
        );
    }

    public function testOwnerOnlyMemberRemoval(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');
        $this->service->join(new AuthContext(2, null, null), $created['invite_code']);

        $this->assertApiError(
            fn () => $this->service->removeMember(new AuthContext(2, $created['household']['id'], 'member'), 1),
            'HOUSEHOLD_OWNER_REQUIRED',
            403,
        );
    }

    public function testOwnerCannotRemoveThemselves(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');

        $this->assertApiError(
            fn () => $this->service->removeMember(new AuthContext(1, $created['household']['id'], 'owner'), 1),
            'HOUSEHOLD_OWNER_REMOVAL_FORBIDDEN',
            409,
        );
    }

    public function testOwnerCanRemoveNonOwnerMember(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');
        $this->service->join(new AuthContext(2, null, null), $created['invite_code']);

        $this->service->removeMember(new AuthContext(1, $created['household']['id'], 'owner'), 2);

        self::assertNull($this->store->membershipForUser(2));
    }

    public function testConcurrentMembershipConflictDuringCreateMapsTo409(): void
    {
        $this->store->createMembershipConflict = true;

        $this->assertApiError(
            fn () => $this->service->create(new AuthContext(1, null, null), 'Home'),
            'HOUSEHOLD_ALREADY_JOINED',
            409,
        );
    }

    public function testConcurrentMembershipConflictDuringJoinMapsTo409(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');
        $this->store->joinMembershipConflict = true;

        $this->assertApiError(
            fn () => $this->service->join(new AuthContext(2, null, null), $created['invite_code']),
            'HOUSEHOLD_ALREADY_JOINED',
            409,
        );
    }

    public function testInviteCollisionRetriesCreate(): void
    {
        $this->store->createInviteCollisionsRemaining = 1;
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $created['invite_code']);
    }

    public function testInviteCollisionRetriesReset(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');

        $this->store->resetInviteCollisionsRemaining = 1;
        $reset = $this->service->resetInvite(new AuthContext(1, $created['household']['id'], 'owner'));
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $reset['invite_code']);
    }

    public function testInviteCollisionExhaustionIsBoundedConflict(): void
    {
        $this->store->createInviteCollisionsRemaining = 20;

        $this->assertApiError(
            fn () => $this->service->create(new AuthContext(1, null, null), 'Home'),
            'HOUSEHOLD_INVITE_CONFLICT',
            409,
        );
        self::assertSame(10, $this->store->createAttempts);
    }

    public function testResetInviteCollisionExhaustionIsBoundedConflict(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), 'Home');
        $this->store->resetInviteCollisionsRemaining = 20;

        $this->assertApiError(
            fn () => $this->service->resetInvite(new AuthContext(1, $created['household']['id'], 'owner')),
            'HOUSEHOLD_INVITE_CONFLICT',
            409,
        );
        self::assertSame(10, $this->store->resetAttempts);
    }

    public function testOrdinaryStoreFailureIsNotMisclassifiedAsConflict(): void
    {
        $failure = new RuntimeException('connection lost');
        $this->store->createFailure = $failure;

        try {
            $this->service->create(new AuthContext(1, null, null), 'Home');
            self::fail('Expected the store failure to surface.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testHouseholdNameLimitCountsUnicodeCharacters(): void
    {
        $created = $this->service->create(new AuthContext(1, null, null), str_repeat('家', 120));
        self::assertSame(str_repeat('家', 120), $created['household']['name']);

        try {
            $this->service->create(new AuthContext(2, null, null), str_repeat('家', 121));
            self::fail('Expected a 121-character household name to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('VALIDATION_FAILED', $exception->errorCode());
        }
    }

    private function assertApiError(callable $action, string $code, int $status): void
    {
        try {
            $action();
            self::fail('Expected API error ' . $code . '.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->status());
            self::assertSame($code, $exception->errorCode());
        }
    }
}
