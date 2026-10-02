<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\ProductUnit;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Support\ImageStore;
use App\Tenancy\CurrentBusiness;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateProduct
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ImageStore $images,
        private readonly CurrentBusiness $currentBusiness,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Creates a product, its opening movement and — optionally — its photograph.
     *
     * A filesystem write cannot participate in a database transaction, so the ordering is
     * deliberate: the file is written first, its server-generated path is committed alongside the
     * product, and the file is deleted again if the transaction fails for any reason. A product is
     * therefore never committed pointing at a file that is not there, and a rolled-back creation
     * never leaves a file nothing refers to.
     */
    public function execute(User $actor, array $data, ?UploadedFile $image = null): Product
    {
        $business = $this->currentBusiness->forActor($actor);
        $imagePath = $image !== null ? $this->images->put($image, ImageStore::PRODUCTS) : null;

        try {
            return DB::transaction(function () use ($actor, $data, $imagePath, $business): Product {
                // The plan's product allowance, serialised on the Business's subscription row lock.
                $this->entitlements->claim($business, Entitlement::MaxProducts);

                // Scoped to the Business, so another tenant's category reads as absent.
                if (! ProductCategory::query()->whereKey($data['category_id'])->exists()) {
                    throw ValidationException::withMessages(['category_id' => 'The selected category is invalid.']);
                }

                $product = new Product;
                $product->business_id = $business->getKey();
                $product->category_id = $data['category_id'];
                $product->name = $data['name'];
                $product->sku = $data['sku'];
                $product->description = $data['description'] ?? null;
                $product->cost_price = $data['cost_price'];
                $product->selling_price = $data['selling_price'];
                $product->current_stock = $data['initial_stock'];
                $product->reorder_level = $data['reorder_level'];
                $product->unit = ProductUnit::from($data['unit']);
                $product->image_path = $imagePath;
                $product->is_active = true;
                $product->created_by = $actor->id;
                $product->save();

                $movement = new InventoryMovement;
                $movement->business_id = $product->business_id;
                $movement->product_id = $product->id;
                $movement->type = InventoryMovementType::Initial;
                $movement->quantity_change = $data['initial_stock'];
                $movement->quantity_before = '0';
                $movement->quantity_after = $data['initial_stock'];
                $movement->reason = 'Initial stock';
                $movement->performed_by = $actor->id;
                $movement->save();

                $this->audit->record('product_created', $product, $actor, newValues: $product->getAttributes());

                if ($imagePath !== null) {
                    $this->audit->record('product_image_attached', $product, $actor,
                        oldValues: ['image_path' => null],
                        newValues: ['image_path' => $imagePath],
                        explicitDiff: true,
                    );
                }

                return $product;
            });
        } catch (Throwable $exception) {
            // Nothing was committed, so discard the file no product refers to.
            $this->images->delete($imagePath);

            throw $exception;
        }
    }
}
