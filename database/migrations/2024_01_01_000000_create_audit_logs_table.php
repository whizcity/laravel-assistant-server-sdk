<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->string('service', 64)->index();
            $table->string('action', 16);                 // create / update / delete
            $table->string('resource', 64)->index();
            $table->string('resource_id', 64)->index();
            $table->string('operator_id', 64)->nullable()->index();
            $table->string('operator_name', 64)->nullable();
            $table->string('operator_ip', 45)->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('user_agent')->nullable();
            $table->tinyInteger('synced')->default(0)->index();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['synced', 'id']);
            $table->index(['synced', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
