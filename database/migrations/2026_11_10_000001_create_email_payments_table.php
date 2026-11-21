<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per payment-received email ingested for an e-mail verified account.
        // The unique keys make ingestion idempotent: the same e-mail or the same UTR
        // can never pay two orders.
        Schema::create('email_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_id', 255);
            $table->string('utr', 40);
            $table->string('txn_id', 60)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('payer_name', 120)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 20)->default('unmatched'); // matched | unmatched | ambiguous
            $table->timestamps();

            $table->unique(['payment_account_id', 'message_id']);
            $table->unique(['user_id', 'utr']);
            $table->index(['payment_account_id', 'status', 'amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_payments');
    }
};
