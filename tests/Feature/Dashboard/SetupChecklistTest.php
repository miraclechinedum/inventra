<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Business\ProvisionBusiness;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppWorld;
use Tests\TestCase;

/**
 * The first-run checklist counts the four required steps from each Business's own records; the
 * optional WhatsApp connection is offered alongside but never moves the count.
 */
class SetupChecklistTest extends TestCase
{
    use BuildsWhatsAppWorld, RefreshDatabase;

    public function test_a_new_business_starts_at_zero_of_four_with_whatsapp_offered_but_not_counted(): void
    {
        $owner = $this->owner('Tech Hub', 'owner@tech.test', '08035550401');

        $this->assertProgress($owner, 0, false);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Set up Tech Hub', '0 of 4 complete.', 'You can use Inventra while you finish.'])
            ->assertSeeInOrder(['Complete your business profile', 'Add your first product', 'Add your first customer', 'Invite your staff', 'Connect WhatsApp', 'Optional'])
            ->assertDontSee('of 5');
    }

    public function test_each_required_step_adds_one_and_all_four_complete_the_setup(): void
    {
        $owner = $this->owner('Tech Hub', 'owner@tech.test', '08035550401');
        $business = $owner->business;

        DB::table('business_settings')->where('business_id', $business->id)->update(['business_phone' => '+2348035550401', 'business_address' => '1 Marina, Lagos']);
        $this->assertProgress($owner, 1, false);

        $this->addProduct($owner);
        $this->assertProgress($owner, 2, false);

        $this->addCustomer($owner);
        $this->assertProgress($owner, 3, false);

        User::factory()->forBusiness($business)->create(['role' => UserRole::SalesRep]);
        $this->assertProgress($owner, 4, true);

        // Set up, with WhatsApp still on offer and nothing else listed.
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Tech Hub is set up')->assertSee('4 of 4 complete.')->assertSee('Connect WhatsApp')->assertSee('Optional')
            ->assertDontSee('Set up Tech Hub')->assertDontSee('Add your first product')->assertDontSee('You can use Inventra while you finish.');

        // Connecting the optional extra leaves nothing to offer, so the checklist goes away.
        $this->connectWhatsApp($business, 'waba-tech', '100000401', 'token-tech');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertViewHas('setup', null)->assertDontSee('dash-setup');
    }

    public function test_connecting_whatsapp_never_changes_the_required_count(): void
    {
        $owner = $this->owner('Tech Hub', 'owner@tech.test', '08035550401');
        $business = $owner->business;

        $this->connectWhatsApp($business, 'waba-tech', '100000401', 'token-tech');
        $this->assertProgress($owner, 0, false);
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('0 of 4 complete.');

        $this->addProduct($owner);
        $this->assertProgress($owner, 1, false);
        $this->actingAs($owner)->get(route('dashboard'))->assertViewHas('setup', fn (array $setup): bool => collect($setup['steps'])->firstWhere('key', 'whatsapp')['done'] === true);
    }

    public function test_progress_is_each_businesss_own(): void
    {
        $alpha = $this->owner('Alpha Ltd', 'owner@alpha.test', '08035550402');
        $bravo = $this->owner('Bravo Ltd', 'owner@bravo.test', '08035550403');

        $this->addProduct($alpha);
        $this->addCustomer($alpha);
        User::factory()->forBusiness($alpha->business)->create(['role' => UserRole::Manager]);
        $this->connectWhatsApp($alpha->business, 'waba-alpha', '100000402', 'token-alpha');

        $this->assertProgress($alpha, 3, false);
        $this->assertProgress($bravo, 0, false);
    }

    public function test_the_checklist_is_administrator_only_and_leaves_the_dashboard_metrics_alone(): void
    {
        $owner = $this->owner('Tech Hub', 'owner@tech.test', '08035550401');
        $manager = User::factory()->forBusiness($owner->business)->create(['role' => UserRole::Manager, 'quick_pin_setup_completed' => true]);

        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertViewHas('setup', null)->assertDontSee('dash-setup');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Total Inventory Value')->assertSee('Sales Today')->assertSee('Low-Stock Products')->assertSee('Outstanding Sales');
    }

    /* ------------------------------------------------------------------- helpers */

    private function owner(string $business, string $email, string $phone): User
    {
        app(CurrentBusiness::class)->forget();

        try {
            $owner = app(ProvisionBusiness::class)->execute([
                'business_name' => $business, 'owner_name' => 'Owner of '.$business, 'email' => $email,
                'phone' => User::normalizePhone($phone), 'password' => 'Secret123',
            ]);
        } finally {
            app(CurrentBusiness::class)->forget();
        }

        $owner->forceFill(['email_verified_at' => now(), 'quick_pin_setup_completed' => true])->save();

        return $owner->fresh();
    }

    /** Every record is attributed to the owner, so no factory-made creator can count as invited staff. */
    private function addProduct(User $owner): void
    {
        app(CurrentBusiness::class)->run($owner->business, fn () => Product::factory()->forBusiness($owner->business)->create([
            'created_by' => $owner->id,
            'category_id' => ProductCategory::factory()->forBusiness($owner->business)->create(['created_by' => $owner->id])->id,
        ]));
    }

    private function addCustomer(User $owner): void
    {
        app(CurrentBusiness::class)->run($owner->business, fn () => Customer::factory()->forBusiness($owner->business)->create(['created_by' => $owner->id]));
    }

    private function assertProgress(User $owner, int $done, bool $complete): void
    {
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertViewHas('setup', fn (array $setup): bool => [$setup['done'], $setup['total'], $setup['complete']] === [$done, 4, $complete]);
    }
}
