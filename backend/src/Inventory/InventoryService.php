<?php

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
    private $transactions;
    private $inventory;
    private $recipes;
    private $converter;
    private $clock;
    private $guard;
    public function __construct(TransactionManager $transactions, InventoryRepository $inventory, RecipeRepository $recipes, UnitConverter $converter, InventoryClock $clock, TenantGuard $guard)
    {
        $this->transactions = $transactions;
        $this->inventory = $inventory;
        $this->recipes = $recipes;
        $this->converter = $converter;
        $this->clock = $clock;
        $this->guard = $guard;
    }
    /** @return list<array<string,mixed>> */
    public function listItems(AuthContext $context, $query = '', $status = 'all', $expiryDays = 15)
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if (Text::length($query) > 200) {
            $fields['q'] = 'Maximum length is 200 characters.';
        }
        if (!in_array($status, ['all', 'active', 'expiring', 'expired'], true)) {
            $fields['status'] = 'Must be all, active, expiring, or expired.';
        }
        if (!is_int($expiryDays) || $expiryDays < 0 || $expiryDays > 365) {
            $fields['expiry_days'] = 'Must be an integer from 0 to 365.';
        }
        $this->throwValidation($fields, 'Inventory query is invalid.');
        $today = $this->clock->today();
        $through = $today->modify('+' . $expiryDays . ' days')->format('Y-m-d');
        $todayString = $today->format('Y-m-d');
        $items = [];
        foreach ($this->inventory->listBatches($householdId, trim($query)) as $batch) {
            $batchStatus = $this->status($batch, $todayString, $through);
            $include = $status === 'all' || ($status === 'active' ? $batchStatus !== 'inactive' && $batchStatus !== 'expired' : $batchStatus === $status);
            if ($include) {
                $items[] = $batch + ['status' => $batchStatus];
            }
        }
        return $items;
    }
    /** @return array<string,mixed> */
    public function create(AuthContext $context, array $payload)
    {
        return $this->transactions->transaction(function () use ($context, $payload) {
            return $this->createWithinTransaction($context, $payload);
        });
    }
    /**
     * Creates a batch and its initial movement without opening a transaction.
     * The coordinating service must already own the transaction.
     *
     * @return array<string,mixed>
     */
    public function createWithinTransaction(AuthContext $context, array $payload)
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
            $ingredient = $this->recipes->ingredientByNormalizedName($householdId, $normalized) !== null ? $this->recipes->ingredientByNormalizedName($householdId, $normalized) : $this->recipes->createIngredient($householdId, $context->userId, trim($validated['ingredient_name']), $normalized, $validated['unit_code']);
        }
        $base = $this->converter->toBase($validated['quantity'], $validated['unit_code']);
        $batchId = $this->inventory->createBatch($householdId, $context->userId, ['ingredient_id' => $ingredient['id'], 'ingredient_name' => $ingredient['name'], 'base_quantity' => $base['quantity'], 'base_unit_code' => $base['unit_code'], 'display_quantity' => $validated['quantity'], 'display_unit_code' => $validated['unit_code'], 'expiry_date' => $validated['expiry_date'], 'note' => $validated['note']]);
        $this->inventory->appendMovement($householdId, $context->userId, ['batch_id' => $batchId, 'operation' => 'add', 'base_delta' => $base['quantity'], 'base_unit_code' => $base['unit_code'], 'display_quantity' => $validated['quantity'], 'display_unit_code' => $validated['unit_code'], 'note' => $validated['note']]);
        return $this->requireBatch($householdId, $batchId);
    }
    /** @return array<string,mixed> */
    public function update(AuthContext $context, $batchId, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        $hasExpiry = array_key_exists('expiry_date', $payload);
        $hasNote = array_key_exists('note', $payload);
        $fields = [];
        if (!$hasExpiry && !$hasNote) {
            $fields['payload'] = 'Provide expiry_date or note.';
        }
        $expiry = $hasExpiry ? $payload['expiry_date'] : null;
        $note = $hasNote ? $payload['note'] : null;
        if ($hasExpiry && !$this->validDate($expiry)) {
            $fields['expiry_date'] = 'Must be YYYY-MM-DD or null.';
        }
        if ($hasNote && ($note !== null && (!is_string($note) || Text::length($note) > 255))) {
            $fields['note'] = 'Maximum length is 255 characters.';
        }
        $this->throwValidation($fields, 'Inventory batch is invalid.');
        $this->requireBatch($householdId, $batchId);
        if (!$this->inventory->updateBatchDetails($householdId, $batchId, $hasExpiry, $expiry, $hasNote, $note)) {
            throw $this->notFound();
        }
        return $this->requireBatch($householdId, $batchId);
    }
    public function delete(AuthContext $context, $batchId)
    {
        $householdId = $this->guard->requireMembership($context);
        $this->requireBatch($householdId, $batchId);
        if (!$this->inventory->deleteBatch($householdId, $batchId)) {
            throw $this->notFound();
        }
        return ['deleted' => true];
    }
    /** @return array<string,mixed> */
    public function move(AuthContext $context, $batchId, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        $operation = isset($payload['operation']) ? $payload['operation'] : null;
        $unitCode = isset($payload['unit_code']) ? $payload['unit_code'] : null;
        $note = isset($payload['note']) ? $payload['note'] : null;
        $fields = [];
        if (!is_string($operation) || !in_array($operation, ['add', 'consume', 'set'], true)) {
            $fields['operation'] = 'Must be add, consume, or set.';
        }
        $quantity = $this->positiveQuantity(isset($payload['quantity']) ? $payload['quantity'] : null, 'quantity', $fields);
        if (!is_string($unitCode) || $this->recipes->unit($unitCode) === null) {
            $fields['unit_code'] = 'Unknown unit.';
        }
        if ($note !== null && (!is_string($note) || Text::length($note) > 255)) {
            $fields['note'] = 'Maximum length is 255 characters.';
        }
        $this->throwValidation($fields, 'Inventory movement is invalid.');
        return $this->transactions->transaction(function () use ($householdId, $context, $batchId, $operation, $quantity, $unitCode, $note) {
            $batch = \App\Support\Compat::valueOrThrow($this->inventory->findBatch($householdId, $batchId, true), $this->notFound());
            if (!$this->converter->compatible($batch['base_unit_code'], $unitCode)) {
                throw new ApiException(422, 'UNIT_INCOMPATIBLE', 'Movement unit is incompatible with the inventory batch.', ['unit_code' => 'Use a compatible unit.']);
            }
            $base = $this->converter->toBase($quantity, $unitCode);
            if ($operation === 'add') {
                $delta = $base['quantity'];
            } elseif ($operation === 'consume') {
                $delta = Quantity::negative($base['quantity']);
            } else {
                $delta = Quantity::subtract($base['quantity'], $batch['base_quantity']);
            }
            $result = Quantity::add($batch['base_quantity'], $delta);
            if (Quantity::compare($result, '0') < 0) {
                throw new ApiException(409, 'INSUFFICIENT_STOCK', 'Inventory stock cannot become negative.');
            }
            if (!$this->inventory->updateBatchQuantity($householdId, $batchId, $result)) {
                throw $this->notFound();
            }
            $this->inventory->appendMovement($householdId, $context->userId, ['batch_id' => $batchId, 'operation' => $operation, 'base_delta' => $delta, 'base_unit_code' => $batch['base_unit_code'], 'display_quantity' => $quantity, 'display_unit_code' => $unitCode, 'note' => $note]);
            return $this->requireBatch($householdId, $batchId);
        });
    }
    /** @return array{items:list<array<string,mixed>>,meta:array<string,int>} */
    public function movements(AuthContext $context, $page = 1, $pageSize = 20)
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if ($page < 1) {
            $fields['page'] = 'Must be at least 1.';
        }
        if ($pageSize < 1 || $pageSize > 50) {
            $fields['page_size'] = 'Must be between 1 and 50.';
        }
        $this->throwValidation($fields, 'Movement query is invalid.');
        $result = $this->inventory->listMovements($householdId, $pageSize, ($page - 1) * $pageSize);
        return ['items' => $result['items'], 'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $result['total'], 'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $pageSize)]];
    }
    /** @return array<string,mixed> */
    private function validateCreation(array $payload)
    {
        $fields = [];
        $ingredientId = isset($payload['ingredient_id']) ? $payload['ingredient_id'] : null;
        $ingredientName = isset($payload['ingredient_name']) ? $payload['ingredient_name'] : null;
        if (($ingredientId === null) === ($ingredientName === null) || $ingredientId !== null && (!is_int($ingredientId) || $ingredientId < 1)) {
            $fields['ingredient'] = 'Provide exactly one valid ingredient_id or ingredient_name.';
        }
        if ($ingredientName !== null && (!is_string($ingredientName) || IngredientNormalizer::normalize($ingredientName) === '' || Text::length(trim($ingredientName)) > 120)) {
            $fields['ingredient_name'] = 'Must be 1-120 characters.';
        }
        $quantity = $this->positiveQuantity(isset($payload['quantity']) ? $payload['quantity'] : null, 'quantity', $fields);
        $unitCode = isset($payload['unit_code']) ? $payload['unit_code'] : null;
        if (!is_string($unitCode) || $this->recipes->unit($unitCode) === null) {
            $fields['unit_code'] = 'Unknown unit.';
        }
        $expiry = isset($payload['expiry_date']) ? $payload['expiry_date'] : null;
        if (array_key_exists('expiry_date', $payload) && !$this->validDate($expiry)) {
            $fields['expiry_date'] = 'Must be YYYY-MM-DD or null.';
        }
        $note = isset($payload['note']) ? $payload['note'] : null;
        if ($note !== null && (!is_string($note) || Text::length($note) > 255)) {
            $fields['note'] = 'Maximum length is 255 characters.';
        }
        $this->throwValidation($fields, 'Inventory batch is invalid.');
        return ['ingredient_id' => $ingredientId, 'ingredient_name' => $ingredientName, 'unit_code' => $unitCode, 'quantity' => $quantity, 'expiry_date' => $expiry, 'note' => $note];
    }
    /** @param array<string,string> $fields */
    private function positiveQuantity($value, $field, array &$fields)
    {
        try {
            return DecimalQuantity::forStorage($value);
        } catch (\Exception $ignored) {
            $fields[$field] = 'Must fit DECIMAL(14,4), be positive, finite, and use at most 4 fractional digits.';
            return '0';
        }
    }
    private function validDate($value)
    {
        if ($value === null) {
            return true;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->clock->today()->getTimezone());
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    /** @param array<string,mixed> $batch */
    private function status(array $batch, $today, $through)
    {
        if (Quantity::compare($batch['base_quantity'], '0') <= 0) {
            return 'inactive';
        }
        if ($batch['expiry_date'] !== null && $batch['expiry_date'] < $today) {
            return 'expired';
        }
        if ($batch['expiry_date'] !== null && $batch['expiry_date'] <= $through) {
            return 'expiring';
        }
        return 'active';
    }
    /** @return array<string,mixed> */
    private function requireBatch($householdId, $batchId)
    {
        return \App\Support\Compat::valueOrThrow($this->inventory->findBatch($householdId, $batchId), $this->notFound());
    }
    private function notFound()
    {
        return new ApiException(404, 'INVENTORY_BATCH_NOT_FOUND', 'The inventory batch was not found.');
    }
    /** @param array<string,string> $fields */
    private function throwValidation(array $fields, $message)
    {
        if ($fields !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', $message, $fields);
        }
    }
}
