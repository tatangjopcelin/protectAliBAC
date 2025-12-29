<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Garde seulement les 5 informations essentielles de traçabilité :
     * 1. Numéro de lot (batch_number)
     * 2. Date de fabrication (manufacturing_date)
     * 3. Nom de l'usine (factory_name)
     * 4. Pays d'origine (origin_country)
     * 5. Numéro de certificat (certificate_number)
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Supprimer les index des colonnes qu'on va supprimer
            $table->dropIndex(['certificate_number']);
            $table->dropIndex(['origin_country']);
            
            // Supprimer tous les champs sauf les 5 essentiels
            $table->dropColumn([
                // Garder: batch_number, manufacturing_date, factory_name, origin_country, certificate_number
                // Supprimer le reste:
                'factory_address',
                'factory_contact_person',
                'factory_phone',
                'factory_email',
                'origin_region',
                'certificate_type',
                'certificate_issue_date',
                'certificate_expiry_date',
                'certificate_file_path',
                'import_document_number',
                'import_date',
                'customs_declaration_number',
                'transport_method',
                'transport_company',
                'transport_document_number',
                'storage_temperature',
                'storage_conditions',
                'serial_number',
                'supplier_reception_date',
            ]);
            
            // Recréer les index pour les champs conservés
            $table->index('certificate_number');
            $table->index('origin_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Recréer les colonnes supprimées
            $table->text('factory_address')->nullable()->after('factory_name');
            $table->string('factory_contact_person')->nullable()->after('factory_address');
            $table->string('factory_phone')->nullable()->after('factory_contact_person');
            $table->string('factory_email')->nullable()->after('factory_phone');
            $table->string('origin_region')->nullable()->after('origin_country');
            $table->string('certificate_type')->nullable()->after('certificate_number');
            $table->date('certificate_issue_date')->nullable()->after('certificate_type');
            $table->date('certificate_expiry_date')->nullable()->after('certificate_issue_date');
            $table->text('certificate_file_path')->nullable()->after('certificate_expiry_date');
            $table->string('import_document_number')->nullable()->after('certificate_file_path');
            $table->date('import_date')->nullable()->after('import_document_number');
            $table->string('customs_declaration_number')->nullable()->after('import_date');
            $table->string('transport_method')->nullable()->after('customs_declaration_number');
            $table->string('transport_company')->nullable()->after('transport_method');
            $table->string('transport_document_number')->nullable()->after('transport_company');
            $table->string('storage_temperature')->nullable()->after('transport_document_number');
            $table->text('storage_conditions')->nullable()->after('storage_temperature');
            $table->string('serial_number')->nullable()->after('storage_conditions');
            $table->date('supplier_reception_date')->nullable()->after('serial_number');
        });
    }
};
