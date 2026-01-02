<?php

declare(strict_types = 1);

use Illuminate\Database\Schema\Blueprint;

return new class extends Illuminate\Database\Migrations\Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('versioned_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('body');
            // Add custom field to test with attribute.
            $table->versionable('version_field');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('versioned_posts');
    }
};
