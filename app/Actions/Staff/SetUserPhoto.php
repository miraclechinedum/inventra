<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Attaches, replaces or removes a staff profile photograph. Ordered the same way as the product
 * equivalent: write the new file, commit the new path, and only then delete the old file, so an
 * account never references an image that is not there.
 */
class SetUserPhoto
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly AuditLogger $audit,
    ) {}

    public function store(User $actor, User $subject, UploadedFile $file): User
    {
        $previous = $subject->photo_path;
        $path = $this->images->put($file, ImageStore::STAFF);

        try {
            DB::transaction(function () use ($subject, $path): void {
                $subject->photo_path = $path;
                $subject->save();
            });
        } catch (Throwable $exception) {
            $this->images->delete($path);

            throw $exception;
        }

        $this->audit->record(
            $previous === null ? 'user_photo_attached' : 'user_photo_replaced',
            $subject,
            $actor,
            oldValues: ['photo_path' => $previous],
            newValues: ['photo_path' => $path],
            explicitDiff: true,
        );

        if ($previous !== null && $previous !== $path) {
            $this->images->delete($previous);
        }

        return $subject;
    }

    public function remove(User $actor, User $subject): User
    {
        $previous = $subject->photo_path;

        if ($previous === null) {
            return $subject;
        }

        DB::transaction(function () use ($subject): void {
            $subject->photo_path = null;
            $subject->save();
        });

        $this->audit->record('user_photo_removed', $subject, $actor,
            oldValues: ['photo_path' => $previous],
            newValues: ['photo_path' => null],
            explicitDiff: true,
        );

        $this->images->delete($previous);

        return $subject;
    }
}
