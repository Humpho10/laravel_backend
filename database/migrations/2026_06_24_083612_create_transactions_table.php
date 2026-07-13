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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id('transaction_id'); // Custom Primary Key
            $table->foreignId('product_id')->constrained('products', 'product_id')->onDelete('cascade');
            
            // REFERENCE MATCH: Links seller_id to 'id' column on your 'users' table
            $table->foreignId('seller_id')->constrained('users', 'id')->onDelete('cascade'); 
            
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
