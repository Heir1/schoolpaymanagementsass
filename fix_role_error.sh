#!/bin/bash

echo "=== CORRECTION DE L'ERREUR ROLE/SPATIE ==="

# 1. Arrêter Octane
echo "1. Arrêt d'Octane..."
php artisan octane:stop 2>/dev/null || true

# 2. Désinstaller Spatie si présent
echo "2. Vérification de Spatie..."
if composer show spatie/laravel-permission 2>/dev/null; then
    echo "   Désinstallation de spatie/laravel-permission..."
    composer remove spatie/laravel-permission
else
    echo "   Spatie n'est pas installé."
fi

# 3. Supprimer PermissionServiceProvider
echo "3. Nettoyage des providers..."
if [ -f "app/Providers/PermissionServiceProvider.php" ]; then
    rm app/Providers/PermissionServiceProvider.php
    echo "   PermissionServiceProvider supprimé."
fi

# 4. Mettre à jour config/app.php
echo "4. Mise à jour de la configuration..."
sed -i '/PermissionServiceProvider::class,/d' config/app.php

# 5. Corriger le modèle Role
echo "5. Correction du modèle Role..."
if [ -f "app/Modules/Users/Models/Role.php" ]; then
    # Faire une sauvegarde
    cp app/Modules/Users/Models/Role.php app/Modules/Users/Models/Role.php.backup
    
    # Modifier l'extension
    sed -i "s/use Spatie\\\\Permission\\\\Models\\\\Role as SpatieRole;//" app/Modules/Users/Models/Role.php
    sed -i "s/class Role extends SpatieRole/class Role extends \\\\Illuminate\\\\Database\\\\Eloquent\\\\Model/" app/Modules/Users/Models/Role.php
    echo "   Modèle Role corrigé (sauvegarde: Role.php.backup)"
fi

# 6. Recharger l'autoloader
echo "6. Rechargement de l'autoloader..."
composer dump-autoload

# 7. Nettoyer les caches
echo "7. Nettoyage des caches..."
rm -rf bootstrap/cache/*

# 8. Redémarrer
echo "8. Démarrage d'Octane..."
php artisan octane:start &

echo "=== CORRECTION TERMINÉE ==="
echo "Testez votre API: http://127.0.0.1:8000/api/v1/admin/students"