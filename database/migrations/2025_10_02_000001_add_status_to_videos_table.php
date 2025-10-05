<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            if (!Schema::hasColumn('videos', 'status')) {
                $table->string('status', 20)->nullable();
                $table->index('status', 'videos_status_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            if (Schema::hasColumn('videos', 'status')) {
                $table->dropIndex('videos_status_index');
                $table->dropColumn('status');
            }
        });
    }
};


