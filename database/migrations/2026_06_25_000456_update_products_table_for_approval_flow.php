<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Change default status from active to pending
            $table->string('status')->default('pending')->change();
            // Add approval flow columns
            $table->text('rejection_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id')->nullOnDelete()->after('rejection_reason');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->timestamp('resubmitted_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
            $table->dropColumn([
                'rejection_reason',
                'reviewed_by',
                'reviewed_at',
                'resubmitted_at',
            ]);
        });
    }
};