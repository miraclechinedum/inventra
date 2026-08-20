<?php

namespace Tests\Feature\Staff;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_admin_can_access_staff_management(): void
    {
        $this->get('/staff')->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/staff')->assertOk()->assertSee('Staff accounts');
    }

    public function test_manager_and_sales_rep_are_forbidden_from_every_staff_capability(): void
    {
        $target = User::factory()->create();

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor);

            foreach ([
                ['GET', '/staff', []],
                ['GET', '/staff/create', []],
                ['GET', "/staff/{$target->id}", []],
                ['GET', "/staff/{$target->id}/edit", []],
                ['GET', "/staff/{$target->id}/activity", []],
                ['POST', '/staff', $this->validPayload()],
                ['PUT', "/staff/{$target->id}", $this->validPayload()],
                ['POST', "/staff/{$target->id}/role", ['role' => UserRole::Manager->value]],
                ['POST', "/staff/{$target->id}/activate", []],
                ['POST', "/staff/{$target->id}/deactivate", []],
                ['POST', "/staff/{$target->id}/lock", []],
                ['POST', "/staff/{$target->id}/unlock", []],
                ['POST', "/staff/{$target->id}/require-password-change", []],
                ['POST', "/staff/{$target->id}/revoke-sessions", []],
            ] as [$method, $uri, $data]) {
                $this->call($method, $uri, $data)->assertForbidden();
            }
        }
    }

    public function test_admin_cannot_perform_self_destructive_actions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $this->post(route('staff.deactivate', $admin))->assertForbidden();
        $this->post(route('staff.lock', $admin))->assertForbidden();
        $this->post(route('staff.role', $admin), ['role' => UserRole::Manager->value])->assertForbidden();
        $this->post(route('staff.revoke-sessions', $admin))->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_an_admin_cannot_mutate_another_admin_through_any_staff_lifecycle_action(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $subject = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($actor);

        foreach ([
            ['PUT', route('staff.update', $subject), ['name' => 'Changed', 'email' => $subject->email, 'phone' => $subject->phone]],
            ['POST', route('staff.role', $subject), ['role' => UserRole::Manager->value]],
            ['POST', route('staff.activate', $subject), []],
            ['POST', route('staff.deactivate', $subject), []],
            ['POST', route('staff.lock', $subject), []],
            ['POST', route('staff.unlock', $subject), []],
            ['POST', route('staff.require-password-change', $subject), []],
            ['POST', route('staff.revoke-sessions', $subject), []],
        ] as [$method, $uri, $payload]) {
            $this->call($method, $uri, $payload)->assertForbidden();
        }

        $this->assertSame(UserRole::Admin, $subject->fresh()->role);
    }

    private function validPayload(): array
    {
        return [
            'name' => 'Denied Staff',
            'email' => 'denied@example.com',
            'phone' => '08012345678',
            'role' => UserRole::Manager->value,
        ];
    }
}
