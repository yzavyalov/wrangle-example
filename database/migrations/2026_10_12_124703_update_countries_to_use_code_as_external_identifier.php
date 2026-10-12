<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropUnique(['wrangle_country_id']);
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('wrangle_country_id');
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->string('code', 10)
                ->nullable(false)
                ->change();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->unsignedBigInteger('wrangle_country_id')
                ->nullable()
                ->unique();
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->string('code', 10)
                ->nullable()
                ->change();
        });
    }
};
