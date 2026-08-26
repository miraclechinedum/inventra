<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordOnboardingController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\QuickPinOnboardingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('active')->group(function () {
        Route::get('/onboarding/password', [PasswordOnboardingController::class, 'edit'])
            ->name('onboarding.password.edit');
        Route::post('/onboarding/password', [PasswordOnboardingController::class, 'update'])
            ->name('onboarding.password.update');

        Route::middleware('password.changed')->group(function () {
            Route::get('/onboarding/pin', [QuickPinOnboardingController::class, 'edit'])
                ->name('onboarding.pin.edit');
            Route::post('/onboarding/pin', [QuickPinOnboardingController::class, 'store'])
                ->middleware('throttle:pin-setup')
                ->name('onboarding.pin.store');
            Route::post('/onboarding/pin/skip', [QuickPinOnboardingController::class, 'skip'])
                ->name('onboarding.pin.skip');

            Route::get('/dashboard', DashboardController::class)
                ->middleware('pin.completed')
                ->name('dashboard');

            Route::middleware('pin.completed')->prefix('inventory')->name('inventory.')->group(function () {
                Route::get('/', [ProductController::class, 'index'])->name('index');
                Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
                Route::post('/products', [ProductController::class, 'store'])->name('products.store');
                Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
                Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
                Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
                Route::post('/products/{product}/adjust-stock', [ProductController::class, 'adjust'])->name('products.adjust');
                Route::post('/products/{product}/activate', [ProductController::class, 'activate'])->name('products.activate');
                Route::post('/products/{product}/deactivate', [ProductController::class, 'deactivate'])->name('products.deactivate');
                Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
                Route::get('/products/{product}/movements', [ProductController::class, 'movements'])->name('products.movements');

                Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
                Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
                Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
                Route::post('/categories/{category}/activate', [CategoryController::class, 'activate'])->name('categories.activate');
                Route::post('/categories/{category}/deactivate', [CategoryController::class, 'deactivate'])->name('categories.deactivate');
            });

            Route::middleware('pin.completed')->prefix('customers')->name('customers.')->group(function () {
                Route::get('/', [CustomerController::class, 'index'])->name('index');
                Route::get('/create', [CustomerController::class, 'create'])->name('create');
                Route::post('/', [CustomerController::class, 'store'])->name('store');
                Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
                Route::get('/{customer}/edit', [CustomerController::class, 'edit'])->name('edit');
                Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');
                Route::post('/{customer}/activate', [CustomerController::class, 'activate'])->name('activate');
                Route::post('/{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('deactivate');
                Route::post('/{customer}/whatsapp-consent', [CustomerController::class, 'consent'])->name('consent');
                Route::get('/{customer}/activity', [CustomerController::class, 'activity'])->name('activity');
            });

            Route::middleware(['pin.completed', 'role:admin'])->group(function () {
                Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
                Route::get('/staff/create', [StaffController::class, 'create'])->name('staff.create');
                Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
                Route::get('/staff/{user}', [StaffController::class, 'show'])->name('staff.show');
                Route::get('/staff/{user}/edit', [StaffController::class, 'edit'])->name('staff.edit');
                Route::put('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
                Route::post('/staff/{user}/role', [StaffController::class, 'changeRole'])->name('staff.role');
                Route::post('/staff/{user}/activate', [StaffController::class, 'activate'])->name('staff.activate');
                Route::post('/staff/{user}/deactivate', [StaffController::class, 'deactivate'])->name('staff.deactivate');
                Route::post('/staff/{user}/lock', [StaffController::class, 'lock'])->name('staff.lock');
                Route::post('/staff/{user}/unlock', [StaffController::class, 'unlock'])->name('staff.unlock');
                Route::post('/staff/{user}/require-password-change', [StaffController::class, 'requirePasswordChange'])
                    ->name('staff.require-password-change');
                Route::post('/staff/{user}/revoke-sessions', [StaffController::class, 'revokeSessions'])
                    ->name('staff.revoke-sessions');
                Route::get('/staff/{user}/activity', [StaffController::class, 'activity'])->name('staff.activity');
            });
        });
    });
});
