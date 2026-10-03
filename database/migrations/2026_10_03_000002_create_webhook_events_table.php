<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every webhook the gateway has sent us, kept.
 *
 * Copied from the school's own fees site, with one addition: a unique index over
 * provider, event type and reference. Paystack retries, and a webhook that runs
 * twice must not credit a parent's money twice — the index makes the second
 * arrival impossible to process a second time rather than relying on the handler
 * remembering to check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('paystack');
            $table->string('event_type');
            $table->string('reference')->nullable();
            $table->json('payload');

            // pending|processed|failed|ignored
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_type', 'reference'], 'webhook_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
