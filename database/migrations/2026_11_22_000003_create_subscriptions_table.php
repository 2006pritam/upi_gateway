<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan_name', 100);
            $table->decimal('amount', 12, 2);
            $table->string('interval', 10); // weekly | monthly | yearly
            $table->string('customer_name', 100);
            $table->string('customer_email')->nullable();
            $table->string('customer_mobile', 20)->nullable();
            $table->date('next_due_at');
            $table->string('status', 10)->default('active'); // active | paused | cancelled
            $table->unsignedBigInteger('payment_account_id')->nullable();
            $table->unsignedBigInteger('last_order_id')->nullable();
            $table->timestamp('last_billed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
