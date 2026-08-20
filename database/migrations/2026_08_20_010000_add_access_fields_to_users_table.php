<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->unique()->after('email');
            $table->enum('role', array_column(UserRole::cases(), 'value'))->after('password');
            $table->enum('status', array_column(UserStatus::cases(), 'value'))
                ->default(UserStatus::Active->value)
                ->after('role');
            $table->boolean('force_password_change')->default(false)->after('status');
            $table->string('quick_pin_hash')->nullable()->after('force_password_change');
            $table->boolean('quick_pin_setup_completed')->default(false)->after('quick_pin_hash');
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('quick_pin_setup_completed');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_failed_login_at')->nullable()->after('locked_until');
            $table->timestamp('last_login_at')->nullable()->after('last_failed_login_at');
            $table->ipAddress('last_login_ip')->nullable()->after('last_login_at');
            $table->foreignId('created_by')->nullable()->after('last_login_ip')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropUnique(['phone']);
            $table->dropColumn([
                'phone',
                'role',
                'status',
                'force_password_change',
                'quick_pin_hash',
                'quick_pin_setup_completed',
                'failed_login_attempts',
                'locked_until',
                'last_failed_login_at',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
