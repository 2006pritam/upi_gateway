<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_status', 32)->default('pending_approval')->after('is_active');
            $table->string('subscription_requested_plan', 32)->nullable()->after('subscription_status');
            $table->string('subscription_plan', 32)->nullable()->after('subscription_requested_plan');
            $table->timestamp('subscription_approved_at')->nullable()->after('subscription_plan');
            $table->timestamp('subscription_paid_at')->nullable()->after('subscription_approved_at');
            $table->timestamp('subscription_started_at')->nullable()->after('subscription_paid_at');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_started_at');
            $table->index(['subscription_status', 'subscription_expires_at'], 'users_subscription_status_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_subscription_status_expiry_index');
            $table->dropColumn([
                'subscription_status',
                'subscription_requested_plan',
                'subscription_plan',
                'subscription_approved_at',
                'subscription_paid_at',
                'subscription_started_at',
                'subscription_expires_at',
            ]);
        });
    }
};
