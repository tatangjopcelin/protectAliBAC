<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Planifier la vérification des dates de péremption tous les jours à 10h30 et 16h00
Schedule::command('products:check-expiration')
    ->dailyAt('10:30')
    ->description('Vérifie les dates de péremption des produits et crée des alertes (matin)');

Schedule::command('products:check-expiration')
    ->dailyAt('16:00')
    ->description('Vérifie les dates de péremption des produits et crée des alertes (après-midi)');

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

// Vérifier les limites d'heures supplémentaires toutes les 15 minutes
Schedule::command('time-entries:check-overtime')
    ->everyFifteenMinutes()
    ->description('Vérifie les pointages en cours et pointe automatiquement si la limite d\'heures sup est atteinte');

// Vérifier les super tâches hebdomadaires chaque lundi à 8h00
Schedule::command('super-tasks:check-weekly')
    ->weeklyOn(1, '08:00')
    ->description('Vérifie que les super tâches sont assignées pour chaque semaine et envoie des alertes si nécessaire');

// Détecter les absences (programmé mais pas pointé) chaque jour à 01h00 pour la veille
Schedule::command('absences:detect')
    ->dailyAt('01:00')
    ->description('Enregistre les absences des employés programmés qui n\'ont pas pointé la veille');

// Notifier les employés des tâches à effectuer aujourd'hui (email, push, alerte in-app) chaque jour à 7h00
Schedule::command('tasks:notify-due-today')
    ->dailyAt('07:00')
    ->description('Envoie email, push et alerte aux employés pour les tâches dues aujourd\'hui');
