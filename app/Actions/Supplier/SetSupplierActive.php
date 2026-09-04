<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class SetSupplierActive
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, Supplier $supplier, bool $active): void
    {
        DB::transaction(function () use ($actor, $supplier, $active) {
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if ($locked->is_active === $active) {
                return;
            }

            $locked->is_active = $active;
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->audit->record($active ? 'supplier_activated' : 'supplier_deactivated', $locked, $actor, newValues: ['is_active' => $active]);
        });
    }
}
