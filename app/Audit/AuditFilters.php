<?php

namespace App\Audit;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Operator-facing filters for the audit trail. Every value arrives from the query string, so each
 * one is narrowed to a bounded scalar here and array-shaped input is discarded rather than coerced.
 */
final readonly class AuditFilters
{
    /**
     * Subject types the index may filter by, as key => model class. The browser never supplies a
     * class name; it supplies a key from this map, so no request value reaches a morph lookup.
     */
    public const SUBJECT_TYPES = [
        'sale' => Sale::class,
        'payment' => SalePayment::class,
        'return' => SaleReturn::class,
        'refund' => SaleRefund::class,
        'customer' => Customer::class,
        'product' => Product::class,
        'product_category' => ProductCategory::class,
        'supplier' => Supplier::class,
        'purchase' => Purchase::class,
        'expense' => Expense::class,
        'expense_category' => ExpenseCategory::class,
        'staff' => User::class,
        'whatsapp_delivery' => WhatsAppDelivery::class,
    ];

    public function __construct(
        public string $from,
        public string $to,
        public string $actor,
        public string $event,
        public string $subjectType,
        public string $search,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $timezone = config('business.timezone');
        $today = CarbonImmutable::now($timezone);
        $from = self::date($request, 'from', $today->startOfMonth()->toDateString());
        $to = self::date($request, 'to', $today->endOfMonth()->toDateString());

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'From date cannot be after To date.']);
        }

        $subjectType = self::scalar($request, 'subject_type', 64);

        return new self(
            $from,
            $to,
            self::scalar($request, 'actor', 20),
            self::scalar($request, 'event', 64),
            array_key_exists($subjectType, self::SUBJECT_TYPES) ? $subjectType : '',
            self::scalar($request, 'search', 120),
        );
    }

    public function utcStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from, config('business.timezone'))->startOfDay()->utc();
    }

    public function utcEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to, config('business.timezone'))->endOfDay()->utc();
    }

    public function subjectClass(): ?string
    {
        return self::SUBJECT_TYPES[$this->subjectType] ?? null;
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return array_filter([
            'from' => $this->from, 'to' => $this->to, 'actor' => $this->actor,
            'event' => $this->event, 'subject_type' => $this->subjectType, 'search' => $this->search,
        ], fn (string $value): bool => $value !== '');
    }

    private static function scalar(Request $request, string $key, int $length): string
    {
        $value = $request->query($key);

        return is_string($value) ? mb_substr(trim($value), 0, $length) : '';
    }

    private static function date(Request $request, string $key, string $default): string
    {
        $value = self::scalar($request, $key, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && CarbonImmutable::hasFormat($value, 'Y-m-d')
            ? $value
            : $default;
    }
}
