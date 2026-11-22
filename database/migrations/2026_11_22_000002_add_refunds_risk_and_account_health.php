<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 200);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('created_ip', 45)->nullable();
            $table->string('risk_level', 10)->nullable();
            $table->json('risk_flags')->nullable();
        });

        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('unhealthy_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_accounts', fn (Blueprint $t) => $t->dropColumn(['failure_count', 'unhealthy_until']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['created_ip', 'risk_level', 'risk_flags']));
        Schema::dropIfExists('refunds');
    }
};
