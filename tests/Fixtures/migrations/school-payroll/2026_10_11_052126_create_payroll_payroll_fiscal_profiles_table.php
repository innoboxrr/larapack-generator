<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_payroll_fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_id')->constrained('scopes')->onUpdate('restrict')->onDelete('restrict');
            $table->foreignId('party_id')->constrained('parties')->onUpdate('restrict')->onDelete('restrict');
            $table->string('rfc');
            $table->string('curp');
            $table->string('nss')->nullable();
            $table->string('postal_code');
            $table->string('state_key');
            $table->string('contract_type')->default(01);
            $table->string('regime_type')->default(02);
            $table->string('risk_class')->nullable();
            $table->decimal('sbc_daily_amount')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->onUpdate('restrict')->onDelete('restrict');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_payroll_fiscal_profiles');
    }
};
