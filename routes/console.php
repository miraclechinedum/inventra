<?php

use App\Models\ExpenseRequest;
use App\Models\PurchaseRequest;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune', ['--model' => SecurityEvent::class])
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => PurchaseRequest::class])
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => ExpenseRequest::class])
    ->dailyAt('02:30')
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => SaleReturnRequest::class])
    ->dailyAt('02:45')
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => SaleRefundRequest::class])
    ->dailyAt('02:50')
    ->withoutOverlapping();

// Operational alerts are projected immediately after the domain writes that cause them, so this
// sweep is a repair pass rather than the primary generator. Hourly is chosen because ledger
// integrity is the one condition with no mutation to hang off — nothing saves a Sale when a Sale
// fails to save what it claimed — so an hour bounds how long a mismatch can sit undetected, while
// staying light enough for shared hosting. withoutOverlapping uses the database cache store already
// configured here; no Redis, worker or supervisor is involved.
Schedule::command('inventra:reconcile-operational-alerts')
    ->hourly()
    ->withoutOverlapping();
