#!/bin/bash

# Script pour configurer le scheduler Laravel via cron
# À exécuter dans le conteneur Docker ou sur le serveur

# Obtenir le chemin absolu du projet
PROJECT_PATH="/var/www/html"

# Ajouter la tâche cron pour exécuter le scheduler Laravel toutes les minutes
(crontab -l 2>/dev/null; echo "* * * * * cd $PROJECT_PATH && php artisan schedule:run >> /dev/null 2>&1") | crontab -

echo "✅ Tâche cron configurée avec succès !"
echo "Le scheduler Laravel s'exécutera toutes les minutes."
echo ""
echo "Pour vérifier la configuration :"
echo "  crontab -l"
echo ""
echo "Pour tester le scheduler :"
echo "  php artisan schedule:run"

