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
        Schema::table('transaction_sheets', function (Blueprint $table) {
            $table->json('summary')->nullable()->change();
            $table->json('details')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_sheets', function (Blueprint $table) {
            $table->json('summary')->nullable(false)->change();
            $table->json('details')->nullable(false)->change();
        });
    }
};
