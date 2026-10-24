<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contrust_chat_reports', function (Blueprint $table) {
            $table->uuid('target_user_uuid')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A schema rollback must never discard legitimate anonymous reports.
        if (DB::table('contrust_chat_reports')->whereNull('target_user_uuid')->exists()) {
            throw new RuntimeException('Unattributed reports must be retained; schema downgrade unavailable.');
        }
        Schema::table('contrust_chat_reports', function (Blueprint $table) {
            $table->uuid('target_user_uuid')->nullable(false)->change();
        });
    }
};
