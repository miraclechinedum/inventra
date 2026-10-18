<?php

namespace App\Actions\Sale;

use App\Models\SaleRequest;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

class IssueSaleRequest
{
    public function __construct(private readonly CurrentBusiness $business) {}

    /** Issues a single-use token for one Record Sale submission. Only its hash is stored. */
    public function execute(User $actor, Store $session): string
    {
        $token = Str::random(64);
        $request = new SaleRequest;
        $request->token_hash = hash('sha256', $token);
        $request->business_id = $this->business->forActor($actor)->getKey();
        $request->actor_id = $actor->id;
        $request->session_id = $session->getId();
        $request->expires_at = now()->addHours(SaleRequest::LIFETIME_HOURS);
        $request->save();

        return $token;
    }
}
