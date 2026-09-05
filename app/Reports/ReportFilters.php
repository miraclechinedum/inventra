<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class ReportFilters
{
    public function __construct(
        public string $from,
        public string $to,
        public string $staff,
        public string $customer,
        public string $product,
        public string $category,
        public string $paymentMethod,
        public string $supplier,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $timezone = config('business.timezone');
        $today = CarbonImmutable::now($timezone);
        $defaultFrom = $today->startOfMonth()->toDateString();
        $defaultTo = $today->endOfMonth()->toDateString();
        $from = self::date($request, 'from', $defaultFrom);
        $to = self::date($request, 'to', $defaultTo);
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'From date cannot be after To date.']);
        }

        return new self(
            $from, $to,
            self::scalar($request, 'staff'), self::scalar($request, 'customer'),
            self::scalar($request, 'product'), self::scalar($request, 'category'),
            self::scalar($request, 'payment_method'), self::scalar($request, 'supplier'),
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

    public function query(): array
    {
        return array_filter([
            'from' => $this->from, 'to' => $this->to, 'staff' => $this->staff,
            'customer' => $this->customer, 'product' => $this->product,
            'category' => $this->category, 'payment_method' => $this->paymentMethod,
            'supplier' => $this->supplier,
        ], fn ($value) => $value !== '');
    }

    private static function scalar(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? mb_substr(trim($value), 0, 255) : '';
    }

    private static function date(Request $request, string $key, string $default): string
    {
        $value = self::scalar($request, $key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && CarbonImmutable::hasFormat($value, 'Y-m-d') ? $value : $default;
    }
}
