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
        Schema::table('giveaway_order', function (Blueprint $table) {
            $table->integer('amount')->default(1)->after('giveaway_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('giveaway_order', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
