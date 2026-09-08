<?php

namespace App\Http\Controllers;

use App\Actions\Sale\IssueRefundRequest;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Enums\ReturnDisposition;
use App\Enums\UserRole;
use App\Http\Requests\RecordSaleRefundRequest;
use App\Http\Requests\RecordSaleReturnRequest;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Support\PerPage;
use App\Support\SaleFinancials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SaleReturnController extends Controller
{
    private function authorizeUser(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::Manager], true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeUser($request);

        $filters = $this->filters($request);
        $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($filters['search'], 0, 255));
        $rows = SaleReturn::query()->withCount('items')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('return_number', 'like', $search.'%')->orWhere('sale_number_snapshot', 'like', $search.'%')))
            ->when(ctype_digit($filters['customer']), fn ($query) => $query->where('customer_id', (int) $filters['customer']))
            ->when(ctype_digit($filters['staff']), fn ($query) => $query->where('returned_by', (int) $filters['staff']))
            ->when($this->date($filters['from']), fn ($query) => $query->whereDate('returned_at', '>=', $filters['from']))
            ->when($this->date($filters['to']), fn ($query) => $query->whereDate('returned_at', '<=', $filters['to']))
            ->latest('returned_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString();

        return view('returns.index', compact('rows', 'filters'));
    }

    public function create(Request $request, Sale $sale, IssueReturnRequest $tokens): View
    {
        $this->authorizeUser($request);
        abort_unless($sale->status->value === 'completed', 422);
        $sale->load('items');
        $prior = DB::table('sale_return_items')->selectRaw('sale_item_id,SUM(quantity_returned) quantity')->whereIn('sale_item_id', $sale->items->pluck('id'))->groupBy('sale_item_id')->pluck('quantity', 'sale_item_id');

        return view('returns.create', ['sale' => $sale, 'prior' => $prior, 'dispositions' => ReturnDisposition::cases(), 'requestToken' => $tokens->execute($request->user(), $sale, $request->session())]);
    }

    public function store(RecordSaleReturnRequest $request, Sale $sale, RecordSaleReturn $action): RedirectResponse
    {
        $return = $action->execute($request->user(), $sale, $request->validated(), $request->session()->getId());

        return redirect()->route('returns.show', $return)->with('status', 'Return recorded.');
    }

    public function show(Request $request, SaleReturn $return): View
    {
        $this->authorizeUser($request);

        return view('returns.show', ['return' => $return->load('items')]);
    }

    public function receipt(Request $request, SaleReturn $return): View
    {
        $this->authorizeUser($request);

        return view('returns.receipt', ['return' => $return->load('items')]);
    }

    public function refundCreate(Request $request, Sale $sale, IssueRefundRequest $tokens): View
    {
        $this->authorizeUser($request);
        $state = DB::transaction(fn () => SaleFinancials::lockedState(Sale::lockForUpdate()->findOrFail($sale->id)));

        return view('returns.refund', compact('sale', 'state') + ['requestToken' => $tokens->execute($request->user(), $sale, $request->session())]);
    }

    public function refundStore(RecordSaleRefundRequest $request, Sale $sale, RecordSaleRefund $action): RedirectResponse
    {
        $refund = $action->execute($request->user(), $sale, $request->validated(), $request->session()->getId());

        return redirect()->route('refunds.show', $refund)->with('status', 'Refund recorded.');
    }

    public function refundIndex(Request $request): View
    {
        $this->authorizeUser($request);
        $filters = $this->filters($request);
        $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($filters['search'], 0, 255));
        $rows = SaleRefund::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('refund_number', 'like', $search.'%')->orWhere('sale_number_snapshot', 'like', $search.'%')))
            ->when(ctype_digit($filters['customer']), fn ($query) => $query->where('customer_id', (int) $filters['customer']))
            ->when(ctype_digit($filters['staff']), fn ($query) => $query->where('refunded_by', (int) $filters['staff']))
            ->when(in_array($filters['method'], ['cash', 'transfer'], true), fn ($query) => $query->where('payment_method', $filters['method']))
            ->when($this->date($filters['from']), fn ($query) => $query->whereDate('refunded_at', '>=', $filters['from']))
            ->when($this->date($filters['to']), fn ($query) => $query->whereDate('refunded_at', '<=', $filters['to']))
            ->latest('refunded_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString();

        return view('returns.refunds-index', compact('rows', 'filters'));
    }

    public function refundShow(Request $request, SaleRefund $refund): View
    {
        $this->authorizeUser($request);

        return view('returns.refund-show', compact('refund'));
    }

    public function refundReceipt(Request $request, SaleRefund $refund): View
    {
        $this->authorizeUser($request);

        return view('returns.refund-receipt', compact('refund'));
    }

    private function filters(Request $request): array
    {
        return collect(['search', 'customer', 'staff', 'method', 'from', 'to'])->mapWithKeys(fn (string $key) => [$key => is_string($request->query($key)) ? trim($request->query($key)) : ''])->all();
    }

    private function date(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
