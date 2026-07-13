<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Nullable, so a message can be a general staff conversation (e.g. Product
// Manager <-> Admin, Super Admin <-> Admin) that isn't about any listing.
// Uses raw SQL rather than Schema::table(...)->change() since this project
// doesn't have doctrine/dbal installed.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages MODIFY product_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages MODIFY product_id BIGINT UNSIGNED NOT NULL');
    }
};
