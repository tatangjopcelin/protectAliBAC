#!/bin/bash

# Script pour configurer le scheduler Laravel via cron (pointage auto heures sup toutes les 15 min).
# - Avec Docker : la cron est configurée automatiquement au démarrage du conteneur (docker-entrypoint.sh).
# - Sans Docker : exécuter ce script une fois sur le serveur, ou : crontab -e puis ajouter la ligne
#   * * * * * cd /chemin/vers/protectAli && php artisan schedule:run >> /dev/null 2>&1

# Obtenir le chemin absolu du projet (dans le conteneur = /var/www/html, en local = répertoire du script)
PROJECT_PATH="${PROJECT_PATH:-$(cd "$(dirname "$0")" && pwd)}"

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

