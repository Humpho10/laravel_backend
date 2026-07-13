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
        Schema::create('products', function (Blueprint $table) {
            $table->id('product_id'); // Custom Primary Key
            
            // REFERENCE MATCH: Links seller_id to 'id' column on your 'users' table
            $table->foreignId('seller_id')->constrained('users', 'id')->onDelete('cascade'); 
            
            // References to your custom categories and sub_categories
            $table->foreignId('category_id')->constrained('categories', 'category_id')->onDelete('cascade');
            $table->foreignId('subcategory_id')->constrained('sub_categories', 'subcategory_id')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description');
            $table->string('condition');
            $table->decimal('price', 10, 2);
            $table->text('specification')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
