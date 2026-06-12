<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('telegram_payments', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
            $table->uuid('subscription_id')->nullable()->change();
            $table->uuid('telegram_group_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_payments', function (Blueprint $table) {
            $table->uuid('user_id')->nullable(false)->change();
            $table->uuid('subscription_id')->nullable(false)->change();
            $table->uuid('telegram_group_id')->nullable(false)->change();
        });
    }
};
