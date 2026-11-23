<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra webhook endpoints per merchant. The original users.webhook_url /
     * users.sandbox_webhook_url columns stay as each environment's primary
     * endpoint, so existing integrations and settings keep working untouched.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 16)->default('live');
            $table->string('url', 500);
            $table->timestamps();

            $table->index(['user_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
