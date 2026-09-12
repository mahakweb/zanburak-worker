<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            if (!Schema::hasColumn('videos', 'progress')) {
                $table->unsignedTinyInteger('progress')->nullable()->after('status');
            }
            if (!Schema::hasColumn('videos', 'error_message')) {
                $table->text('error_message')->nullable()->after('progress');
            }
            if (!Schema::hasColumn('videos', 'processing_started_at')) {
                $table->timestamp('processing_started_at')->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('videos', 'progress') ? 'progress' : null,
                Schema::hasColumn('videos', 'error_message') ? 'error_message' : null,
                Schema::hasColumn('videos', 'processing_started_at') ? 'processing_started_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
