<?php

namespace App\LinkPreview;

interface LinkPreviewRepository
{
    /** @param array<string,mixed> $preview */
    public function create(array $preview);
    /** @return array<string,mixed>|null */
    public function findForAdoption($tokenHash, $householdId, $userId);
    /** @return array<string,mixed>|null */
    public function findForImage($tokenHash, $householdId, $userId);
    public function markAdopted($id, $adoptedAt, $publicUrl);
    public function clearTemporaryPath($id);
}
