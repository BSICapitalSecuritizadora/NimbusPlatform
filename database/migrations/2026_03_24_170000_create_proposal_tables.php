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
        Schema::create('proposal_sectors', function (Blueprint $row) {
            $row->id();
            $row->string('name');
            $row->timestamps();
        });

        Schema::create('proposal_companies', function (Blueprint $row) {
            $row->id();
            $row->string('name');
            $row->string('cnpj')->unique();
            $row->string('ie')->nullable();
            $row->string('site')->nullable();
            $row->string('cep')->nullable();
            $row->string('logradouro')->nullable();
            $row->string('complemento')->nullable();
            $row->string('numero')->nullable();
            $row->string('bairro')->nullable();
            $row->string('cidade')->nullable();
            $row->string('estado', 2)->nullable();
            $row->timestamps();
        });

        Schema::create('proposal_company_sector', function (Blueprint $row) {
            $row->foreignId('company_id')->constrained('proposal_companies')->onDelete('cascade');
            $row->foreignId('sector_id')->constrained('proposal_sectors')->onDelete('cascade');
            $row->primary(['company_id', 'sector_id']);
        });

        Schema::create('proposal_contacts', function (Blueprint $row) {
            $row->id();
            $row->foreignId('company_id')->constrained('proposal_companies')->onDelete('cascade');
            $row->string('name');
            $row->string('email');
            $row->string('phone_personal')->nullable();
            $row->boolean('whatsapp')->default(false);
            $row->string('phone_company')->nullable();
            $row->string('cargo')->nullable();
            $row->timestamps();
        });

        Schema::create('proposals', function (Blueprint $row) {
            $row->id();
            $row->foreignId('company_id')->constrained('proposal_companies')->onDelete('cascade');
            $row->foreignId('contact_id')->constrained('proposal_contacts')->onDelete('cascade');
            $row->text('observations')->nullable();
            $row->string('status')->default('pending'); // Envida, Em Análise, etc.
            $row->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('proposal_contacts');
        Schema::dropIfExists('proposal_company_sector');
        Schema::dropIfExists('proposal_companies');
        Schema::dropIfExists('proposal_sectors');
    }
};
