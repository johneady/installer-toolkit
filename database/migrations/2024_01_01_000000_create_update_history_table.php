<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_history', function (Blueprint $table): void {
            $table->id();
            $table->string('backup_id')->nullable()->index();
            $table->string('version_from');
            $table->string('version_to');
            $table->string('status')->default('in_progress')->index();
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_history');
    }
};
