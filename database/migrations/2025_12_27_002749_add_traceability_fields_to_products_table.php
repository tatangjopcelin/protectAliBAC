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
        Schema::table('products', function (Blueprint $table) {
            // Informations de traçabilité complète (comme Komia)
            
            // Numéro de lot / Batch number
            $table->string('batch_number')->nullable()->after('barcode');
            
            // Date de fabrication
            $table->date('manufacturing_date')->nullable()->after('batch_number');
            
            // Informations sur l'usine de production
            $table->string('factory_name')->nullable()->after('manufacturing_date');
            $table->text('factory_address')->nullable()->after('factory_name');
            $table->string('factory_contact_person')->nullable()->after('factory_address');
            $table->string('factory_phone')->nullable()->after('factory_contact_person');
            $table->string('factory_email')->nullable()->after('factory_phone');
            
            // Pays et région d'origine
            $table->string('origin_country')->nullable()->after('factory_email');
            $table->string('origin_region')->nullable()->after('origin_country');
            
            // Certificats et documents
            $table->string('certificate_number')->nullable()->after('origin_region');
            $table->string('certificate_type')->nullable()->after('certificate_number'); // HACCP, ISO, Bio, etc.
            $table->date('certificate_issue_date')->nullable()->after('certificate_type');
            $table->date('certificate_expiry_date')->nullable()->after('certificate_issue_date');
            $table->text('certificate_file_path')->nullable()->after('certificate_expiry_date'); // Chemin vers le fichier PDF/image
            
            // Documents d'importation
            $table->string('import_document_number')->nullable()->after('certificate_file_path');
            $table->date('import_date')->nullable()->after('import_document_number');
            $table->string('customs_declaration_number')->nullable()->after('import_date');
            
            // Informations de transport
            $table->string('transport_method')->nullable()->after('customs_declaration_number'); // Route, Mer, Air
            $table->string('transport_company')->nullable()->after('transport_method');
            $table->string('transport_document_number')->nullable()->after('transport_company');
            
            // Température de conservation
            $table->string('storage_temperature')->nullable()->after('transport_document_number'); // Ex: "2-8°C", "Ambiente"
            
            // Conditions de stockage
            $table->text('storage_conditions')->nullable()->after('storage_temperature');
            
            // Numéro de série (si applicable)
            $table->string('serial_number')->nullable()->after('storage_conditions');
            
            // Date de réception chez le fournisseur (avant livraison au restaurant)
            $table->date('supplier_reception_date')->nullable()->after('serial_number');
            
            // Index pour faciliter les recherches
            $table->index('batch_number');
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
            $table->dropIndex(['batch_number']);
            $table->dropIndex(['certificate_number']);
            $table->dropIndex(['origin_country']);
            
            $table->dropColumn([
                'batch_number',
                'manufacturing_date',
                'factory_name',
                'factory_address',
                'factory_contact_person',
                'factory_phone',
                'factory_email',
                'origin_country',
                'origin_region',
                'certificate_number',
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
        });
    }
};
