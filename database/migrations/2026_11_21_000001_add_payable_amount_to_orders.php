<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // What the customer actually pays. Equals `amount` plus a few paise when another
            // open order on the same e-mail-verified account already uses that exact amount.
            $table->decimal('payable_amount', 12, 2)->nullable()->after('amount');
            $table->index(['payment_account_id', 'payable_amount'], 'orders_account_payable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_account_payable_idx');
            $table->dropColumn('payable_amount');
        });
    }
};
