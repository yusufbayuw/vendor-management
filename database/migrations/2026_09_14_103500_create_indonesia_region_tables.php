<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = (string) config('laravolt.indonesia.table_prefix', 'indonesia_');

        if (! Schema::hasTable($prefix.'provinces')) {
            Schema::create($prefix.'provinces', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('code', 2)->unique();
                $table->string('name', 255);
                $table->text('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix.'cities')) {
            Schema::create($prefix.'cities', function (Blueprint $table) use ($prefix): void {
                $table->bigIncrements('id');
                $table->char('code', 4)->unique();
                $table->char('province_code', 2)->index();
                $table->string('name', 255);
                $table->text('meta')->nullable();
                $table->timestamps();

                $table->foreign('province_code')
                    ->references('code')
                    ->on($prefix.'provinces')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable($prefix.'districts')) {
            Schema::create($prefix.'districts', function (Blueprint $table) use ($prefix): void {
                $table->bigIncrements('id');
                $table->char('code', 7)->unique();
                $table->char('city_code', 4)->index();
                $table->string('name', 255);
                $table->text('meta')->nullable();
                $table->timestamps();

                $table->foreign('city_code')
                    ->references('code')
                    ->on($prefix.'cities')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable($prefix.'villages')) {
            Schema::create($prefix.'villages', function (Blueprint $table) use ($prefix): void {
                $table->bigIncrements('id');
                $table->char('code', 10)->unique();
                $table->char('district_code', 7)->index();
                $table->string('name', 255);
                $table->text('meta')->nullable();
                $table->timestamps();

                $table->foreign('district_code')
                    ->references('code')
                    ->on($prefix.'districts')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $prefix = (string) config('laravolt.indonesia.table_prefix', 'indonesia_');

        Schema::dropIfExists($prefix.'villages');
        Schema::dropIfExists($prefix.'districts');
        Schema::dropIfExists($prefix.'cities');
        Schema::dropIfExists($prefix.'provinces');
    }
};
