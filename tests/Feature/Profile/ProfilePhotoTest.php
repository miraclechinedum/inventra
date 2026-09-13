<?php

namespace Tests\Feature\Profile;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff profile photographs. The point of the feature is that a Manager or Sales Rep can set their
 * own picture without an Admin doing it for them, so these tests centre on that self-service
 * boundary: your photo is yours to choose, an Admin may only remove one, and nobody outside those
 * two roles can read someone else's.
 */
class ProfilePhotoTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $written = [];

    protected function tearDown(): void
    {
        $store = app(ImageStore::class);

        foreach ($this->written as $path) {
            $store->delete($path);
        }

        $this->written = [];
        parent::tearDown();
    }

    private function png(string $name = 'me.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 300, 300);
    }

    private function upload(User $user): User
    {
        $this->actingAs($user)
            ->post(route('profile.photo.store'), ['photo' => $this->png()])
            ->assertRedirect();

        $user = $user->fresh();

        if ($user->photo_path !== null) {
            $this->written[] = $user->photo_path;
        }

        return $user;
    }

    // ───────────────────────────────── self-service, every role ─────────────────────────────────

    /** @return array<string, array{0: UserRole}> */
    public static function roles(): array
    {
        return [
            'manager' => [UserRole::Manager],
            'sales rep' => [UserRole::SalesRep],
            'admin' => [UserRole::Admin],
        ];
    }

    #[DataProvider('roles')]
    public function test_any_role_can_set_and_remove_their_own_photo(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $store = app(ImageStore::class);

        $user = $this->upload($user);

        $this->assertNotNull($user->photo_path, $role->value.' must be able to upload a photo');
        $this->assertTrue($store->exists($user->photo_path));
        $this->assertStringStartsWith(ImageStore::STAFF.'/', $user->photo_path);

        $path = $user->photo_path;
        $this->actingAs($user)->delete(route('profile.photo.destroy'))->assertRedirect();

        $this->assertNull($user->fresh()->photo_path);
        $this->assertFalse($store->exists($path));
    }

    public function test_the_profile_page_is_reachable_by_a_sales_rep(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $html = $this->actingAs($rep)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Profile photo', $html);
        $this->assertStringContainsString('Upload photo', $html);
        $this->assertStringContainsString('No image for '.$rep->name, $html);
    }

    public function test_the_sidebar_avatar_links_to_the_profile_and_shows_the_photo(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $before = $this->actingAs($rep)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString(route('profile.edit'), $before);
        $this->assertStringNotContainsString(route('users.photo', $rep), $before);

        $rep = $this->upload($rep);

        $after = $this->actingAs($rep)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString(route('users.photo', $rep), $after);
    }

    // ───────────────────────────────── who may choose a photo ───────────────────────────────────

    public function test_an_admin_cannot_choose_a_photo_for_someone_else(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        // There is deliberately no route for it; the ability itself must also refuse.
        $this->assertFalse($admin->can('updatePhoto', $rep));
        $this->assertTrue($rep->can('updatePhoto', $rep));
    }

    public function test_an_admin_may_remove_an_inappropriate_photo(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $rep = $this->upload($rep);
        $path = $rep->photo_path;

        $this->actingAs($admin)->delete(route('staff.photo.destroy', $rep))->assertRedirect();

        $this->assertNull($rep->fresh()->photo_path);
        $this->assertFalse(app(ImageStore::class)->exists($path));
    }

    public function test_a_manager_cannot_remove_another_persons_photo(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $rep = $this->upload($rep);

        // staff.* is an Admin-only route group, so a Manager cannot even reach it.
        $this->actingAs($manager)->delete(route('staff.photo.destroy', $rep))->assertForbidden();
        $this->assertNotNull($rep->fresh()->photo_path);
    }

    // ──────────────────────────────────────── serving ───────────────────────────────────────────

    public function test_a_photo_is_readable_only_by_its_owner_and_an_admin(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $rep = $this->upload($rep);
        $url = route('users.photo', $rep);

        // The owner.
        $response = $this->actingAs($rep)->get($url)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl,
            'a photograph of a person must never be publicly cacheable');
        $this->assertStringNotContainsString('public', $cacheControl);

        // An Admin, who governs the account.
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get($url)->assertOk();

        // A peer, who has no business with it.
        $this->actingAs(User::factory()->create(['role' => UserRole::SalesRep]))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))->get($url)->assertForbidden();
    }

    public function test_a_guest_cannot_read_a_photo(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $rep = $this->upload($rep);

        $this->post(route('logout'));
        $this->get(route('users.photo', $rep))->assertRedirect(route('login'));
    }

    public function test_an_account_without_a_photo_returns_not_found(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->actingAs($rep)->get(route('users.photo', $rep))->assertNotFound();
    }

    // ─────────────────────────────────────── validation ─────────────────────────────────────────

    public function test_a_disguised_non_image_is_rejected(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $file = UploadedFile::fake()->createWithContent('avatar.png', '<?php echo "pwned"; ?>');

        $this->actingAs($rep)->post(route('profile.photo.store'), ['photo' => $file])
            ->assertSessionHasErrors('photo');

        $this->assertNull($rep->fresh()->photo_path);
    }

    public function test_an_oversized_photo_is_rejected(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $tooBig = UploadedFile::fake()->image('big.jpg', 500, 500)->size(3072);

        $this->actingAs($rep)->post(route('profile.photo.store'), ['photo' => $tooBig])
            ->assertSessionHasErrors('photo');

        $this->assertNull($rep->fresh()->photo_path);
    }

    public function test_the_server_names_the_file(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($rep)->post(route('profile.photo.store'), [
            'photo' => $this->png('../../../etc/passwd.png'),
        ])->assertRedirect();

        $rep = $rep->fresh();
        $this->written[] = $rep->photo_path;

        $this->assertMatchesRegularExpression('#^'.ImageStore::STAFF.'/[A-Za-z0-9]{40}\.png$#D', $rep->photo_path);
        $this->assertStringNotContainsString('..', $rep->photo_path);
        $this->assertStringNotContainsString('passwd', $rep->photo_path);
    }

    // ───────────────────────────────── replacement & audit ──────────────────────────────────────

    public function test_replacing_a_photo_removes_the_old_file(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $store = app(ImageStore::class);

        $rep = $this->upload($rep);
        $first = $rep->photo_path;
        $rep = $this->upload($rep);
        $second = $rep->photo_path;

        $this->assertNotSame($first, $second);
        $this->assertFalse($store->exists($first));
        $this->assertTrue($store->exists($second));
    }

    public function test_photo_changes_are_audited_against_the_acting_user(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $rep = $this->upload($rep);
        $attached = AuditLog::query()->where('action', 'user_photo_attached')->sole();
        $this->assertSame($rep->id, $attached->actor_id, 'a self-service upload is the owner\'s action');

        $this->actingAs($admin)->delete(route('staff.photo.destroy', $rep));
        $this->assertStringStartsWith(ImageStore::STAFF.'/', $attached->new_values['photo_path']);

        $removed = AuditLog::query()->where('action', 'user_photo_removed')->sole();
        $this->assertSame($admin->id, $removed->actor_id, 'a moderation removal names the Admin');
        $this->assertNotNull($removed->old_values['photo_path']);
        $this->assertNull($removed->new_values['photo_path']);
    }

    public function test_the_staff_pages_show_photos_to_an_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Chidi Okonkwo']);
        $rep = $this->upload($rep);

        $index = $this->actingAs($admin)->get(route('staff.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('users.photo', $rep), $index);

        $show = $this->actingAs($admin)->get(route('staff.show', $rep))->assertOk()->getContent();
        $this->assertStringContainsString(route('users.photo', $rep), $show);
        $this->assertStringContainsString('Remove photo', $show);
    }
}
