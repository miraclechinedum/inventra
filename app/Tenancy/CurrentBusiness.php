<?php

namespace App\Tenancy;

use App\Models\Business;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * The Business the current unit of work acts for.
 *
 * Bound as a scoped container instance, never static state: Laravel discards scoped instances
 * between queue jobs and long-lived-worker requests, and ResolveCurrentBusiness clears it when a
 * request terminates, so one tenant's context cannot outlive the work it was set for.
 *
 * It is only ever set from something authoritative — the authenticated user's persisted Business
 * in the web middleware, or a Business a console command or test names explicitly. It is never
 * inferred from request input, and it never falls back to "the first" Business: reading it when
 * nothing has been set is an error, not a default.
 */
class CurrentBusiness
{
    private ?Business $business = null;

    /** @var list<Business|null> contexts suspended by enter(), restored by leave() */
    private array $suspended = [];

    public function set(Business $business): void
    {
        $this->business = $business;
    }

    public function forget(): void
    {
        $this->business = null;
    }

    public function has(): bool
    {
        return $this->business !== null;
    }

    public function get(): Business
    {
        if ($this->business === null) {
            throw new BusinessContextException('No business is in context for this operation.');
        }

        // A signed-in user can only ever act for their own business. A context that disagrees with
        // them was left behind by other work and must not be trusted.
        $user = Auth::user();

        if ($user instanceof User && (int) $user->business_id !== (int) $this->business->getKey()) {
            throw new BusinessContextException('The business in context does not match the signed-in user.');
        }

        return $this->business;
    }

    public function id(): int
    {
        return (int) $this->get()->getKey();
    }

    /**
     * The active Business an actor mutates on behalf of.
     *
     * The actor's own persisted Business is authoritative. When a context is set it must agree,
     * which is how a mismatched context fails closed instead of redirecting a write elsewhere.
     */
    public function forActor(User $actor): Business
    {
        $business = $actor->business;

        if ($business === null) {
            throw new BusinessContextException('This account does not belong to a business.');
        }

        if (! $business->isActive()) {
            throw new BusinessContextException('This business is not active.');
        }

        if ($this->business !== null && (int) $this->business->getKey() !== (int) $business->getKey()) {
            throw new BusinessContextException('The business in context does not match the acting user.');
        }

        return $business;
    }

    /**
     * Makes a Business current until the matching leave(), suspending whatever was current before.
     * Used by the request middleware, whose context must end with the request.
     */
    public function enter(Business $business): void
    {
        $this->suspended[] = $this->business;
        $this->business = $business;
    }

    /**
     * Suspends whatever is current, leaving no context until the matching leave(). A request starts
     * this way, so nothing a previous unit of work left behind is visible while it authenticates.
     */
    public function suspend(): void
    {
        $this->suspended[] = $this->business;
        $this->business = null;
    }

    /** Restores the context that the matching enter() or suspend() replaced — in production, none. */
    public function leave(): void
    {
        $this->business = array_pop($this->suspended);
    }

    /**
     * Runs work for an explicitly named Business — the console and test entry point — and restores
     * whatever was in context before, even when the work throws.
     *
     * @template TResult
     *
     * @param  Closure(Business): TResult  $callback
     * @return TResult
     */
    public function run(Business $business, Closure $callback): mixed
    {
        $this->enter($business);

        try {
            return $callback($business);
        } finally {
            $this->leave();
        }
    }
}
