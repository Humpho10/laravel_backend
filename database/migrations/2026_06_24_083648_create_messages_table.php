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
        Schema::create('messages', function (Blueprint $table) {
            $table->id('message_id'); // Custom Primary Key
            $table->foreignId('product_id')->constrained('products', 'product_id')->onDelete('cascade');
            
            // REFERENCE MATCHES: Both sender and recipient link back to 'id' on your 'users' table
            $table->foreignId('sender_id')->constrained('users', 'id')->onDelete('cascade');
            $table->foreignId('recipient_id')->constrained('users', 'id')->onDelete('cascade');
            
            $table->text('message_text');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
