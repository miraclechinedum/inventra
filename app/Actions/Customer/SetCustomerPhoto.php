<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Attaches, replaces or removes a customer photograph.
 *
 * The same shape as SetProductImage, deliberately: one upload architecture, one private disk, one
 * set of validation rules. A customer photo is not more or less sensitive than a product one, and
 * two implementations would eventually accept different things.
 *
 * Replacement is ordered so the record is never left pointing at a file that is not there: the new
 * file is written first, the database is pointed at it inside a transaction, and only then is the
 * superseded file deleted. If the write fails, the customer still references the old file.
 *
 * Only the path is stored. Image bytes never go near the database.
 */
class SetCustomerPhoto
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly AuditLogger $audit,
    ) {}

    public function store(User $actor, Customer $customer, UploadedFile $file): Customer
    {
        $previous = $customer->photo_path;
        $path = $this->images->put($file, ImageStore::CUSTOMERS);

        try {
            DB::transaction(function () use ($customer, $path, $actor): void {
                $customer->photo_path = $path;
                $customer->updated_by = $actor->id;
                $customer->save();
            });
        } catch (Throwable $exception) {
            // The database kept the old path, so discard the file nothing refers to.
            $this->images->delete($path);

            throw $exception;
        }

        $this->audit->record(
            $previous === null ? 'customer_photo_attached' : 'customer_photo_replaced',
            $customer,
            $actor,
            oldValues: ['photo_path' => $previous],
            newValues: ['photo_path' => $path],
            explicitDiff: true,
        );

        // Only now that the new path is committed is the superseded file removed.
        if ($previous !== null && $previous !== $path) {
            $this->images->delete($previous);
        }

        return $customer;
    }

    /**
     * Removes the photograph.
     *
     * Only ever called because someone asked for it. A form submitted without a file means "leave
     * the photo alone", never "delete it" — losing a photo by omission would be a nasty surprise.
     */
    public function remove(User $actor, Customer $customer): Customer
    {
        $previous = $customer->photo_path;

        if ($previous === null) {
            return $customer;
        }

        DB::transaction(function () use ($customer, $actor): void {
            $customer->photo_path = null;
            $customer->updated_by = $actor->id;
            $customer->save();
        });

        $this->audit->record('customer_photo_removed', $customer, $actor,
            oldValues: ['photo_path' => $previous],
            newValues: ['photo_path' => null],
            explicitDiff: true,
        );

        $this->images->delete($previous);

        return $customer;
    }
}
