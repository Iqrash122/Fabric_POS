<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false);
        });

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Fabric POS');
            $table->string('currency', 3)->default('PKR');
            $table->string('timezone')->default('Asia/Karachi');
            $table->string('country', 2)->default('PK');
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->text('invoice_footer')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('credit_delivery')->default(false);
            $table->boolean('cost_reporting')->default(false);
            $table->unsignedInteger('recent_days')->default(30);
            $table->unsignedInteger('refresh_seconds')->default(60);
            $table->text('whatsapp_template')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('normalized_phone')->index();
            $table->string('whatsapp')->nullable();
            $table->string('normalized_whatsapp')->nullable()->index();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->index('name');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->json('changes');
            $table->index(['entity', 'entity_id']);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        DB::table('number_sequences')->insert([
            'kind' => 'OWNER', 'next_number' => 1, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX one_owner ON users (is_owner) WHERE is_owner = true');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('business_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_owner'));
    }
};
