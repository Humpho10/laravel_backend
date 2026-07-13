<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Nullable so notifications that aren't about a specific entity (e.g. a
// staff-to-staff message with no product) don't need a fake reference_id.
// Raw SQL for the same doctrine/dbal-free reason as the messages migration.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications MODIFY reference_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications MODIFY reference_id BIGINT UNSIGNED NOT NULL');
    }
};
