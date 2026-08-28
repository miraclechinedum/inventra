<?php

namespace App\Policies;

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppDelivery;

class WhatsAppDeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function view(User $user, WhatsAppDelivery $delivery): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $delivery->sale->sold_by === $user->id);
    }

    public function sendReceipt(User $user, Sale $sale): bool
    {
        return $sale->status === SaleStatus::Completed
            && (in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
                || ($user->role === UserRole::SalesRep && $sale->sold_by === $user->id));
    }

    public function retry(User $user, WhatsAppDelivery $delivery): bool
    {
        return $delivery->status === WhatsAppDeliveryStatus::Failed
            && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function resolveUnknown(User $user, WhatsAppDelivery $delivery): bool
    {
        return $user->role === UserRole::Admin
            && $delivery->status === WhatsAppDeliveryStatus::Pending
            && $delivery->failure_code === 'outcome_unknown'
            && $delivery->provider_message_id === null;
    }
}
