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
        Schema::create('giveaways', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('description');
            $table->json('images')->nullable();
            $table->timestamp('closes_at');
            $table->decimal('price', 8, 2);
            $table->integer('ticketsTotal');
            $table->integer('ticketsPerUser');
            $table->boolean('autoDraw')->default(true);
            $table->boolean('ticketsTotalHidden')->default(false);
            $table->integer('manyWinners')->default(1);
            $table->decimal('alternative_prize', 8, 2);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE giveaways AUTO_INCREMENT = 678421');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('giveaways');
    }
};
