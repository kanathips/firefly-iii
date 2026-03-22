<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the two extension tax tables:
 *   extension_tax_profiles   – one tax configuration per user per tax year
 *   extension_tax_tag_links  – links a Tag to a TaxProfile for deductibility
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('extension_tax_profiles', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('name', 255);
            $table->unsignedSmallInteger('tax_year');
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'tax_year'], 'idx_tax_profiles_user_year');
        });

        Schema::create('extension_tax_tag_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tax_profile_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();

            $table->foreign('tax_profile_id')->references('id')->on('extension_tax_profiles')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
            $table->unique(['tax_profile_id', 'tag_id'], 'uq_tax_tag_link');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_tax_tag_links');
        Schema::dropIfExists('extension_tax_profiles');
    }
};
