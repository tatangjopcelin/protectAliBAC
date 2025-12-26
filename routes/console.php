<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Planifier la vérification des dates de péremption tous les jours à 10h30
Schedule::command('products:check-expiration')
    ->dailyAt('10:30')
    ->description('Vérifie les dates de péremption des produits et crée des alertes');

// Gérer automatiquement les produits périmés tous les jours à 11h00
Schedule::command('products:handle-expired')
    ->dailyAt('11:00')
    ->description('Gère automatiquement les produits périmés en réduisant leur stock à 0');

// Nettoyer les anciennes alertes lues tous les dimanches
Schedule::call(function () {
    $alertService = app(\App\Services\AlertService::class);
    $alertService->cleanOldAlerts(30);
})->weeklyOn(0, '02:00')
  ->description('Nettoie les anciennes alertes lues (plus de 30 jours)');
