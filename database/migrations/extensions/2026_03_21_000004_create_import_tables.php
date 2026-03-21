<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the extension_import_previews table.
 *
 * Previews are lightweight records that link a UUID preview token to a user.
 * The full transaction payload is stored in the application cache (not here).
 * This table exists primarily for audit and cleanup purposes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('extension_import_previews', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->uuid('preview_token')->unique();
            $table->string('source_filename', 255)->nullable();
            $table->string('bank_format', 64)->default('generic');
            $table->unsignedSmallInteger('transaction_count')->default(0);
            $table->unsignedSmallInteger('duplicate_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'confirmed_at'], 'idx_import_previews_user_confirmed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_import_previews');
    }
};
