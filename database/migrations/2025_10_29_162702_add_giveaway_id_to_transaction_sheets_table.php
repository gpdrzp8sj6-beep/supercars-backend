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
            $table->foreignId('giveaway_id')->nullable()->constrained()->after('file_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_sheets', function (Blueprint $table) {
            $table->dropForeign(['giveaway_id']);
            $table->dropColumn('giveaway_id');
        });
    }
};
