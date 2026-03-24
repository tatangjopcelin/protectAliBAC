#!/bin/sh
# Entrypoint pour le conteneur : install des deps Composer si besoin, cron Laravel, puis php artisan serve.

set -e
PROJECT_PATH="${PROJECT_PATH:-/var/www/html}"
cd "$PROJECT_PATH"

# Installer les dépendances Composer si vendor n'existe pas (ex: après un clone)
if [ ! -f vendor/autoload.php ]; then
  echo "Installation des dépendances Composer..."
  composer install --no-interaction
fi

# Configurer le scheduler Laravel (exécution toutes les 30 minutes)
(crontab -l 2>/dev/null; echo "*/30 * * * * cd $PROJECT_PATH && php artisan schedule:run >> /dev/null 2>&1") | crontab -

# Démarrer le démon cron en arrière-plan
cron

# Lancer la commande passée (ex: php artisan serve)
exec "$@"
