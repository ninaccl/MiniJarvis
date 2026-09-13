<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Recipe\DecimalQuantity;
use App\Recipe\IngredientNormalizer;
use App\Recipe\RecipeRepository;
use App\Support\Text;
use DateTimeImmutable;

final class InventoryService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly InventoryRepository $inventory,
        private readonly RecipeRepository $recipes,
        private readonly UnitConverter $converter,
        private readonly InventoryClock $clock,
        private readonly TenantGuard $guard,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(AuthContext $context, string $query = '', string $status = 'all'): array
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if (Text::length($query) > 200) $fields['q'] = 'Maximum length is 200 characters.';
        if (!in_array($status, ['all', 'active', 'expiring', 'expired'], true)) $fields['status'] = 'Must be all, active, expiring, or expired.';
        $this->throwValidation($fields, 'Inventory query is invalid.');

        $today = $this->clock->today();
        $through = $today->modify('+3 days')->format('Y-m-d');
        $todayString = $today->format('Y-m-d');
        $items = [];
        foreach ($this->inventory->listBatches($householdId, trim($query)) as $batch) {
            $batchStatus = $this->status($batch, $todayString, $through);
            $include = match ($status) {
                'all' => true,
                'active' => $batchStatus !== 'inactive' && $batchStatus !== 'expired',
                default => $batchStatus === $status,
            };
            if ($include) $items[] = $batch + ['status' => $batchStatus];
        }
        return $items;
    }

    /** @return array<string,mixed> */
    public function create(AuthContext $context, array $payload): array
    {
        return $this->transactions->transaction(fn (): array => $this->createWithinTransaction($context, $payload));
    }

    /**
     * Creates a batch and its initial movement without opening a transaction.
     * The coordinating service must already own the transaction.
     *
     * @return array<string,mixed>
     */
    public function createWithinTransaction(AuthContext $context, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        $validated = $this->validateCreation($payload);
        if ($validated['ingredient_id'] !== null) {
            $ingredient = $this->recipes->ingredient($householdId, $validated['ingredient_id']);
            if ($ingredient === null) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Inventory batch is invalid.', ['ingredient_id' => 'Unknown ingredient.']);
            }
        } else {
            $normalized = IngredientNormalizer::normalize($validated['ingredient_name']);
            $ingredient = $this->recipes->ingredientByNormalizedName($householdId, $normalized)
                ?? $this->recipes->createIngredient($householdId, $context->userId, trim($validated['ingredient_name']), $normalized, $validated['unit_code']);
        }
        $base = $this->converter->toBase($validated['quantity'], $validated['unit_code']);
        $batchId = $this->inventory->createBatch($householdId, $context->userId, [
            'ingredient_id' => $ingredient['id'],
            'ingredient_name' => $ingredient['name'],
            'base_quantity' => $base['quantity'],
            'base_unit_code' => $base['unit_code'],
            'display_quantity' => $validated['quantity'],
            'display_unit_code' => $validated['unit_code'],
            'expiry_date' => $validated['expiry_date'],
            'note' => $validated['note'],
        ]);
        $this->inventory->appendMovement($householdId, $context->userId, [
            'batch_id' => $batchId,
            'operation' => 'add',
            'base_delta' => $base['quantity'],
            'base_unit_code' => $base['unit_code'],
            'display_quantity' => $validated['quantity'],
            'display_unit_code' => $validated['unit_code'],
            'note' => $validated['note'],
        ]);
        return $this->requireBatch($householdId, $batchId);
    }

    /** @return array<string,mixed> */
    public function update(AuthContext $context, int $batchId, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        $hasExpiry = array_key_exists('expiry_date', $payload);
        $hasNote = array_key_exists('note', $payload);
        $fields = [];
        if (!$hasExpiry && !$hasNote) $fields['payload'] = 'Provide expiry_date or note.';
        $expiry = $hasExpiry ? $payload['expiry_date'] : null;
        $note = $hasNote ? $payload['note'] : null;
        if ($hasExpiry && !$this->validDate($expiry)) $fields['expiry_date'] = 'Must be YYYY-MM-DD or null.';
        if ($hasNote && ($note !== null && (!is_string($note) || Text::length($note) > 255))) $fields['note'] = 'Maximum length is 255 characters.';
        $this->throwValidation($fields, 'Inventory batch is invalid.');
        $this->requireBatch($householdId, $batchId);
        if (!$this->inventory->updateBatchDetails($householdId, $batchId, $hasExpiry, $expiry, $hasNote, $note)) throw $this->notFound();
        return $this->requireBatch($householdId, $batchId);
    }

    /** @return array<string,mixed> */
    public function move(AuthContext $context, int $batchId, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        $operation = $payload['operation'] ?? null;
        $unitCode = $payload['unit_code'] ?? null;
        $note = $payload['note'] ?? null;
        $fields = [];
        if (!is_string($operation) || !in_array($operation, ['add', 'consume', 'set'], true)) $fields['operation'] = 'Must be add, consume, or set.';
        $quantity = $this->positiveQuantity($payload['quantity'] ?? null, 'quantity', $fields);
        if (!is_string($unitCode) || $this->recipes->unit($unitCode) === null) $fields['unit_code'] = 'Unknown unit.';
        if ($note !== null && (!is_string($note) || Text::length($note) > 255)) $fields['note'] = 'Maximum length is 255 characters.';
        $this->throwValidation($fields, 'Inventory movement is invalid.');

        return $this->transactions->transaction(function () use ($householdId, $context, $batchId, $operation, $quantity, $unitCode, $note): array {
            $batch = $this->inventory->findBatch($householdId, $batchId, true) ?? throw $this->notFound();
            if (!$this->converter->compatible($batch['base_unit_code'], $unitCode)) {
                throw new ApiException(422, 'UNIT_INCOMPATIBLE', 'Movement unit is incompatible with the inventory batch.', ['unit_code' => 'Use a compatible unit.']);
            }
            $base = $this->converter->toBase($quantity, $unitCode);
            $delta = match ($operation) {
                'add' => $base['quantity'],
                'consume' => Quantity::negative($base['quantity']),
                'set' => Quantity::subtract($base['quantity'], $batch['base_quantity']),
            };
            $result = Quantity::add($batch['base_quantity'], $delta);
            if (Quantity::compare($result, '0') < 0) {
                throw new ApiException(409, 'INSUFFICIENT_STOCK', 'Inventory stock cannot become negative.');
            }
            if (!$this->inventory->updateBatchQuantity($householdId, $batchId, $result)) throw $this->notFound();
            $this->inventory->appendMovement($householdId, $context->userId, [
                'batch_id' => $batchId,
                'operation' => $operation,
                'base_delta' => $delta,
                'base_unit_code' => $batch['base_unit_code'],
                'display_quantity' => $quantity,
                'display_unit_code' => $unitCode,
                'note' => $note,
            ]);
            return $this->requireBatch($householdId, $batchId);
        });
    }

    /** @return array{items:list<array<string,mixed>>,meta:array<string,int>} */
    public function movements(AuthContext $context, int $page = 1, int $pageSize = 20): array
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if ($page < 1) $fields['page'] = 'Must be at least 1.';
        if ($pageSize < 1 || $pageSize > 50) $fields['page_size'] = 'Must be between 1 and 50.';
        $this->throwValidation($fields, 'Movement query is invalid.');
        $result = $this->inventory->listMovements($householdId, $pageSize, ($page - 1) * $pageSize);
        return ['items' => $result['items'], 'meta' => [
            'page' => $page, 'page_size' => $pageSize, 'total' => $result['total'],
            'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $pageSize),
        ]];
    }

    /** @return array<string,mixed> */
    private function validateCreation(array $payload): array
    {
        $fields = [];
        $ingredientId = $payload['ingredient_id'] ?? null;
        $ingredientName = $payload['ingredient_name'] ?? null;
        if (($ingredientId === null) === ($ingredientName === null) || ($ingredientId !== null && (!is_int($ingredientId) || $ingredientId < 1))) {
            $fields['ingredient'] = 'Provide exactly one valid ingredient_id or ingredient_name.';
        }
        if ($ingredientName !== null && (!is_string($ingredientName) || IngredientNormalizer::normalize($ingredientName) === '' || Text::length(trim($ingredientName)) > 120)) {
            $fields['ingredient_name'] = 'Must be 1-120 characters.';
        }
        $quantity = $this->positiveQuantity($payload['quantity'] ?? null, 'quantity', $fields);
        $unitCode = $payload['unit_code'] ?? null;
        if (!is_string($unitCode) || $this->recipes->unit($unitCode) === null) $fields['unit_code'] = 'Unknown unit.';
        $expiry = $payload['expiry_date'] ?? null;
        if (array_key_exists('expiry_date', $payload) && !$this->validDate($expiry)) $fields['expiry_date'] = 'Must be YYYY-MM-DD or null.';
        $note = $payload['note'] ?? null;
        if ($note !== null && (!is_string($note) || Text::length($note) > 255)) $fields['note'] = 'Maximum length is 255 characters.';
        $this->throwValidation($fields, 'Inventory batch is invalid.');
        return [
            'ingredient_id' => $ingredientId, 'ingredient_name' => $ingredientName, 'unit_code' => $unitCode,
            'quantity' => $quantity, 'expiry_date' => $expiry, 'note' => $note,
        ];
    }

    /** @param array<string,string> $fields */
    private function positiveQuantity(mixed $value, string $field, array &$fields): string
    {
        try {
            return DecimalQuantity::forStorage($value);
        } catch (\Throwable) {
            $fields[$field] = 'Must fit DECIMAL(14,4), be positive, finite, and use at most 4 fractional digits.';
            return '0';
        }
    }

    private function validDate(mixed $value): bool
    {
        if ($value === null) return true;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->clock->today()->getTimezone());
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param array<string,mixed> $batch */
    private function status(array $batch, string $today, string $through): string
    {
        if (Quantity::compare($batch['base_quantity'], '0') <= 0) return 'inactive';
        if ($batch['expiry_date'] !== null && $batch['expiry_date'] < $today) return 'expired';
        if ($batch['expiry_date'] !== null && $batch['expiry_date'] <= $through) return 'expiring';
        return 'active';
    }

    /** @return array<string,mixed> */
    private function requireBatch(int $householdId, int $batchId): array
    {
        return $this->inventory->findBatch($householdId, $batchId) ?? throw $this->notFound();
    }

    private function notFound(): ApiException
    {
        return new ApiException(404, 'INVENTORY_BATCH_NOT_FOUND', 'The inventory batch was not found.');
    }

    /** @param array<string,string> $fields */
    private function throwValidation(array $fields, string $message): void
    {
        if ($fields !== []) throw new ApiException(422, 'VALIDATION_FAILED', $message, $fields);
    }
}
