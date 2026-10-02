<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\SalePaymentRequest;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

class IssueSalePaymentRequest
{
    public function __construct(private readonly CurrentBusiness $business) {}

    public function execute(Sale $sale, User $actor, Store $session): string
    {
        $token = Str::random(64);
        $request = new SalePaymentRequest;
        $request->token_hash = hash('sha256', $token);
        $request->business_id = $this->business->forActor($actor)->getKey();
        $request->sale_id = $sale->id;
        $request->actor_id = $actor->id;
        $request->session_id = $session->getId();
        $request->expires_at = now()->addMinutes(30);
        $request->save();

        return $token;
    }
}
