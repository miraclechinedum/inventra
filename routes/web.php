<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordOnboardingController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\QuickPinOnboardingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalePaymentController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WhatsAppDeliveryController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
    ->middleware(['whatsapp.webhook.size', 'throttle:whatsapp-webhook'])
    ->name('webhooks.whatsapp.handle');

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

            Route::middleware('pin.completed')->prefix('sales')->name('sales.')->group(function () {
                Route::get('/', [SaleController::class, 'index'])->name('index');
                Route::get('/create', [SaleController::class, 'create'])->name('create');
                Route::post('/', [SaleController::class, 'store'])->name('store');
                Route::get('/{sale}', [SaleController::class, 'show'])->name('show');
                Route::get('/{sale}/receipt', [SaleController::class, 'receipt'])->name('receipt');
                Route::post('/{sale}/payments', [SalePaymentController::class, 'store'])->name('payments.store');
                Route::get('/{sale}/payments/{payment}', [SalePaymentController::class, 'show'])->name('payments.show');
                Route::get('/{sale}/payments/{payment}/receipt', [SalePaymentController::class, 'receipt'])->name('payments.receipt');
                Route::post('/{sale}/void', [SaleController::class, 'void'])->name('void');
                Route::get('/{sale}/activity', [SaleController::class, 'activity'])->name('activity');
                Route::post('/{sale}/whatsapp/send', [WhatsAppDeliveryController::class, 'send'])->name('whatsapp.send');
                Route::post('/{sale}/whatsapp/retry/{delivery}', [WhatsAppDeliveryController::class, 'retry'])->name('whatsapp.retry');
            });

            Route::middleware('pin.completed')->get('/sale-payments', [SalePaymentController::class, 'index'])
                ->name('sale-payments.index');

            Route::middleware(['pin.completed', 'role:admin,manager'])->group(function () {
                Route::get('/returns', [SaleReturnController::class, 'index'])->name('returns.index');
                Route::get('/returns/{return}', [SaleReturnController::class, 'show'])->name('returns.show');
                Route::get('/returns/{return}/receipt', [SaleReturnController::class, 'receipt'])->name('returns.receipt');
                Route::get('/refunds', [SaleReturnController::class, 'refundIndex'])->name('refunds.index');
                Route::get('/refunds/{refund}', [SaleReturnController::class, 'refundShow'])->name('refunds.show');
                Route::get('/refunds/{refund}/receipt', [SaleReturnController::class, 'refundReceipt'])->name('refunds.receipt');
                Route::get('/sales/{sale}/returns/create', [SaleReturnController::class, 'create'])->name('sales.returns.create');
                Route::post('/sales/{sale}/returns', [SaleReturnController::class, 'store'])->name('sales.returns.store');
                Route::get('/sales/{sale}/refunds/create', [SaleReturnController::class, 'refundCreate'])->name('sales.refunds.create');
                Route::post('/sales/{sale}/refunds', [SaleReturnController::class, 'refundStore'])->name('sales.refunds.store');
                Route::prefix('reports')->name('reports.')->group(function () {
                    Route::get('/', [ReportController::class, 'index'])->name('index');
                    Route::get('/sales', [ReportController::class, 'sales'])->name('sales');
                    Route::get('/collections', [ReportController::class, 'collections'])->name('collections');
                    Route::get('/receivables', [ReportController::class, 'receivables'])->name('receivables');
                    Route::get('/expenses', [ReportController::class, 'expenses'])->name('expenses');
                    Route::get('/purchases', [ReportController::class, 'purchases'])->name('purchases');
                    Route::get('/inventory', [ReportController::class, 'inventory'])->name('inventory');
                    Route::get('/products', [ReportController::class, 'products'])->name('products');
                    Route::get('/customers', [ReportController::class, 'customers'])->name('customers');
                    Route::get('/staff', [ReportController::class, 'staff'])->name('staff');
                    Route::get('/business-summary', [ReportController::class, 'summary'])->name('summary');
                });
                Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
                Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
                Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
                Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
                Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
                Route::get('/expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
                Route::get('/expense-categories/create', [ExpenseCategoryController::class, 'create'])->name('expense-categories.create');
                Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
                Route::get('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'show'])->name('expense-categories.show');
                Route::get('/expense-categories/{expense_category}/edit', [ExpenseCategoryController::class, 'edit'])->name('expense-categories.edit');
                Route::put('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
                Route::post('/expense-categories/{expense_category}/activate', [ExpenseCategoryController::class, 'activate'])->name('expense-categories.activate');
                Route::post('/expense-categories/{expense_category}/deactivate', [ExpenseCategoryController::class, 'deactivate'])->name('expense-categories.deactivate');
                Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
                Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
                Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
                Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
                Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
                Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
                Route::post('/suppliers/{supplier}/activate', [SupplierController::class, 'activate'])->name('suppliers.activate');
                Route::post('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate'])->name('suppliers.deactivate');
                Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
                Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
                Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
                Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
                Route::get('/purchases/{purchase}/receipt', [PurchaseController::class, 'receipt'])->name('purchases.receipt');
            });

            Route::middleware('pin.completed')->prefix('whatsapp')->name('whatsapp.')->group(function () {
                Route::get('/deliveries', [WhatsAppDeliveryController::class, 'index'])->name('deliveries.index');
                Route::get('/deliveries/{delivery}', [WhatsAppDeliveryController::class, 'show'])->name('deliveries.show');
                Route::post('/deliveries/{delivery}/resolve-unknown', [WhatsAppDeliveryController::class, 'resolveUnknown'])
                    ->name('deliveries.resolve-unknown');
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
