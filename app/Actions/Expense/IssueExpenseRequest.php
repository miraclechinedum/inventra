<?php

namespace App\Actions\Expense;

use App\Models\ExpenseRequest;
use App\Models\User;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

class IssueExpenseRequest
{
    public function execute(User $actor, Store $session): string
    {
        $token = Str::random(64);
        $request = new ExpenseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $actor->id;
        $request->session_id = $session->getId();
        $request->expires_at = now()->addMinutes(30);
        $request->save();

        return $token;
    }
}
