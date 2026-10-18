<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\SaleRequest;
use App\Models\User;
use App\Support\CartSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spends a Record Sale page's single-use token and records the Sale it was issued for.
 *
 * The token row is the first lock taken, ahead of the customer, the products and any discount
 * draft, so two deliveries of the same submission serialise on it: the first records the Sale,
 * and the second finds the token spent and is handed that same Sale. A retried request, a double
 * click or the back button can therefore never record a second Sale or deduct stock twice.
 *
 * A spent token replays only the exact submission that spent it. Anything else presented with it
 * is refused rather than silently treated as the earlier Sale.
 */
class RecordSaleSubmission
{
    public function __construct(private readonly CreateSale $sales) {}

    /**
     * @return array{sale: Sale, replayed: bool}
     */
    public function execute(User $actor, array $data, string $sessionId): array
    {
        $result = DB::transaction(function () use ($actor, $data, $sessionId): array {
            $submission = SaleRequest::query()
                ->where('token_hash', hash('sha256', (string) $data['request_token']))
                ->where('business_id', $actor->business_id)
                ->lockForUpdate()
                ->first();

            if ($submission === null
                || (int) $submission->actor_id !== (int) $actor->id
                || ! hash_equals((string) $submission->session_id, $sessionId)) {
                throw ValidationException::withMessages([
                    'request_token' => 'This sale form is no longer valid. Review the sale and submit it again.',
                ]);
            }

            $payloadHash = $this->payloadHash($data);

            if ($submission->used_at !== null) {
                if (! hash_equals((string) $submission->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'request_token' => 'This sale form was already used to record a different sale. Review the sale and submit it again.',
                    ]);
                }

                return ['sale' => Sale::query()->findOrFail($submission->sale_id), 'replayed' => true];
            }

            if ($submission->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'request_token' => 'This sale form has expired. Review the sale and submit it again.',
                ]);
            }

            $sale = $this->sales->record($actor, $data, $submission);

            $submission->used_at = now();
            $submission->payload_hash = $payloadHash;
            $submission->sale_id = $sale->id;
            $submission->save();

            return ['sale' => $sale, 'replayed' => false];
        });

        // Only a Sale this call actually recorded has anything to follow it; a replay is the
        // Sale an earlier delivery already committed and followed up.
        if (! $result['replayed']) {
            $this->sales->afterCommit($result['sale']);
        }

        return $result;
    }

    /**
     * What this submission asks for, canonicalised so that the same sale hashes the same however
     * its lines were split or ordered. Server-derived figures are not part of it: they are not
     * the operator's to submit.
     */
    private function payloadHash(array $data): string
    {
        $walkIn = (bool) ($data['is_walk_in'] ?? false);

        return hash('sha256', json_encode([
            'lines' => CartSnapshot::canonical($data['products'] ?? []),
            'buyer' => $walkIn ? 'walk-in' : 'customer:'.(int) ($data['customer_id'] ?? 0),
            'sale_date' => (string) ($data['sale_date'] ?? ''),
            'amount_paid' => bcadd((string) ($data['amount_paid'] ?? '0'), '0', 2),
            'payment_method' => (string) ($data['payment_method'] ?? ''),
            'sale_draft_id' => isset($data['sale_draft_id']) && $data['sale_draft_id'] !== '' ? (int) $data['sale_draft_id'] : null,
            'notes' => (string) ($data['notes'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }
}
