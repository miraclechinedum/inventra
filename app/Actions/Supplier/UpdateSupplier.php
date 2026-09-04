<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateSupplier
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, Supplier $supplier, array $data): Supplier
    {
        Gate::forUser($actor)->authorize('update', $supplier);

        return DB::transaction(function () use ($actor, $supplier, $data) {
            $lockedSupplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $oldValues = $lockedSupplier->getAttributes();
            foreach (['name', 'contact_person', 'phone', 'email', 'address', 'city', 'notes'] as $field) {
                $lockedSupplier->$field = $data[$field] ?? null;
            }

            if (! $lockedSupplier->isDirty()) {
                return $lockedSupplier;
            }

            $lockedSupplier->updated_by = $actor->id;
            $lockedSupplier->save();
            $this->audit->record('supplier_updated', $lockedSupplier, $actor, oldValues: $oldValues, newValues: $lockedSupplier->getAttributes());

            return $lockedSupplier;
        });
    }
}
