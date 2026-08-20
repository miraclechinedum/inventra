<?php

namespace Tests\Feature\Auth;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityEventLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_event_metadata_is_scalar_and_allowlisted(): void
    {
        app(SecurityEventRecorder::class)->record('test_event', null, [
            'route' => 'dashboard',
            'reason' => ['nested-secret'],
            'channel' => true,
            'password' => 'NeverStoreThis9',
            'token' => 'secret-token',
        ]);

        $event = SecurityEvent::query()->firstOrFail();
        $this->assertSame(['route' => 'dashboard', 'channel' => true], $event->metadata);
        $this->assertStringNotContainsString('NeverStoreThis9', $event->toJson());
        $this->assertStringNotContainsString('secret-token', $event->toJson());
    }

    public function test_login_failure_event_does_not_store_submitted_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['identifier' => $user->email, 'password' => 'ExposedSecret9']);

        $event = SecurityEvent::query()->where('event', 'login_failure')->firstOrFail();
        $this->assertStringNotContainsString('ExposedSecret9', $event->toJson());
    }

    public function test_prunable_query_selects_only_events_outside_retention_period(): void
    {
        config()->set('auth_security.security_events.retention_days', 90);
        $old = SecurityEvent::query()->create(['event' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        $recent = SecurityEvent::query()->create(['event' => 'recent']);
        $recent->forceFill(['created_at' => now()->subDays(89)])->save();

        $ids = (new SecurityEvent)->prunable()->pluck('id');

        $this->assertTrue($ids->contains($old->id));
        $this->assertFalse($ids->contains($recent->id));
    }
}
