<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\CreateStaff;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Renders the Add Staff screens to files for inspection in a real browser.
 *
 * Skipped unless STAFF_SNAPSHOT_DIR is set, so it costs nothing in an ordinary run. It asserts
 * nothing about appearance — a test cannot see — it only produces the artefacts a human looks at.
 * The test database is used; the development database is never written to.
 */
class StaffCreateRenderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_the_add_staff_screens(): void
    {
        $directory = env('STAFF_SNAPSHOT_DIR');

        if (! is_string($directory) || $directory === '') {
            $this->markTestSkipped('Set STAFF_SNAPSHOT_DIR to render the screens.');
        }

        $this->assertSame('inventra_test', DB::connection()->getDatabaseName());

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $this->save($directory, 'staff-create', $this->get(route('staff.create'))->getContent());

        // The duplicate-email state, produced by a real refused submission.
        User::factory()->create(['email' => 'taken@business.com']);
        $this->from(route('staff.create'))->post(route('staff.store'), [
            'name' => 'Ade Bello', 'email' => 'taken@business.com',
            'phone' => '08012345678', 'role' => 'sales_rep',
        ]);
        $this->save($directory, 'staff-create-error', $this->get(route('staff.create'))->getContent());

        // The one-time reveal, rendered from a genuinely created account.
        $result = app(CreateStaff::class)->execute(
            $admin,
            ['name' => 'Funke Ojo', 'email' => 'funke@business.com', 'phone' => null],
            UserRole::Manager,
        );
        $this->save($directory, 'staff-created', view('staff.created', [
            'staffMember' => $result['user'],
            'temporaryPassword' => $result['temporary_password'],
        ])->render());

        $this->addToAssertionCount(1);
    }

    private function save(string $directory, string $name, string $html): void
    {
        // Written only where it was asked for. Serving the artefact over HTTP, so the compiled
        // stylesheet resolves, is the caller's business — this leaves nothing in the repository.
        $html = str_replace(['http://localhost/build/', 'http://inventra.test/build/'], '/build/', $html);

        file_put_contents(rtrim($directory, '/').'/'.$name.'.html', $html);
    }
}
