<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Expense\IssueExpenseRequest;
use App\Actions\Sale\IssueSalePaymentRequest;
use App\Actions\Sale\RecordSalePayment;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Sale;
use App\Models\SalePaymentRequest;
use App\Models\SaleReturnRequest;
use App\Models\User;
use App\Support\UnavailableIdentifier;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Request tokens carry their operator's Business, and staff identity validation says nothing about
 * other Businesses. Staff emails and phones stay unique across every Business, because login does
 * not name a Business; the wording is what must not leak.
 */
class RequestTokenAndIdentityTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private User $adminA;

    private User $colleagueA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Business::query()->orderBy('id')->firstOrFail();
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->adminA = User::factory()->forBusiness($this->a)->create(['role' => UserRole::Admin, 'email' => 'admin@alpha.test', 'phone' => '+2348031000001']);
        $this->colleagueA = User::factory()->forBusiness($this->a)->create(['role' => UserRole::Manager, 'email' => 'colleague@alpha.test', 'phone' => '+2348031000002']);
        $this->adminB = User::factory()->forBusiness($this->b)->create(['role' => UserRole::Admin, 'email' => 'owner@bravo.test', 'phone' => '+2348032000001']);
    }

    /* ---------------------------------------------------------------- request tokens */

    public function test_a_token_is_stamped_with_its_operators_business(): void
    {
        $session = session()->driver();

        $this->inBusiness($this->b, fn () => app(IssueExpenseRequest::class)->execute($this->adminB, $session));
        $saleA = Sale::factory()->forBusiness($this->a)->create();
        app(IssueSalePaymentRequest::class)->execute($saleA, $this->adminA, $session);

        $this->assertSame($this->b->id, DB::table('expense_requests')->where('actor_id', $this->adminB->id)->value('business_id'));
        $this->assertSame($this->a->id, DB::table('sale_payment_requests')->where('actor_id', $this->adminA->id)->value('business_id'));
    }

    public function test_a_token_cannot_span_two_businesses(): void
    {
        $saleB = $this->inBusiness($this->b, fn () => Sale::factory()->forBusiness($this->b)->create());

        foreach ([
            'another business\'s sale' => ['sale_id' => $saleB->id],
            'a business the operator is not in' => ['sale_id' => Sale::factory()->forBusiness($this->a)->create()->id, 'business_id' => $this->b->id],
        ] as $case => $attributes) {
            $token = new SalePaymentRequest;
            $token->forceFill($attributes + ['token_hash' => hash('sha256', Str::random(64)), 'actor_id' => $this->adminA->id,
                'session_id' => 'probe', 'expires_at' => now()->addMinute()]);

            try {
                $token->save();
                $this->fail("A token must refuse {$case}.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        // And the database, beneath the model.
        $this->expectException(QueryException::class);
        DB::table('sale_return_requests')->insert([
            'business_id' => $this->a->id, 'token_hash' => hash('sha256', Str::random(64)), 'sale_id' => $saleB->id,
            'actor_id' => $this->adminA->id, 'session_id' => 'probe', 'expires_at' => now()->addMinute(),
        ]);
    }

    public function test_a_token_issued_in_another_business_is_never_found(): void
    {
        $saleB = $this->inBusiness($this->b, fn () => Sale::factory()->forBusiness($this->b)->create());
        $token = $this->inBusiness($this->b, fn () => app(IssueSalePaymentRequest::class)->execute($saleB, $this->adminB, session()->driver()));

        try {
            $this->inBusiness($this->a, fn () => app(RecordSalePayment::class)->execute($this->adminA, Sale::factory()->forBusiness($this->a)->create(), [
                'request_token' => $token, 'amount' => '1.00', 'payment_method' => 'cash', 'note' => null,
            ], session()->getId()));
            $this->fail('Another Business\'s token must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_token', $exception->errors());
        }

        $this->assertNull(DB::table('sale_payment_requests')->where('actor_id', $this->adminB->id)->value('used_at'));
        $this->assertSame(0, SaleReturnRequest::query()->count());
    }

    /* ----------------------------------------------------------- identity enumeration */

    public function test_creating_staff_cannot_tell_another_businesss_identifier_from_a_colleagues(): void
    {
        // The same exact message whether a colleague or another Business holds the identifier.
        foreach ([
            'email' => [UnavailableIdentifier::EMAIL, [$this->adminB->email, $this->colleagueA->email]],
            'phone' => [UnavailableIdentifier::PHONE, ['08032000001', '08031000002']],
        ] as $field => [$message, $values]) {
            foreach ($values as $value) {
                $this->actingAs($this->adminA)->post(route('staff.store'), array_merge(
                    ['name' => 'New Hire', 'email' => 'new.hire@alpha.test', 'phone' => '08039999999', 'role' => UserRole::Manager->value],
                    [$field => $value],
                ))->assertSessionHasErrors([$field => $message]);
            }
        }

        $this->assertStringNotContainsStringIgnoringCase('exist', UnavailableIdentifier::EMAIL.UnavailableIdentifier::PHONE.UnavailableIdentifier::EITHER);
        $this->assertSame(0, User::query()->where('email', 'new.hire@alpha.test')->count());
    }

    public function test_updating_staff_or_a_profile_says_the_same_for_any_holder_and_accepts_the_unchanged_value(): void
    {
        foreach ([
            'staff update' => fn (array $data) => $this->actingAs($this->adminA)->put(route('staff.update', $this->colleagueA), $data),
            'own profile' => fn (array $data) => $this->actingAs($this->colleagueA)->put(route('profile.update'), $data),
        ] as $form => $submit) {
            $unchanged = ['name' => $this->colleagueA->name, 'email' => $this->colleagueA->email, 'phone' => '08031000002'];

            $submit(['email' => $this->adminB->email] + $unchanged)->assertSessionHasErrors(['email' => UnavailableIdentifier::EMAIL]);
            $submit(['email' => $this->adminA->email] + $unchanged)->assertSessionHasErrors(['email' => UnavailableIdentifier::EMAIL]);
            $submit(['phone' => '08032000001'] + $unchanged)->assertSessionHasErrors(['phone' => UnavailableIdentifier::PHONE]);
            $submit(['phone' => '08031000001'] + $unchanged)->assertSessionHasErrors(['phone' => UnavailableIdentifier::PHONE]);
            $submit($unchanged)->assertSessionHasNoErrors();
        }

        // Uniqueness itself is unchanged: global, in the database.
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $this->colleagueA->id)->update(['email' => $this->adminB->email]);
    }

    public function test_login_still_resolves_accounts_of_either_business(): void
    {
        foreach ([$this->adminA, $this->adminB] as $user) {
            $this->post(route('login.store'), ['identifier' => $user->email, 'password' => 'password'])->assertRedirect();
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
        }
    }

    private function inBusiness(Business $business, \Closure $work): mixed
    {
        return app(CurrentBusiness::class)->run($business, $work);
    }
}
