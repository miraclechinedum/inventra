<?php

namespace Tests\Feature\BusinessSettings;

use App\Actions\Settings\SetBusinessLogo;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ImageStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The logo's row, its audit evidence and the files on disk never disagree, whichever step fails.
 */
class BusinessLogoIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_attaching_and_replacing_commit_the_path_and_its_audit_together(): void
    {
        $first = $this->action()->store($this->admin, $this->image())->logo_path;
        $second = $this->action()->store($this->admin, $this->image())->logo_path;

        $this->assertSame($second, DB::table('business_settings')->value('logo_path'));
        $this->assertTrue($this->images()->exists($second));
        $this->assertFalse($this->images()->exists($first), 'The replaced file goes once the change has committed');

        $replaced = DB::table('audit_logs')->where('action', 'business_logo_replaced')->sole();
        $this->assertSame(['logo_path' => $first], json_decode($replaced->old_values, true));
        $this->assertSame(['logo_path' => $second], json_decode($replaced->new_values, true));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'business_logo_attached')->count());
    }

    public function test_a_failed_audit_on_replace_keeps_the_current_logo_and_leaves_no_orphan(): void
    {
        $current = $this->action()->store($this->admin, $this->image())->logo_path;
        $auditRows = DB::table('audit_logs')->count();
        $this->failAudits();

        try {
            $this->action()->store($this->admin, $this->image());
            $this->fail('A logo change must not commit without its audit evidence.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($current, DB::table('business_settings')->value('logo_path'));
        $this->assertTrue($this->images()->exists($current), 'The logo in use must survive a failed replacement');
        $this->assertSame([$current], Storage::disk('local')->files(ImageStore::BUSINESS), 'The attempted file must not be left behind');
        $this->assertSame($auditRows, DB::table('audit_logs')->count());
    }

    public function test_a_failed_audit_on_remove_keeps_the_row_and_the_file(): void
    {
        $current = $this->action()->store($this->admin, $this->image())->logo_path;
        $auditRows = DB::table('audit_logs')->count();
        $this->failAudits();

        try {
            $this->action()->remove($this->admin);
            $this->fail('A logo removal must not commit without its audit evidence.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($current, DB::table('business_settings')->value('logo_path'));
        $this->assertTrue($this->images()->exists($current), 'The file must not be deleted before the removal commits');
        $this->assertSame($auditRows, DB::table('audit_logs')->count());
    }

    public function test_removing_commits_the_cleared_path_and_its_audit_then_deletes_the_file(): void
    {
        $current = $this->action()->store($this->admin, $this->image())->logo_path;

        $this->assertNull($this->action()->remove($this->admin)->logo_path);

        $this->assertNull(DB::table('business_settings')->value('logo_path'));
        $this->assertFalse($this->images()->exists($current));
        $removed = DB::table('audit_logs')->where('action', 'business_logo_removed')->sole();
        $this->assertSame(['logo_path' => $current], json_decode($removed->old_values, true));

        // Removing again is a no-op: nothing to change, nothing to audit.
        $this->action()->remove($this->admin);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'business_logo_removed')->count());
    }

    private function action(): SetBusinessLogo
    {
        return app(SetBusinessLogo::class);
    }

    private function images(): ImageStore
    {
        return app(ImageStore::class);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', 200, 200);
    }

    private function failAudits(): void
    {
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, ?User $actor,
                array $oldValues = [], array $newValues = [], array $metadata = [],
                bool $explicitDiff = false): void
            {
                throw new RuntimeException('audit unavailable');
            }
        });
    }
}
