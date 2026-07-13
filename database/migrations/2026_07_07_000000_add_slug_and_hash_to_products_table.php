<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // Add slug column - SEO friendly URL part
            $table->string('slug')->unique()->after('title')->nullable();

            // Add hash_id column - permanent identifier (like Jiji's random string)
            $table->string('hash_id')->unique()->after('slug')->nullable();

            // Index for faster lookups
            $table->index(['hash_id', 'slug']);
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['slug', 'hash_id']);
        });
    }
};
