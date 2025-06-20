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
       Schema::create('orders', function (Blueprint $table) {
           $table->id();
           $table->foreignId('user_id')->constrained()->onDelete('cascade');
           $table->enum('status', ['completed', 'pending', 'cancelled', 'failed'])->default('pending');
           $table->double('total');
           $table->string('checkoutId')->nullable();

           // Add the extra user/address fields
           $table->string('forenames');
           $table->string('surname');
           $table->string('phone');
           $table->string('address_line_1');
           $table->string('address_line_2')->nullable();
           $table->string('city');
           $table->string('post_code');
           $table->string('country');

           $table->timestamps();
       });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
