<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Attaches, replaces or removes a product photograph.
 *
 * Replacement is ordered deliberately: the new file is written first, the database is pointed at it
 * inside a transaction, and only then is the old file deleted. If any step fails the product still
 * references a file that exists, so a product is never left pointing at a missing image.
 */
class SetProductImage
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly AuditLogger $audit,
    ) {}

    public function store(User $actor, Product $product, UploadedFile $file): Product
    {
        $previous = $product->image_path;
        $path = $this->images->put($file, ImageStore::PRODUCTS);

        try {
            DB::transaction(function () use ($product, $path, $actor): void {
                $product->image_path = $path;
                $product->updated_by = $actor->id;
                $product->save();
            });
        } catch (\Throwable $exception) {
            // The database kept the old path, so discard the file nothing refers to.
            $this->images->delete($path);

            throw $exception;
        }

        $this->audit->record(
            $previous === null ? 'product_image_attached' : 'product_image_replaced',
            $product,
            $actor,
            oldValues: ['image_path' => $previous],
            newValues: ['image_path' => $path],
            explicitDiff: true,
        );

        // Only now that the new path is committed is the superseded file removed.
        if ($previous !== null && $previous !== $path) {
            $this->images->delete($previous);
        }

        return $product;
    }

    public function remove(User $actor, Product $product): Product
    {
        $previous = $product->image_path;

        if ($previous === null) {
            return $product;
        }

        DB::transaction(function () use ($product, $actor): void {
            $product->image_path = null;
            $product->updated_by = $actor->id;
            $product->save();
        });

        $this->audit->record('product_image_removed', $product, $actor,
            oldValues: ['image_path' => $previous],
            newValues: ['image_path' => null],
            explicitDiff: true,
        );

        $this->images->delete($previous);

        return $product;
    }
}
