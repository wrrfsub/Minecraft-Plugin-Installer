<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_plugin_installer_plugins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('file');
            $table->string('source', 16);
            $table->string('project_id');
            $table->string('name');
            $table->string('version')->nullable();
            $table->text('icon')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'file']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_plugin_installer_plugins');
    }
};
