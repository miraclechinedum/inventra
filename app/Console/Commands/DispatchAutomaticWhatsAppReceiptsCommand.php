<?php

namespace App\Console\Commands;

use App\Actions\WhatsApp\SendWhatsAppReceipt;
use App\Actions\WhatsApp\TransitionWhatsAppDelivery;
use App\Contracts\WhatsAppClient;
use App\Enums\SaleStatus;
use App\Enums\WhatsAppDeliveryOrigin;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\Sale;
use App\Models\WhatsAppDelivery;
use App\Services\AuditLogger;
use App\Support\WhatsAppReceiptEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the receipts that sale completion queued. This is the only place the Meta API is called for
 * an automatic delivery, and it runs on the scheduler — never inside a sale transaction.
 *
 * Duplicate sending is prevented by the database, not by this process. A candidate is taken with a
 * conditional UPDATE that only matches while `dispatch_claimed_at` is still NULL; the row is ours
 * only if that UPDATE affected exactly one row. A second scheduler tick, a concurrent run, or a
 * re-run after this command is interrupted therefore cannot claim a row that has already been
 * claimed. The consequence of a crash between claiming and sending is a receipt that is never sent
 * rather than one sent twice, which is the safer failure for a customer-facing message.
 */
class DispatchAutomaticWhatsAppReceiptsCommand extends Command
{
    protected $signature = 'inventra:dispatch-whatsapp-receipts {--limit=25 : Maximum deliveries to dispatch in one run}';

    protected $description = 'Send WhatsApp receipts queued by completed sales';

    public function handle(
        WhatsAppClient $client,
        SendWhatsAppReceipt $sender,
        TransitionWhatsAppDelivery $transition,
        AuditLogger $audit,
    ): int {
        if (! $client->isConfigured()) {
            $this->line('WhatsApp is not configured; nothing dispatched.');

            return self::SUCCESS;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $dispatched = 0;
        $skipped = 0;
        $withdrawn = 0;
        $failed = 0;

        foreach ($this->candidates($limit) as $candidate) {
            if (! $this->claim($candidate->id)) {
                $skipped++;

                continue;
            }

            try {
                $delivery = WhatsAppDelivery::query()->with('customer')->findOrFail($candidate->id);
                $sale = $delivery->sale;

                if ($sale === null) {
                    $skipped++;

                    continue;
                }

                // Consent and sale state are re-read here, not trusted from queueing time. Minutes
                // can pass between a sale completing and this run — long enough for the customer to
                // opt out or for an Admin to void the sale — and a withdrawn consent must stop the
                // send even though the row was eligible when it was queued.
                $blocker = $this->blockingReason($delivery, $sale);

                if ($blocker !== null) {
                    $this->abandon($transition, $audit, $delivery, $blocker);
                    $withdrawn++;

                    continue;
                }

                // The scheduler, not a staff member, is the actor: the audit trail records this as
                // a system action so an automatic send is never attributed to the seller.
                $sender->dispatch($delivery, $sale, null);
                $dispatched++;
            } catch (Throwable $exception) {
                $failed++;
                Log::warning('An automatic WhatsApp receipt dispatch failed.', [
                    'delivery_id' => $candidate->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        // Counts only; no customer names, phone numbers or amounts in cron output.
        $this->line('Dispatched: '.$dispatched);
        $this->line('Already claimed elsewhere: '.$skipped);
        $this->line('Abandoned (consent or sale changed): '.$withdrawn);
        $this->line('Failed: '.$failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Why this claimed delivery must not be sent after all, or null when it may proceed. The code
     * is short enough for the 64-character failure_code column and is recorded verbatim.
     */
    private function blockingReason(WhatsAppDelivery $delivery, Sale $sale): ?string
    {
        if ($sale->status !== SaleStatus::Completed) {
            return 'sale_no_longer_completed';
        }

        if ($delivery->customer === null || ! WhatsAppReceiptEligibility::permits($delivery->customer)) {
            return 'consent_withdrawn';
        }

        return null;
    }

    /**
     * Closes a claimed delivery without contacting the provider. The attempt stays on record as a
     * failed delivery so the reason is visible in WhatsApp history rather than vanishing, and the
     * terminal Failed status keeps it out of the queue permanently.
     */
    private function abandon(
        TransitionWhatsAppDelivery $transition,
        AuditLogger $audit,
        WhatsAppDelivery $delivery,
        string $reason,
    ): void {
        $delivery = $transition->execute(
            $delivery,
            WhatsAppDeliveryStatus::Failed,
            failureCode: $reason,
            failureReason: $reason === 'consent_withdrawn'
                ? 'The Customer was no longer eligible for WhatsApp delivery when the receipt was dispatched.'
                : 'The Sale was no longer completed when the receipt was dispatched.',
        );

        $audit->record('whatsapp_receipt_auto_abandoned', $delivery, null, newValues: $delivery->getAttributes(), metadata: [
            'reason' => $reason,
        ]);

        Log::info('An automatic WhatsApp receipt was abandoned before contacting the provider.', [
            'delivery_id' => $delivery->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Unclaimed automatic rows still awaiting their first provider call. Manual rows are excluded
     * by `origin`, which is what keeps a manual send that is momentarily pending — between its own
     * commit and the provider's reply — out of this queue.
     *
     * @return Collection<int, object>
     */
    private function candidates(int $limit)
    {
        return DB::table('whatsapp_deliveries')
            ->select('id')
            ->where('origin', WhatsAppDeliveryOrigin::Automatic->value)
            ->where('status', WhatsAppDeliveryStatus::Pending->value)
            ->whereNull('dispatch_claimed_at')
            ->whereNull('provider_message_id')
            ->whereNull('failure_code')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** Takes exclusive ownership of one queued delivery. True only if this run won the row. */
    private function claim(int $deliveryId): bool
    {
        return DB::table('whatsapp_deliveries')
            ->where('id', $deliveryId)
            ->where('origin', WhatsAppDeliveryOrigin::Automatic->value)
            ->where('status', WhatsAppDeliveryStatus::Pending->value)
            ->whereNull('dispatch_claimed_at')
            ->update(['dispatch_claimed_at' => now(), 'updated_at' => now()]) === 1;
    }
}
