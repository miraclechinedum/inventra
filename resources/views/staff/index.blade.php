{{--
    Staff & roles, rebuilt to the Figma.

    Three places where the design and the system differ, and what was done:

    - The Figma shows an "Invited" status. Inventra has no invitation lifecycle: `UserStatus` is
      Active/Inactive/Locked and an account exists only once an Administrator has created it with a
      password. The real three are rendered rather than a fourth that nothing could ever set.
    - The Figma's role chips read "Sales Rep". `UserRole::SalesRep` labels itself "Sales
      Representative" everywhere else in Inventra, so the chip carries the real label and the
      Figma's short form is used only where the column is too narrow for it.
    - The ellipsis menu exposes the actions that already exist — Edit, Revoke access, Restore
      access — each behind the policy that already governs it. No new backend action was added and
      no existing gate was relaxed: an entry the policy refuses is absent from the DOM entirely.

    The filter strip, sorting and pagination are the existing ones; only the table's presentation
    changed.
--}}
<x-app-layout title="Staff & roles">
    <div class="sr-page">
        <div class="sr-head">
            <a href="{{ route('profile.edit') }}" class="sr-back" aria-label="Back to my profile">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <div class="sr-head-body">
                <h1>Staff &amp; roles</h1>
                {{-- The real total, not the page count: the Figma's subtitle reports the team. --}}
                <p>{{ $memberCount }} {{ \Illuminate\Support\Str::plural('member', $memberCount) }}</p>
            </div>

            @can('create', \App\Models\User::class)
                <a href="{{ route('staff.create') }}" class="inventra-primary-action sr-add">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Add member
                </a>
            @endcan
        </div>

        {{-- The existing filter strip, unchanged in behaviour. --}}
        <form method="GET" class="sr-filters">
            <input aria-label="Search staff" name="search" value="{{ is_string(request('search')) ? request('search') : '' }}" placeholder="Name, email, or phone">
            <select aria-label="Role" name="role" data-table-control>
                <option value="">All roles</option>
                @foreach (\App\Enums\UserRole::cases() as $role)
                    <option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
            <select aria-label="Status" name="status" data-table-control>
                <option value="">All statuses</option>
                @foreach (\App\Enums\UserStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ ucfirst($status->value) }}</option>
                @endforeach
            </select>
            <button type="submit">Apply</button>
            <x-filter-reset />
        </form>

        <div class="sr-table-wrap">
            <table class="sr-table">
                <thead>
                    <tr>
                        {{-- S/N is the shared record-list standard every Inventra listing carries,
                             and it counts through the whole filtered result set rather than
                             restarting each page. The Figma omits it; dropping it here would make
                             this the one table that numbers its rows differently from the rest. --}}
                        <th class="ui-sn">S/N</th>
                        <x-sort-header key="name" label="Member" :active="$sort['key']" :direction="$sort['direction']" />
                        <x-sort-header key="role" label="Role" :active="$sort['key']" :direction="$sort['direction']" />
                        <x-sort-header key="status" label="Status" :active="$sort['key']" :direction="$sort['direction']" />
                        <th><span class="sr-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($staff as $member)
                        @php($isSelf = $member->is(auth()->user()))
                        @php($initials = collect(explode(' ', $member->name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''))
                        {{-- The menu's entries: computed once, so the trigger is rendered only when
                             at least one action is actually permitted. --}}
                        @php($canEdit = auth()->user()->can('update', $member))
                        @php($canRevoke = auth()->user()->can('changeStatus', $member) && $member->status === \App\Enums\UserStatus::Active)
                        @php($canRestore = auth()->user()->can('changeStatus', $member) && $member->status === \App\Enums\UserStatus::Inactive)

                        <tr @class(['is-inactive' => $member->status !== \App\Enums\UserStatus::Active])>
                            <td class="ui-sn">{{ $staff->firstItem() + $loop->index }}</td>
                            <td class="sr-member-cell">
                                <span class="sr-member">
                                    <span class="sr-avatar" aria-hidden="true">
                                        @if ($member->photo_path)
                                            <img src="{{ route('users.photo', $member) }}" alt="" width="38" height="38" loading="lazy">
                                        @else
                                            {{ $initials }}
                                        @endif
                                    </span>
                                    <span class="sr-member-body">
                                        {{-- The signed-in account is marked, as the Figma does. --}}
                                        <strong>{{ $member->name }}@if ($isSelf)<span class="sr-you">(You)</span>@endif</strong>
                                        <small>{{ $member->email }}</small>
                                    </span>
                                </span>
                            </td>

                            <td>
                                {{-- The real role. Inventra has no ownership concept to report. --}}
                                <span class="sr-role" data-role="{{ $member->role->value }}">{{ $member->role->label() }}</span>
                            </td>

                            <td>
                                <span class="sr-status" data-status="{{ $member->status->value }}">
                                    <span class="sr-dot" aria-hidden="true"></span>{{ ucfirst($member->status->value) }}
                                </span>
                            </td>

                            <td class="sr-actions-cell">
                                @if ($canEdit || $canRevoke || $canRestore)
                                    <span class="sr-menu" x-data="staffRowMenu('{{ $member->id }}')"
                                          x-on:keydown.escape.window="close" x-on:click.outside="close"
                                          x-on:open-staff-menu.window="standDown">
                                        <button type="button" class="sr-menu-trigger" x-ref="trigger"
                                                x-on:click="toggle" x-bind:aria-expanded="open ? 'true' : 'false'"
                                                aria-haspopup="menu" aria-controls="sr-menu-{{ $member->id }}"
                                                aria-label="Actions for {{ $member->name }}">
                                            <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg>
                                        </button>

                                        <span class="sr-menu-list" id="sr-menu-{{ $member->id }}" role="menu" x-cloak x-show="open">
                                            @if ($canEdit)
                                                <a href="{{ route('staff.edit', $member) }}" role="menuitem" class="sr-menu-item" x-on:click="close">
                                                    <svg viewBox="0 0 16 16" width="15" height="15" fill="none" aria-hidden="true"><path d="M11.3 2.7a1.4 1.4 0 0 1 2 2L5.6 12.4l-2.6.6.6-2.6 7.7-7.7Z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                    Edit
                                                </a>
                                            @endif

                                            {{-- The confirmation's own trigger carries `role="menuitem"`, so the
                                                 focusable control and the menu entry are the same element. Wrapping a
                                                 button in a `role="menuitem"` span would announce an entry that
                                                 keyboard focus could never land on. Both use the existing dialog, the
                                                 existing copy and the existing route. --}}
                                            @if ($canRevoke)
                                                <x-lifecycle-confirm :action="route('staff.deactivate', $member)" tone="danger"
                                                    label="Revoke access" title="Remove {{ $member->name }}'s access?"
                                                    trigger-label="Revoke access" confirm-label="Remove access"
                                                    message="They'll be signed out immediately. Sales they recorded are kept."
                                                    menuitem class="sr-menu-item is-danger">
                                                    <x-slot:icon><svg viewBox="0 0 16 16" width="15" height="15" fill="none" aria-hidden="true"><path d="M10.5 13.5v-1a2.5 2.5 0 0 0-2.5-2.5H4a2.5 2.5 0 0 0-2.5 2.5v1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6" cy="5" r="2.5" stroke="currentColor" stroke-width="1.3"/><path d="M11.5 6.5h3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></x-slot:icon>
                                                </x-lifecycle-confirm>
                                            @endif

                                            @if ($canRestore)
                                                <x-lifecycle-confirm :action="route('staff.activate', $member)" tone="primary"
                                                    label="Restore access" title="Restore {{ $member->name }}'s access?"
                                                    trigger-label="Restore access" confirm-label="Restore access"
                                                    message="They'll be able to sign in again. Any password-change requirement stays in place."
                                                    menuitem class="sr-menu-item">
                                                    <x-slot:icon><svg viewBox="0 0 16 16" width="15" height="15" fill="none" aria-hidden="true"><path d="M10.5 13.5v-1a2.5 2.5 0 0 0-2.5-2.5H4a2.5 2.5 0 0 0-2.5 2.5v1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6" cy="5" r="2.5" stroke="currentColor" stroke-width="1.3"/><path d="M13 3.5v4M11 5.5h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></x-slot:icon>
                                                </x-lifecycle-confirm>
                                            @endif
                                        </span>
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="sr-empty">
                                <x-empty-state title="No staff accounts match these filters." description="Try another search or adjust your filters." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-table-footer :paginator="$staff" noun="staff account" />
    </div>
</x-app-layout>
