<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('description', 1000)->nullable();
            $table->string('button_label', 30)->nullable();
            $table->string('theme_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', fn (Blueprint $t) => $t->dropColumn(['description', 'button_label', 'theme_color']));
    }
};
