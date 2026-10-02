<?php

use App\Models\ExpenseRequest;
use App\Models\PurchaseRequest;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\SecurityEvent;
use App\Models\WhatsAppOnboardingAttempt;
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

Schedule::command('model:prune', ['--model' => WhatsAppOnboardingAttempt::class])
    ->dailyAt('02:55')
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

// WhatsApp automation messages. The automations only persist a queued message; the provider is
// called here, on the scheduler, so a provider outage can never sit inside a business transaction.
// Every minute, because a greeting or a purchase thank-you is worth little once the moment has
// passed, and the shared-hosting cron already runs the scheduler once a minute. This adds no queue
// worker and no supervisor: one short command that exits immediately when nothing is due or
// WhatsApp is not connected. Duplicate sending is prevented in the database by the
// dispatch_claimed_at claim, so withoutOverlapping here is a courtesy, not the safety mechanism.
Schedule::command('inventra:dispatch-whatsapp-messages')
    ->everyMinute()
    ->withoutOverlapping();

// Records trial, grace and suspension transitions and their audit rows. Access itself is decided
// from the stored dates at read time, so this is a bookkeeping pass, not the gate.
Schedule::command('inventra:advance-subscriptions')
    ->hourly()
    ->withoutOverlapping();
