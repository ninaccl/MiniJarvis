<?php

declare(strict_types=1);

namespace App\LinkPreview;

interface LinkPreviewRepository
{
    /** @param array<string,mixed> $preview */
    public function create(array $preview): int;
    /** @return array<string,mixed>|null */
    public function findForAdoption(string $tokenHash, int $householdId, int $userId): ?array;
    /** @return array<string,mixed>|null */
    public function findForImage(string $tokenHash, int $householdId, int $userId): ?array;
    public function markAdopted(int $id, string $adoptedAt, string $publicUrl): bool;
    public function clearTemporaryPath(int $id): void;
}
