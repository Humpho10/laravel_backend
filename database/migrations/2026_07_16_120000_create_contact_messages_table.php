<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id('contact_message_id');

            $table->string('name', 150);
            $table->string('email', 150);
            $table->string('topic', 100)->default('General inquiry');
            $table->text('message');

            // 'new' | 'read' | 'replied'
            $table->string('status')->default('new');

            $table->text('reply_message')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users', 'id')->nullOnDelete();

            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
