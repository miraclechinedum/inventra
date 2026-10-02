<?php

namespace App\Console\Commands;

use App\Actions\WhatsAppAutomation\SendWhatsAppMessage;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\WhatsAppMessage;
use App\Tenancy\CurrentBusiness;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends the messages the automations queued. The only place the provider is called for an automatic
 * message, and it runs on the scheduler — never inside a business transaction, so a provider outage
 * can never delay or fail a sale, a customer record or a stock movement.
 *
 * Shared-hosting compatible by construction: one short command invoked by the same once-a-minute
 * cron the scheduler already uses. No queue worker, no supervisor, no Redis, no Horizon — the same
 * arrangement the previous receipt dispatcher proved out here.
 *
 * Duplicate sending is prevented by the database, not by this process. A row is claimed with a
 * conditional UPDATE that matches only while `dispatch_claimed_at` is NULL, and the row is ours only
 * if that UPDATE affected exactly one row. A concurrent run, an overlapping tick or a re-run after
 * an interruption therefore cannot claim the same row twice. A crash between claiming and sending
 * leaves a message unsent rather than sent twice, which is the safer failure for something a real
 * person receives.
 *
 * Messages of every Business share this one run, and nothing else is shared. Each message is sent
 * by SendWhatsAppMessage inside its own Business, through that Business's connection, so no message
 * can leave another Business's number. A Business whose connection is not connected simply has no
 * candidates — its messages wait, exactly as the whole installation's did before — and it can
 * never hold up, or fall through to, another Business's sends.
 */
class DispatchWhatsAppMessagesCommand extends Command
{
    protected $signature = 'inventra:dispatch-whatsapp-messages {--limit=25 : Maximum messages to dispatch in one run}';

    protected $description = 'Send queued WhatsApp automation messages';

    public function handle(WhatsAppConnectionProvider $provider, SendWhatsAppMessage $sender, CurrentBusiness $tenancy): int
    {
        if (! $provider->isConfigured()) {
            $this->line('WhatsApp is not configured; nothing dispatched.');

            return self::SUCCESS;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->candidates($limit) as $candidate) {
            if (! $this->claim($candidate)) {
                $skipped++;

                continue;
            }

            try {
                // Read inside the message's own Business; the send enters it again for the rest.
                $business = Business::query()->findOrFail($candidate->business_id);
                $message = $tenancy->run($business, fn () => WhatsAppMessage::query()->find($candidate->id));

                if ($message === null) {
                    $skipped++;

                    continue;
                }

                $message = $sender->dispatch($message);
                $message->status === WhatsAppMessage::STATUS_FAILED ? $failed++ : $sent++;
            } catch (Throwable $exception) {
                // One bad message must not stop the rest of the run.
                $failed++;
                report($exception);
            }
        }

        $this->line("Dispatched {$sent}, failed {$failed}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Queued messages whose send time has arrived, of active Businesses whose own connection is
     * connected. `send_after` is how the pickup reminder's "1 day after" is honoured without a
     * daemon: the row simply is not a candidate until then.
     *
     * Deliberately across Businesses: the scheduler serves them all. It selects identifiers only,
     * and each is then handled inside its own Business.
     *
     * @return Collection<int, object{id: int, business_id: int}>
     */
    private function candidates(int $limit): Collection
    {
        return DB::table('whatsapp_messages as m')
            ->join('businesses as b', 'b.id', '=', 'm.business_id')
            ->join('whatsapp_connection as c', 'c.business_id', '=', 'm.business_id')
            ->select('m.id', 'm.business_id')
            ->where('b.status', BusinessStatus::Active->value)
            ->where('c.status', 'connected')
            ->where(fn ($q) => $q->whereNull('c.token_expires_at')->orWhere('c.token_expires_at', '>', now()))
            ->where('m.status', WhatsAppMessage::STATUS_QUEUED)
            ->whereNull('m.dispatch_claimed_at')
            ->where(fn ($q) => $q->whereNull('m.send_after')->orWhere('m.send_after', '<=', now()))
            ->orderBy('m.id')
            ->limit($limit)
            ->get();
    }

    /** The claim. True only if this process is the one that took the row. */
    private function claim(object $candidate): bool
    {
        return DB::table('whatsapp_messages')
            ->where('business_id', $candidate->business_id)
            ->where('id', $candidate->id)
            ->whereNull('dispatch_claimed_at')
            ->where('status', WhatsAppMessage::STATUS_QUEUED)
            ->update(['dispatch_claimed_at' => now(), 'updated_at' => now()]) === 1;
    }
}
