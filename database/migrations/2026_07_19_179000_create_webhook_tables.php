<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained(indexName: 'webhook_endpoints_user_fk')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id'], 'webhook_endpoints_owner_index');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')
                ->constrained(indexName: 'webhook_deliveries_endpoint_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(indexName: 'webhook_deliveries_user_fk')
                ->cascadeOnDelete();
            $table->string('event_type');
            $table->string('status', 20);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'id'], 'webhook_deliveries_endpoint_index');
            $table->index(['user_id', 'id'], 'webhook_deliveries_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
