<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('title', 100);
            $table->decimal('amount', 12, 2)->nullable(); // null = customer enters the amount
            $table->string('note', 50)->nullable();
            $table->unsignedBigInteger('payment_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_link_id')->nullable()->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id', 64)->nullable();
            $table->boolean('telegram_alerts')->default(false);
            $table->boolean('daily_summary')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['telegram_bot_token', 'telegram_chat_id', 'telegram_alerts', 'daily_summary']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('payment_link_id'));
        Schema::dropIfExists('payment_links');
    }
};
