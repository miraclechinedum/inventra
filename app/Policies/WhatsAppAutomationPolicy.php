<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppMessage;
use App\Policies\Concerns\DeniesOtherBusinesses;

/**
 * WhatsApp automation is Administrator-only, in every direction.
 *
 * Configuring it means deciding what Inventra says to customers automatically, and reading the logs
 * means reading those conversations, so both are held to the same bar. This is the server-side
 * gate; the sidebar hiding the link is presentation, never protection.
 */
class WhatsAppAutomationPolicy
{
    // Denies any automation or message of another Business before a role rule is consulted.
    use DeniesOtherBusinesses;

    public function viewAny(User $actor): bool
    {
        return $actor->role === UserRole::Admin;
    }

    /**
     * Reading the message log, without any power to change it.
     *
     * Deliberately a separate ability from viewAny rather than a widening of it: viewAny governs the
     * Automation module — the connection, the templates, the test send — and a Manager has no
     * business there. This grants the read-only log and nothing else, so every mutating ability
     * below stays Administrator-only and a Manager reaching a retry URL still receives 403.
     */
    public function viewLogs(User $actor): bool
    {
        return in_array($actor->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function configure(User $actor, ?WhatsAppAutomation $automation = null): bool
    {
        return $actor->role === UserRole::Admin;
    }

    public function connect(User $actor): bool
    {
        return $actor->role === UserRole::Admin;
    }

    public function sendTest(User $actor, ?WhatsAppAutomation $automation = null): bool
    {
        return $actor->role === UserRole::Admin;
    }

    public function retry(User $actor, ?WhatsAppMessage $message = null): bool
    {
        return $actor->role === UserRole::Admin;
    }
}
