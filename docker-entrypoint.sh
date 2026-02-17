#!/bin/sh
# Entrypoint pour le conteneur : configure la cron Laravel puis lance la commande (php artisan serve).

set -e
PROJECT_PATH="${PROJECT_PATH:-/var/www/html}"

# Configurer le scheduler Laravel (exécution toutes les minutes)
(crontab -l 2>/dev/null; echo "* * * * * cd $PROJECT_PATH && php artisan schedule:run >> /dev/null 2>&1") | crontab -

# Démarrer le démon cron en arrière-plan
cron

# Lancer la commande passée (ex: php artisan serve)
exec "$@"
