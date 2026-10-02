<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Business\ProvisionBusiness;
use App\Actions\Inventory\CreateProduct;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The standard plan's separate allowances — one Manager, one Sales Representative, twenty
 * products — for Businesses provisioned through signup, and the uncapped legacy grant.
 */
class PlanAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_new_businesses_resolve_the_standard_allowances_through_their_plan(): void
    {
        $owner = $this->owner('Alpha Ltd');
        $entitlements = app(Entitlements::class);

        $this->assertSame('standard', DB::table('business_subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')->where('s.business_id', $owner->business_id)->value('p.key'));
        $this->assertSame([1, 1, 20, true], [
            $entitlements->limit($owner->business, Entitlement::MaxManagers),
            $entitlements->limit($owner->business, Entitlement::MaxSalesRepresentatives),
            $entitlements->limit($owner->business, Entitlement::MaxProducts),
            Plan::byKey('standard')->grant(Entitlement::WhatsAppAutomation),
        ]);
        // The owner is the Administrator and holds no role place.
        $this->assertSame([0, 0], [$entitlements->inUse($owner->business, Entitlement::MaxManagers), $entitlements->inUse($owner->business, Entitlement::MaxSalesRepresentatives)]);
    }

    public function test_one_manager_and_one_sales_representative_and_never_two_of_either(): void
    {
        $owner = $this->owner('Alpha Ltd');

        $this->hire($owner, UserRole::Manager)->assertOk();
        $this->hire($owner, UserRole::SalesRep)->assertOk();

        // Neither role can borrow the other's place.
        $this->hire($owner, UserRole::Manager)->assertSessionHasErrors(['staff' => 'Your current plan supports 1 Manager.']);
        $this->hire($owner, UserRole::SalesRep)->assertSessionHasErrors(['staff' => 'Your current plan supports 1 Sales Representative.']);
        $this->assertSame([UserRole::Admin->value => 1, UserRole::Manager->value => 1, UserRole::SalesRep->value => 1], $this->roles($owner));
    }

    public function test_a_spare_place_of_one_role_cannot_become_a_second_of_the_other(): void
    {
        $owner = $this->owner('Alpha Ltd');
        $this->hire($owner, UserRole::Manager)->assertOk();
        $this->hire($owner, UserRole::Manager)->assertSessionHasErrors('staff');

        $owner2 = $this->owner('Bravo Ltd');
        $this->hire($owner2, UserRole::SalesRep)->assertOk();
        $this->hire($owner2, UserRole::SalesRep)->assertSessionHasErrors('staff');

        // Nor through a role change: the spare Sales Representative place stays that.
        $rep = $this->hireAndFind($owner, UserRole::SalesRep);
        $this->actingAs($owner)->post(route('staff.role', $rep), ['role' => UserRole::Manager->value])
            ->assertSessionHasErrors(['staff' => 'Your current plan supports 1 Manager.']);
        $this->assertSame(UserRole::SalesRep, $rep->fresh()->role);
    }

    public function test_deactivating_frees_a_place_reactivating_needs_one_and_editing_takes_none(): void
    {
        $owner = $this->owner('Alpha Ltd');

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $first = $this->hireAndFind($owner, $role);

            $this->actingAs($owner)->put(route('staff.update', $first), ['name' => 'Renamed', 'email' => $first->email, 'phone' => $first->phone])->assertRedirect();
            $this->hire($owner, $role)->assertSessionHasErrors('staff');

            $this->actingAs($owner)->post(route('staff.deactivate', $first))->assertRedirect();
            $second = $this->hireAndFind($owner, $role);

            $this->actingAs($owner)->post(route('staff.activate', $first))->assertSessionHasErrors('staff');
            $this->assertSame(UserStatus::Inactive, $first->fresh()->status);

            $this->actingAs($owner)->post(route('staff.deactivate', $second))->assertRedirect();
            $this->actingAs($owner)->post(route('staff.activate', $first))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(UserStatus::Active, $first->fresh()->status);
        }
    }

    public function test_tenant_staff_management_cannot_create_an_administrator(): void
    {
        $owner = $this->owner('Alpha Ltd');

        $this->hire($owner, UserRole::Admin)->assertSessionHasErrors('role');
        $member = $this->hireAndFind($owner, UserRole::SalesRep);
        $this->actingAs($owner)->post(route('staff.role', $member), ['role' => UserRole::Admin->value])->assertSessionHasErrors('role');

        $this->assertSame(1, DB::table('users')->where('business_id', $owner->business_id)->where('role', UserRole::Admin->value)->count());
    }

    public function test_twenty_products_and_not_one_more(): void
    {
        $owner = $this->owner('Alpha Ltd');
        $category = $this->category($owner);

        for ($i = 1; $i <= 19; $i++) {
            $this->productThroughAction($owner, $category, "P-{$i}");
        }

        $this->actingAs($owner)->post(route('inventory.products.store'), $this->product($category, 'P-20'))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('inventory.products.store'), $this->product($category, 'P-21'))
            ->assertSessionHasErrors(['product' => 'Your current plan supports up to 20 products.']);
        $this->assertSame(20, DB::table('products')->where('business_id', $owner->business_id)->count());

        // Editing the twentieth takes no place.
        $twentieth = $this->inBusiness($owner->business, fn () => Product::query()->where('sku', 'P-20')->sole());
        $this->actingAs($owner)->put(route('inventory.products.update', $twentieth), $this->product($category, 'P-20', ['name' => 'Renamed twentieth']) + ['reorder_level' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed twentieth', $twentieth->fresh()->name);
    }

    public function test_archiving_frees_a_place_and_reactivating_needs_one(): void
    {
        $owner = $this->owner('Alpha Ltd');
        $category = $this->category($owner);

        for ($i = 1; $i <= 20; $i++) {
            $this->productThroughAction($owner, $category, "P-{$i}");
        }
        [$first, $second] = $this->inBusiness($owner->business, fn () => [Product::query()->where('sku', 'P-1')->sole(), Product::query()->where('sku', 'P-2')->sole()]);

        // Every product the application creates has history, so it can never be permanently
        // deleted; archiving takes it out of service instead, and that frees its place.
        $this->actingAs($owner)->delete(route('inventory.products.force-destroy', $first))->assertRedirect();
        $this->assertSame(20, DB::table('products')->where('business_id', $owner->business_id)->count(), 'a product with history is kept');

        $this->actingAs($owner)->delete(route('inventory.products.destroy', $first))->assertRedirect();
        $this->actingAs($owner)->post(route('inventory.products.store'), $this->product($category, 'P-21'))->assertSessionHasNoErrors();

        // Full again: bringing an archived product back needs a free place.
        $this->actingAs($owner)->post(route('inventory.products.reactivate', $first))->assertSessionHasErrors(['product' => 'Your current plan supports up to 20 products.']);
        $this->assertFalse((bool) $first->fresh()->is_active);

        $this->actingAs($owner)->post(route('inventory.products.deactivate', $second))->assertRedirect();
        $this->actingAs($owner)->post(route('inventory.products.activate', $first))->assertSessionHasNoErrors();
        $this->assertTrue((bool) $first->fresh()->is_active);
        $this->assertSame(20, app(Entitlements::class)->inUse($owner->business, Entitlement::MaxProducts));
    }

    public function test_each_businesss_allowances_are_its_own(): void
    {
        $alpha = $this->owner('Alpha Ltd');
        $bravo = $this->owner('Bravo Ltd');
        $this->hire($alpha, UserRole::Manager)->assertOk();
        $this->hire($alpha, UserRole::SalesRep)->assertOk();
        $category = $this->category($alpha);

        for ($i = 1; $i <= 20; $i++) {
            $this->productThroughAction($alpha, $category, "A-{$i}");
        }

        // Alpha is full on every allowance; Bravo has all of its own.
        $this->hire($bravo, UserRole::Manager)->assertOk();
        $this->hire($bravo, UserRole::SalesRep)->assertOk();
        $this->actingAs($bravo)->post(route('inventory.products.store'), $this->product($this->category($bravo), 'B-1'))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('products')->where('business_id', $bravo->business_id)->count());
    }

    public function test_the_grandfathered_legacy_business_is_not_capped(): void
    {
        $installation = Business::query()->orderBy('id')->firstOrFail();
        $admin = User::factory()->forBusiness($installation)->create(['role' => UserRole::Admin, 'quick_pin_setup_completed' => true]);
        $entitlements = app(Entitlements::class);

        foreach ([Entitlement::MaxManagers, Entitlement::MaxSalesRepresentatives, Entitlement::MaxProducts] as $limit) {
            $this->assertNull($entitlements->limit($installation, $limit), $limit->value);
        }

        foreach ([UserRole::Manager, UserRole::Manager, UserRole::SalesRep, UserRole::SalesRep] as $role) {
            $this->hire($admin, $role)->assertOk();
        }

        $category = $this->category($admin);
        for ($i = 1; $i <= 21; $i++) {
            $this->productThroughAction($admin, $category, "L-{$i}");
        }
        $this->assertSame(21, DB::table('products')->where('business_id', $installation->id)->count());
    }

    public function test_the_subscription_page_shows_each_allowance_with_its_own_usage(): void
    {
        $alpha = $this->owner('Alpha Ltd');
        $bravo = $this->owner('Bravo Ltd');
        $this->hire($alpha, UserRole::Manager)->assertOk();
        $category = $this->category($alpha);
        for ($i = 1; $i <= 7; $i++) {
            $this->productThroughAction($alpha, $category, "A-{$i}");
        }
        $this->hire($bravo, UserRole::SalesRep)->assertOk();

        $this->actingAs($alpha)->get(route('subscription.show'))->assertOk()
            ->assertSeeInOrder(['Managers', '1 / 1', 'Sales Representatives', '0 / 1', 'Products', '7 / 20', 'WhatsApp automation', 'Included'])
            ->assertSee('Billing setup is coming soon');
    }

    /* -------------------------------------------------------------------- helpers */

    private function owner(string $business): User
    {
        // Signup is always a guest's request.
        auth()->forgetGuards();
        app(CurrentBusiness::class)->forget();

        try {
            $owner = app(ProvisionBusiness::class)->execute([
                'business_name' => $business, 'owner_name' => 'Owner of '.$business, 'email' => Str($business)->slug().'@owner.test',
                'phone' => User::normalizePhone('0803555'.str_pad((string) (++$this->sequence), 4, '0', STR_PAD_LEFT)), 'password' => 'Secret123',
            ]);
        } finally {
            app(CurrentBusiness::class)->set(Business::query()->orderBy('id')->firstOrFail());
        }

        $owner->forceFill(['quick_pin_setup_completed' => true, 'email_verified_at' => now()])->save();

        return $owner->fresh();
    }

    private function hire(User $admin, UserRole $role): TestResponse
    {
        $n = ++$this->sequence;

        return $this->actingAs($admin)->post(route('staff.store'), [
            'name' => "Hire {$n}", 'email' => "hire{$n}@staff.test", 'phone' => '0802'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'role' => $role->value,
        ]);
    }

    private function hireAndFind(User $admin, UserRole $role): User
    {
        $this->hire($admin, $role)->assertOk();

        return User::query()->where('business_id', $admin->business_id)->where('role', $role->value)->latest('id')->firstOrFail();
    }

    /** @return array<string, int> */
    private function roles(User $owner): array
    {
        return DB::table('users')->where('business_id', $owner->business_id)->selectRaw('role, COUNT(*) AS total')->groupBy('role')->orderBy('role')->pluck('total', 'role')->map(fn ($n) => (int) $n)->all();
    }

    private function category(User $admin): ProductCategory
    {
        // Attributed to the Administrator, so the factory adds no staff account of its own.
        return $this->inBusiness($admin->business, fn () => ProductCategory::factory()->forBusiness($admin->business)->create(['created_by' => $admin->id]));
    }

    private function productThroughAction(User $admin, ProductCategory $category, string $sku): void
    {
        $this->inBusiness($admin->business, fn () => app(CreateProduct::class)->execute($admin, $this->product($category, $sku)));
    }

    /** @return array<string, mixed> */
    private function product(ProductCategory $category, string $sku, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id, 'name' => 'Product '.$sku, 'sku' => $sku, 'description' => null,
            'cost_price' => '10.00', 'selling_price' => '15.00', 'initial_stock' => '0', 'reorder_level' => '0', 'unit' => 'piece',
        ], $overrides);
    }

    private function inBusiness(Business $business, \Closure $work): mixed
    {
        return app(CurrentBusiness::class)->run($business, $work);
    }
}
