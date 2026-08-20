<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_normalized_and_mysql_uniqueness_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'MixedCase@example.com']);

        $this->assertSame('mixedcase@example.com', $user->email);
        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'MIXEDCASE@example.com']);
    }
}
