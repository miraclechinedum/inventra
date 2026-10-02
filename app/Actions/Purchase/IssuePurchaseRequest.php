<?php

namespace App\Actions\Purchase;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

class IssuePurchaseRequest
{
    public function __construct(private readonly CurrentBusiness $business) {}

    public function execute(User $actor, Store $session): string
    {
        $token = Str::random(64);
        $request = new PurchaseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->business_id = $this->business->forActor($actor)->getKey();
        $request->actor_id = $actor->id;
        $request->session_id = $session->getId();
        $request->expires_at = now()->addMinutes(30);
        $request->save();

        return $token;
    }
}
