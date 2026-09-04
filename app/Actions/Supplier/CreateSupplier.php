<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SupplierCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSupplier
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, array $data): Supplier
    {
        return DB::transaction(function () use ($actor, $data) {
            $supplier = new Supplier;
            $supplier->supplier_code = 'PENDING-'.Str::random(20);
            $this->fillProfile($supplier, $data);
            $supplier->is_active = true;
            $supplier->created_by = $actor->id;
            $supplier->save();
            $supplier->supplier_code = SupplierCode::fromId($supplier->id);
            $supplier->save();
            $this->audit->record('supplier_created', $supplier, $actor, newValues: $supplier->getAttributes());

            return $supplier;
        });
    }

    private function fillProfile(Supplier $supplier, array $data): void
    {
        foreach (['name', 'contact_person', 'phone', 'email', 'address', 'city', 'notes'] as $field) {
            $supplier->$field = $data[$field] ?? null;
        }
    }
}
