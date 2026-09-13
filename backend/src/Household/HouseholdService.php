<?php

declare(strict_types=1);

namespace App\Household;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Http\ApiException;
use App\Support\Text;

final class HouseholdService
{
    private const INVITE_ATTEMPTS = 10;

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly HouseholdStore $households,
        private readonly TenantGuard $guard,
    ) {
    }

    /** @return array{household:array<string,mixed>,invite_code:string} */
    public function create(AuthContext $context, string $name): array
    {
        $name = trim($name);
        if ($name === '' || Text::length($name) > 120) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Household name is invalid.', [
                'name' => $name === '' ? 'Required.' : 'Maximum length is 120 characters.',
            ]);
        }

        for ($attempt = 0; $attempt < self::INVITE_ATTEMPTS; $attempt++) {
            try {
                return $this->transactions->transaction(function () use ($context, $name): array {
                    $this->ensureNotMember($context->userId);
                    [$inviteCode, $inviteHash] = $this->newInviteCandidate();
                    if ($this->households->inviteHashExists($inviteHash)) {
                        throw new InviteCodeCollision();
                    }
                    $id = $this->households->create($name, $context->userId, $inviteHash);
                    $household = $this->presentHousehold($this->requireHousehold($id), 'owner');
                    return ['household' => $household, 'invite_code' => $inviteCode];
                });
            } catch (InviteCodeCollision) {
                continue;
            } catch (MembershipAlreadyExists) {
                throw $this->alreadyJoined();
            }
        }

        throw new ApiException(409, 'HOUSEHOLD_INVITE_CONFLICT', 'A unique invite code could not be allocated.');
    }

    /** @return array{household:array<string,mixed>} */
    public function join(AuthContext $context, string $inviteCode): array
    {
        $inviteCode = InviteCode::normalize($inviteCode);
        if (!InviteCode::isValid($inviteCode)) {
            throw new ApiException(422, 'HOUSEHOLD_INVALID_INVITE', 'The invite code is invalid.');
        }

        try {
            return $this->transactions->transaction(function () use ($context, $inviteCode): array {
                $this->ensureNotMember($context->userId);
                $household = $this->households->householdByInviteHash(InviteCode::hash($inviteCode));
                if ($household === null) {
                    throw new ApiException(404, 'HOUSEHOLD_INVITE_NOT_FOUND', 'The invite code was not found.');
                }
                $this->households->addMember((int) $household['id'], $context->userId);
                return ['household' => $this->presentHousehold($household, 'member')];
            });
        } catch (MembershipAlreadyExists) {
            throw $this->alreadyJoined();
        }
    }

    /** @return array{household:array<string,mixed>,members:list<array<string,mixed>>} */
    public function current(AuthContext $context): array
    {
        $householdId = $this->guard->requireMembership($context);
        $household = $this->requireHousehold($householdId);
        return [
            'household' => $this->presentHousehold($household, (string) $context->role),
            'members' => $this->households->members($householdId),
        ];
    }

    /** @return array{invite_code:string} */
    public function resetInvite(AuthContext $context): array
    {
        $householdId = $this->guard->requireOwner($context);
        for ($attempt = 0; $attempt < self::INVITE_ATTEMPTS; $attempt++) {
            try {
                return $this->transactions->transaction(function () use ($householdId): array {
                    [$inviteCode, $inviteHash] = $this->newInviteCandidate();
                    if ($this->households->inviteHashExists($inviteHash)) {
                        throw new InviteCodeCollision();
                    }
                    $this->households->replaceInviteHash($householdId, $inviteHash);
                    return ['invite_code' => $inviteCode];
                });
            } catch (InviteCodeCollision) {
                continue;
            }
        }

        throw new ApiException(409, 'HOUSEHOLD_INVITE_CONFLICT', 'A unique invite code could not be allocated.');
    }

    public function removeMember(AuthContext $context, int $userId): void
    {
        $householdId = $this->guard->requireOwner($context);
        $member = $this->households->member($householdId, $userId);
        if ($member === null) {
            throw new ApiException(404, 'HOUSEHOLD_MEMBER_NOT_FOUND', 'The household member was not found.');
        }
        if ($member['role'] === 'owner') {
            throw new ApiException(409, 'HOUSEHOLD_OWNER_REMOVAL_FORBIDDEN', 'The household owner cannot be removed.');
        }
        $this->transactions->transaction(function () use ($householdId, $userId): void {
            if (!$this->households->removeMember($householdId, $userId)) {
                throw new ApiException(404, 'HOUSEHOLD_MEMBER_NOT_FOUND', 'The household member was not found.');
            }
        });
    }

    private function ensureNotMember(int $userId): void
    {
        if ($this->households->membershipForUser($userId) !== null) {
            throw $this->alreadyJoined();
        }
    }

    /** @return array{string,string} */
    private function newInviteCandidate(): array
    {
        $code = InviteCode::generate();
        return [$code, InviteCode::hash($code)];
    }

    private function alreadyJoined(): ApiException
    {
        return new ApiException(409, 'HOUSEHOLD_ALREADY_JOINED', 'The user already belongs to a household.');
    }

    /** @return array{id:int,name:string,owner_user_id:int,invite_code_hash:string} */
    private function requireHousehold(int $id): array
    {
        $household = $this->households->household($id);
        if ($household === null) {
            throw new ApiException(404, 'HOUSEHOLD_NOT_FOUND', 'The household was not found.');
        }
        return $household;
    }

    /** @param array{id:int,name:string,owner_user_id:int,invite_code_hash:string} $household @return array{id:int,name:string,owner_user_id:int,role:string} */
    private function presentHousehold(array $household, string $role): array
    {
        return [
            'id' => (int) $household['id'],
            'name' => (string) $household['name'],
            'owner_user_id' => (int) $household['owner_user_id'],
            'role' => $role,
        ];
    }
}
