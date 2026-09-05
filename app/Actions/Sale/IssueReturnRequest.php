<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\SaleReturnRequest;
use App\Models\User;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

class IssueReturnRequest
{
    public function execute(User $actor, Sale $sale, Store $session): string
    {
        $token = Str::random(64);
        $row = new SaleReturnRequest;
        $row->token_hash = hash('sha256', $token);
        $row->sale_id = $sale->id;
        $row->actor_id = $actor->id;
        $row->session_id = $session->getId();
        $row->expires_at = now()->addMinutes(30);
        $row->save();

        return $token;
    }
}
