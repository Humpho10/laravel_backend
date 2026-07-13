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
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->id('audit_id'); // Custom Primary Key
            
            // REFERENCE MATCH: Links user_id to 'id' column on your 'users' table
            $table->foreignId('user_id')->constrained('users', 'id')->onDelete('cascade');
            
            $table->string('action');
            $table->string('table_name');
            $table->unsignedBigInteger('record_id');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_trails');
    }
};
