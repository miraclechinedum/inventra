<?php

namespace App\Http\Controllers;

use App\Actions\Staff\SetUserPhoto;
use App\Http\Requests\ProfilePhotoRequest;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Self-service profile. Every signed-in staff member reaches this, which is what gives Managers and
 * Sales Reps a profile photograph without an Admin having to do it for them — the staff pages
 * remain Admin-only.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function storePhoto(ProfilePhotoRequest $request, SetUserPhoto $action): RedirectResponse
    {
        $user = $request->user();

        try {
            $action->store($user, $user, $request->file('photo'));
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['photo' => 'The image could not be read or is not a supported type.']);
        }

        return back()->with('status', 'Profile photo saved.');
    }

    public function destroyPhoto(Request $request, SetUserPhoto $action): RedirectResponse
    {
        $user = $request->user();
        Gate::authorize('removePhoto', $user);
        $action->remove($user, $user);

        return back()->with('status', 'Profile photo removed.');
    }

    /**
     * Streams a staff photograph from the private disk. Authorization is the only gate — the file
     * is not web-reachable — so no storage:link or signed URL is needed for an <img src> to work.
     */
    public function photo(User $user, ImageStore $images): BinaryFileResponse
    {
        Gate::authorize('viewPhoto', $user);
        abort_unless($images->exists($user->photo_path), 404);

        $response = response()->file($images->absolutePath($user->photo_path), [
            'Content-Type' => $images->typeOf($user->photo_path),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // A photograph of a person is never publicly cacheable. setPrivate() is used rather than a
        // Cache-Control string because Symfony rewrites a hand-written max-age header into `public`.
        $response->setPrivate();
        $response->setMaxAge(600);
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }
}
