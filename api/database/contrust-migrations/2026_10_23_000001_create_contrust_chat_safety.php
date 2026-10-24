<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contrust_chat_consents', function (Blueprint $table) {
            $table->uuid('company_uuid');
            $table->uuid('user_uuid');
            $table->string('policy_version', 40);
            $table->timestamp('accepted_at');
            $table->primary(['company_uuid', 'user_uuid']);
        });
        Schema::create('contrust_chat_blocks', function (Blueprint $table) {
            $table->uuid('company_uuid');
            $table->uuid('user_uuid');
            $table->uuid('blocked_user_uuid');
            $table->timestamp('created_at');
            $table->primary(['company_uuid', 'user_uuid', 'blocked_user_uuid']);
            $table->index(['company_uuid', 'blocked_user_uuid']);
        });
        Schema::create('contrust_chat_reports', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('company_uuid');
            $table->uuid('reporter_uuid');
            $table->uuid('target_user_uuid');
            $table->uuid('chat_message_uuid')->nullable();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->text('encrypted_context');
            $table->string('reason', 24);
            $table->string('status', 20)->default('open');
            $table->uuid('reviewer_uuid')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_uuid', 'reporter_uuid', 'request_id'], 'contrust_chat_report_request');
            $table->index(['company_uuid', 'status', 'created_at']);
            $table->index('target_user_uuid');
            $table->index('reviewer_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrust_chat_reports');
        Schema::dropIfExists('contrust_chat_blocks');
        Schema::dropIfExists('contrust_chat_consents');
    }
};
