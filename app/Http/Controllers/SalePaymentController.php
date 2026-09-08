<?php

namespace App\Http\Controllers;

use App\Actions\Sale\RecordSalePayment;
use App\Enums\PaymentMethod;
use App\Http\Requests\Sale\RecordSalePaymentRequest;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SalePaymentController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SalePayment::class);
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $from = is_string($request->query('from')) ? $request->query('from') : '';
        $to = is_string($request->query('to')) ? $request->query('to') : '';
        $recordedBy = is_string($request->query('recorded_by')) ? $request->query('recorded_by') : '';
        $paymentMethod = is_string($request->query('payment_method')) ? $request->query('payment_method') : '';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $methods = array_column(PaymentMethod::cases(), 'value');

        $payments = SalePayment::query()->with('sale:id,sale_number,customer_name_snapshot')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('payment_number', 'like', mb_strtoupper($escaped).'%')
                ->orWhereHas('sale', fn ($query) => $query->where('sale_number', 'like', mb_strtoupper($escaped).'%')
                    ->orWhere('customer_name_snapshot', 'like', $escaped.'%'))))
            ->when(in_array($paymentMethod, $methods, true), fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from), fn ($query) => $query->whereDate('paid_at', '>=', $from))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $to), fn ($query) => $query->whereDate('paid_at', '<=', $to))
            ->when(ctype_digit($recordedBy), fn ($query) => $query->where('recorded_by', $recordedBy))
            ->latest('paid_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString();

        return view('sale-payments.index', [
            'payments' => $payments,
            'recorders' => User::query()->orderBy('name')->get(['id', 'name']),
            'filters' => compact('search', 'from', 'to', 'recordedBy', 'paymentMethod'),
        ]);
    }

    public function store(RecordSalePaymentRequest $request, Sale $sale, RecordSalePayment $action): RedirectResponse
    {
        $payment = $action->execute($request->user(), $sale, $request->validated(), $request->session()->getId());

        return redirect()->route('sales.payments.show', [$sale, $payment])->with('status', 'Payment recorded successfully.');
    }

    public function show(Sale $sale, SalePayment $payment): View
    {
        $this->authorizeNested($sale, $payment);

        return view('sale-payments.show', compact('sale', 'payment'));
    }

    public function receipt(Sale $sale, SalePayment $payment): View
    {
        $this->authorizeNested($sale, $payment);

        return view('sale-payments.receipt', compact('sale', 'payment'));
    }

    private function authorizeNested(Sale $sale, SalePayment $payment): void
    {
        abort_unless($payment->sale_id === $sale->id, 404);
        Gate::authorize('view', $payment);
    }
}
