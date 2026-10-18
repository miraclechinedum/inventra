<?php

namespace Tests\Concerns;

use App\Actions\Sale\IssueSaleRequest;
use App\Tenancy\CurrentBusiness;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

/**
 * Record Sale submissions for HTTP tests.
 *
 * A Record Sale post must carry the single-use token its page was issued, bound to the operator's
 * session. This issues a real token, through the real action, for the signed-in user and a session
 * the following requests carry by cookie — exactly what loading the page would have done.
 */
trait SubmitsSales
{
    private ?string $saleSubmissionSession = null;

    /** The payload with a fresh, unspent submission token for the signed-in user. */
    protected function withSaleToken(array $payload): array
    {
        return $payload + ['request_token' => $this->saleToken()];
    }

    protected function saleToken(): string
    {
        $user = auth()->user();

        // A test that already carries a session cookie keeps that session, so its flashed errors
        // and old input still reach the next request; otherwise one is chosen here.
        $cookie = config('session.cookie');
        $this->saleSubmissionSession ??= is_string($this->defaultCookies[$cookie] ?? null)
            ? $this->defaultCookies[$cookie]
            : Str::random(40);
        $this->withCookie($cookie, $this->saleSubmissionSession);

        $session = new Store($cookie, new ArraySessionHandler(1), $this->saleSubmissionSession);

        // Issued inside the operator's own Business, as loading their Record Sale page would be.
        return app(CurrentBusiness::class)->run(
            $user->business,
            fn (): string => app(IssueSaleRequest::class)->execute($user, $session),
        );
    }
}
