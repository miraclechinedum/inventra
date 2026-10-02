<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppMessage;
use App\Support\PerPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The WhatsApp message log, read-only.
 *
 * Reached by Administrators and Managers through WhatsAppAutomationPolicy::viewLogs. That ability is
 * deliberately separate from `viewAny`, which still governs the Automation module itself — so a
 * Manager reads the log here and is refused the connection, the templates, the test send and the
 * retry, every one of which authorises independently.
 *
 * Nothing on this screen mutates. There is no store, update, destroy or retry action, so a Manager's
 * inability to retry is structural rather than a hidden button: the route does not exist for them.
 *
 * Every filter is applied in SQL and the list is paginated, so a busy installation never loads its
 * whole message history to answer a search.
 */
class WhatsAppLogController extends Controller
{
    /** Date windows the filter offers. Fixed set — the value reaches a WHERE clause. */
    private const RANGES = ['today', 'week', 'month', 'all'];

    public function index(Request $request): View
    {
        Gate::authorize('viewLogs', WhatsAppAutomation::class);

        $filters = $this->filters($request);
        $today = CarbonImmutable::now(config('business.timezone'));

        return view('whatsapp.logs.index', [
            'filters' => $filters,
            'summary' => $this->summary($today),
            'messages' => $this->query($filters)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(PerPage::resolve($request))
                ->withQueryString(),
            'selected' => $this->selected($request),
            'typeOptions' => $this->typeOptions(),
            'statusOptions' => $this->statusOptions(),
            'rangeOptions' => ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time'],
            // Retrying is Administrator-only and authorised on its own route; this only decides
            // which copy the panel shows, never whether the action is permitted.
            'canRetry' => $request->user()->can('retry', WhatsAppAutomation::class),
        ]);
    }

    /**
     * The four headline counts.
     *
     * "Sent today" counts messages that actually left today — anything the provider accepted, in
     * whatever state it has since reached — keyed off `sent_at` rather than `created_at`, because a
     * message queued yesterday and sent this morning was sent today. The other three describe the
     * current state of the whole log, which is how the design reads them.
     *
     * @return array<string, int>
     */
    private function summary(CarbonImmutable $today): array
    {
        $counts = WhatsAppMessage::query()
            ->selectRaw('COUNT(*) total')
            ->selectRaw('SUM(status = ?) delivered', [WhatsAppMessage::STATUS_DELIVERED])
            ->selectRaw('SUM(status = ?) read_count', [WhatsAppMessage::STATUS_READ])
            ->selectRaw('SUM(status IN (?, ?)) pending', [WhatsAppMessage::STATUS_QUEUED, WhatsAppMessage::STATUS_SENT])
            ->selectRaw('SUM(status = ?) failed', [WhatsAppMessage::STATUS_FAILED])
            ->selectRaw('SUM(sent_at IS NOT NULL AND sent_at BETWEEN ? AND ?) sent_today', [
                $today->startOfDay()->utc(), $today->endOfDay()->utc(),
            ])
            ->first();

        return [
            'sentToday' => (int) ($counts?->sent_today ?? 0),
            // `read` is a delivered message the recipient also opened; counting it as delivered
            // keeps the card honest rather than under-reporting successful delivery.
            'delivered' => (int) ($counts?->delivered ?? 0) + (int) ($counts?->read_count ?? 0),
            'pending' => (int) ($counts?->pending ?? 0),
            'failed' => (int) ($counts?->failed ?? 0),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function query(array $filters)
    {
        $search = $filters['search'];

        return WhatsAppMessage::query()
            ->when($search !== '', function ($query) use ($search): void {
                // Bound parameters with LIKE's own wildcards neutralised, so a typed % or _ searches
                // for that character rather than matching everything.
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

                $query->where(function ($query) use ($escaped): void {
                    $query->where('recipient_name', 'like', '%'.$escaped.'%')
                        ->orWhere('destination_phone', 'like', '%'.$escaped.'%');
                });
            })
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['status'] !== '', function ($query) use ($filters): void {
                // "Pending" is two real states — queued, and sent-but-unconfirmed. The filter maps
                // the word the operator picked onto the states it actually means.
                $query->when(
                    $filters['status'] === 'pending',
                    fn ($query) => $query->whereIn('status', [WhatsAppMessage::STATUS_QUEUED, WhatsAppMessage::STATUS_SENT]),
                    fn ($query) => $query->when(
                        $filters['status'] === WhatsAppMessage::STATUS_DELIVERED,
                        fn ($query) => $query->whereIn('status', [WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ]),
                        fn ($query) => $query->where('status', $filters['status']),
                    ),
                );
            })
            ->when($filters['range'] !== 'all', function ($query) use ($filters): void {
                $now = CarbonImmutable::now(config('business.timezone'));

                $from = match ($filters['range']) {
                    'today' => $now->startOfDay(),
                    'month' => $now->startOfMonth(),
                    default => $now->startOfWeek(),
                };

                $query->whereBetween('created_at', [$from->utc(), $now->endOfDay()->utc()]);
            });
    }

    /**
     * The row the detail panel is showing, or null when nothing is selected.
     *
     * Resolved through the same filtered query the list uses, so a hand-typed id cannot open a row
     * the operator's current filters exclude — and, more importantly, cannot reach anything the log
     * itself would not show them.
     */
    private function selected(Request $request): ?WhatsAppMessage
    {
        $id = $request->query('message');

        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return WhatsAppMessage::query()
            ->with(['automation:id,key', 'customer:id,first_name,last_name,phone'])
            ->whereKey((int) $id)
            ->first();
    }

    /**
     * The low-stock product a message refers to, when it refers to one.
     *
     * Read from the stored `subject` morph rather than parsed out of the message body, and only
     * when the subject really is a Product — so a message pointing at something else, or at a
     * product since deleted, simply has no preview link.
     */
    public static function lowStockProduct(WhatsAppMessage $message): ?Product
    {
        if ($message->type !== WhatsAppAutomation::LOW_STOCK || $message->subject_type !== 'product') {
            return null;
        }

        return Product::query()->whereKey($message->subject_id)->first();
    }

    /**
     * Types present in this installation, so the filter never offers a value that matches nothing —
     * and never hides one the backend has started producing.
     *
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        return WhatsAppMessage::query()
            ->select('type')->distinct()->orderBy('type')->pluck('type')
            ->mapWithKeys(fn (string $type): array => [
                $type => (new WhatsAppMessage(['type' => $type]))->typeLabel(),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            WhatsAppMessage::STATUS_DELIVERED => 'Delivered',
            'pending' => 'Pending',
            WhatsAppMessage::STATUS_FAILED => 'Failed',
        ];
    }

    /**
     * Every filter narrowed to a bounded scalar and checked against an allowlist, so array-shaped or
     * unknown input is discarded rather than reaching a query.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        $search = $request->query('search');
        $type = $request->query('type');
        $status = $request->query('status');
        $range = $request->query('range');

        return [
            'search' => is_string($search) ? mb_substr(trim($search), 0, 100) : '',
            'type' => is_string($type) && array_key_exists($type, $this->typeOptions()) ? $type : '',
            'status' => is_string($status) && array_key_exists($status, $this->statusOptions()) ? $status : '',
            'range' => is_string($range) && in_array($range, self::RANGES, true) ? $range : 'week',
        ];
    }
}
