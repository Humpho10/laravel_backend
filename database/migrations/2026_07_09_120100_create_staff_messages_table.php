<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A dedicated inbox for internal staff chat (Super Admin ↔ Admin /
     * Product-Manager) — kept completely separate from the buyer/seller
     * `messages` table (which is always tied to a product listing) so the
     * Super Admin's new "message the staff" feature can't interact with,
     * or accidentally expose, real customer conversations.
     */
    public function up(): void
    {
        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id('staff_message_id');
            $table->foreignId('sender_id')->constrained('users', 'id')->onDelete('cascade');
            $table->foreignId('recipient_id')->constrained('users', 'id')->onDelete('cascade');
            $table->text('message_text');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['sender_id', 'recipient_id']);
            $table->index(['recipient_id', 'sender_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
    }
};
