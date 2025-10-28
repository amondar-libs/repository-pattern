<?php

declare( strict_types = 1 );

use Illuminate\Database\Schema\Blueprint;

return new class extends Illuminate\Database\Migrations\Migration
{
    /**
     * Run the migrations.
     */
    public function up() : void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('first_line');
            $table->string('zip_code');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down() : void
    {
        Schema::dropIfExists('user_addresses');
    }

};
