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
