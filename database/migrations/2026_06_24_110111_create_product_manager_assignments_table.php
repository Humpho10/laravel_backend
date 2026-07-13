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
    Schema::create('product_manager_assignments', function (Blueprint $table) {
        $table->id();
        
        $table->foreignId('product_manager_id')
              ->constrained('users')
              ->onDelete('cascade');
              
        // ── THE CRITICAL FIX ──────────────────────────────────
        // Added 'category_id' as the second argument here:
        $table->foreignId('category_id')
              ->constrained('categories', 'category_id') 
              ->onDelete('cascade');
              
        $table->unique(['product_manager_id', 'category_id'], 'pm_category_unique');
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_manager_assignments');
    }
};
