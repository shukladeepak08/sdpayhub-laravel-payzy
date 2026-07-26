<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payzy_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('fingerprint', 64);
            $table->longText('response');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payzy_idempotency_keys');
    }
};
