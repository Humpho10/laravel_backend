<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_ratings', function (Blueprint $table) {
            $table->id('rating_id');

            // The seller being rated and the buyer giving the rating
            $table->foreignId('seller_id')->constrained('users', 'id')->onDelete('cascade');
            $table->foreignId('buyer_id')->constrained('users', 'id')->onDelete('cascade');

            // 1..5 star score
            $table->unsignedTinyInteger('rating');

            $table->timestamps();

            // One rating per buyer per seller (updatable)
            $table->unique(['seller_id', 'buyer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_ratings');
    }
};
