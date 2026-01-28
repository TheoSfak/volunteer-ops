<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participation_requests', function (Blueprint $table) {
            $table->boolean('points_awarded')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('participation_requests', function (Blueprint $table) {
            $table->dropColumn('points_awarded');
        });
    }
};
