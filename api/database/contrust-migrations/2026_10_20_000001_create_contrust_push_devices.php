<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contrust_push_devices', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->char('token_hash', 64)->unique();
            $table->uuid('installation_id')->unique();
            $table->text('encrypted_token');
            $table->unsignedBigInteger('token_id')->index();
            $table->uuid('user_uuid')->index();
            $table->uuid('company_uuid')->index();
            $table->string('app_identifier', 100);
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrust_push_devices');
    }
};
