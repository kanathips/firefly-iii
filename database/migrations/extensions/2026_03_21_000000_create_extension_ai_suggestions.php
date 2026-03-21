<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('extension_ai_suggestions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('transaction_journal_id')->nullable();
            $table->string('suggested_category')->nullable();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('transaction_journal_id')
                ->references('id')
                ->on('transaction_journals')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_ai_suggestions');
    }
};
