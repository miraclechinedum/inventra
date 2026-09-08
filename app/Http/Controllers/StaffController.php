<?php

namespace App\Http\Controllers;

use App\Actions\Staff\ActivateStaff;
use App\Actions\Staff\ChangeStaffRole;
use App\Actions\Staff\CreateStaff;
use App\Actions\Staff\DeactivateStaff;
use App\Actions\Staff\LockStaff;
use App\Actions\Staff\RequireStaffPasswordChange;
use App\Actions\Staff\RevokeStaffSessions;
use App\Actions\Staff\UnlockStaff;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Staff\ChangeStaffRoleRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use App\Support\PerPage;
use App\Support\TableSort;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffController extends Controller
{
    /** Sort keys the staff list exposes, mapped to the real columns they may order by. */
    private const SORTABLE = [
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'role' => 'role',
        'status' => 'status',
        'last_login' => 'last_login_at',
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $searchInput = $request->query('search');
        $roleInput = $request->query('role');
        $statusInput = $request->query('status');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $escapedSearch = $this->escapeLikePrefix($search);
        $normalizedPhone = User::normalizePhone($search);
        $sort = TableSort::resolve($request, self::SORTABLE, 'name');
        $staff = User::query()
            ->with('creator:id,name')
            ->when($search !== '', function ($query) use ($escapedSearch, $normalizedPhone): void {
                $query->where(function ($query) use ($escapedSearch, $normalizedPhone): void {
                    $query->where('name', 'like', $escapedSearch.'%')
                        ->orWhere('email', 'like', mb_strtolower($escapedSearch).'%')
                        ->when($normalizedPhone, fn ($query) => $query->orWhere('phone', 'like', $normalizedPhone.'%'));
                });
            })
            ->when(is_string($roleInput) ? UserRole::tryFrom($roleInput) : null,
                fn ($query, UserRole $role) => $query->where('role', $role))
            ->when(is_string($statusInput) ? UserStatus::tryFrom($statusInput) : null,
                fn ($query, UserStatus $status) => $query->where('status', $status))
            ->orderBy($sort['columns'][0], $sort['direction'])
            // Names, emails and last-login timestamps all tie, so the primary key breaks the tie
            // and keeps page boundaries stable across requests.
            ->orderBy('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('staff.index', ['staff' => $staff, 'sort' => $sort]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('staff.create');
    }

    public function store(StoreStaffRequest $request, CreateStaff $action): View
    {
        $validated = $request->validated();

        try {
            $result = $action->execute(
                $request->user(),
                ['name' => $validated['name'], 'email' => $validated['email'], 'phone' => $validated['phone']],
                UserRole::from($validated['role']),
            );
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => 'An account with that email address or phone number already exists.',
            ]);
        }

        return view('staff.created', [
            'staffMember' => $result['user'],
            'temporaryPassword' => $result['temporary_password'],
        ]);
    }

    public function show(User $user): View
    {
        Gate::authorize('view', $user);

        return view('staff.show', ['staffMember' => $user->load('creator:id,name')]);
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('staff.edit', ['staffMember' => $user]);
    }

    public function update(
        UpdateStaffRequest $request,
        User $user,
        SecurityEventRecorder $events,
        AuditLogger $audit,
    ): RedirectResponse {
        $validated = $request->validated();
        $fields = ['name', 'email', 'phone'];
        $oldValues = $user->only($fields);
        $changedFields = collect($fields)
            ->filter(fn (string $field): bool => $user->{$field} !== $validated[$field])
            ->implode(',');
        $actor = $request->user();

        try {
            DB::transaction(function () use ($user, $validated, $fields, $oldValues, $changedFields, $actor, $events, $audit): void {
                $user->name = $validated['name'];
                $user->email = $validated['email'];
                $user->phone = $validated['phone'];
                $user->save();

                $events->record('staff_profile_updated', $user, ['changed_fields' => $changedFields], $actor);
                $audit->record('staff_profile_updated', $user, $actor, oldValues: $oldValues, newValues: $user->only($fields));
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => 'An account with that email address or phone number already exists.',
            ]);
        }

        return redirect()->route('staff.show', $user)->with('status', 'Staff profile updated.');
    }

    public function changeRole(
        ChangeStaffRoleRequest $request,
        User $user,
        ChangeStaffRole $action,
    ): RedirectResponse {
        $action->execute($request->user(), $user, UserRole::from($request->validated('role')));

        return back()->with('status', 'Staff role updated and active sessions revoked.');
    }

    public function activate(Request $request, User $user, ActivateStaff $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $user);
        abort_unless($user->status === UserStatus::Inactive, 403);
        $action->execute($request->user(), $user);

        return back()->with('status', 'Staff account activated.');
    }

    public function deactivate(Request $request, User $user, DeactivateStaff $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $user);
        abort_unless($user->status === UserStatus::Active, 403);
        $action->execute($request->user(), $user);

        return back()->with('status', 'Staff account deactivated and sessions revoked.');
    }

    public function lock(Request $request, User $user, LockStaff $action): RedirectResponse
    {
        Gate::authorize('lock', $user);
        abort_unless($user->status === UserStatus::Active, 403);
        $action->execute($request->user(), $user);

        return back()->with('status', 'Staff account manually locked and sessions revoked.');
    }

    public function unlock(Request $request, User $user, UnlockStaff $action): RedirectResponse
    {
        Gate::authorize('unlock', $user);
        $action->execute($request->user(), $user);

        return back()->with('status', 'Staff account unlocked and temporary failure state cleared.');
    }

    public function requirePasswordChange(
        Request $request,
        User $user,
        RequireStaffPasswordChange $action,
    ): RedirectResponse {
        Gate::authorize('requirePasswordChange', $user);
        $action->execute($request->user(), $user);

        return back()->with('status', 'Password change required and sessions revoked.');
    }

    public function revokeSessions(
        Request $request,
        User $user,
        RevokeStaffSessions $action,
    ): RedirectResponse {
        Gate::authorize('revokeSessions', $user);
        $action->execute($request->user(), $user);

        return back()->with('status', 'All staff sessions revoked.');
    }

    public function activity(Request $request, User $user): View
    {
        Gate::authorize('viewActivity', $user);
        $events = $user->securityEvents()
            ->with('actor:id,name')
            ->latest('created_at')
            ->latest('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('staff.activity', ['staffMember' => $user, 'events' => $events]);
    }

    private function escapeLikePrefix(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
