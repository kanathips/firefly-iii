<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the extension push_subscriptions table:
 *   push_subscriptions – browser VAPID push subscriptions per user
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_subscriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('endpoint');
            $table->text('p256dh_key');
            $table->string('auth_key', 255);
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id', 'idx_push_subscriptions_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
