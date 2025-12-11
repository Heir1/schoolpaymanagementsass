#!/bin/bash

echo "🔧 CONFIGURATION DES MODÈLES ET MIGRATIONS (User modulaire uniquement)"
echo "======================================================================"

# 1. Vérifier et créer les dossiers nécessaires
echo "📁 Création des dossiers des modules..."
mkdir -p app/Modules/{Schools,Academic,Users,Billing,Shared}/Models
mkdir -p app/Modules/Shared/Models/Pivots
echo "✓ Dossiers créés"

# 2. SUPPRIMER le modèle User par défaut
echo ""
echo "👤 Suppression du modèle User par défaut..."

if [ -f "app/Models/User.php" ]; then
    echo "⚠️  Modèle User par défaut détecté : app/Models/User.php"
    
    # Créer un backup
    cp app/Models/User.php app/Models/User.php.backup
    echo "✓ Backup créé : app/Models/User.php.backup"
    
    # Supprimer le modèle par défaut
    rm app/Models/User.php
    echo "✓ Modèle User par défaut supprimé"
    
    # S'assurer que le modèle modulaire existe
    if [ ! -f "app/Modules/Users/Models/User.php" ]; then
        echo "❌ Modèle User modulaire non trouvé ! Création..."
        mkdir -p app/Modules/Users/Models
        
        # Créer le modèle User modulaire
        cat > app/Modules/Users/Models/User.php << 'EOF'
<?php

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'users';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'full_name',
        'phone_or_email',
        'avatar_url',
        'password',
        'confirm_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'confirm_password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
EOF
        echo "✓ Modèle User modulaire créé : app/Modules/Users/Models/User.php"
    else
        echo "✓ Modèle User modulaire existe déjà"
    fi
elif [ -f "app/Modules/Users/Models/User.php" ]; then
    echo "✓ Modèle User modulaire trouvé (pas de conflit)"
else
    echo "❌ Aucun modèle User trouvé ! Création du modèle modulaire..."
    
    # Créer le modèle User modulaire
    cat > app/Modules/Users/Models/User.php << 'EOF'
<?php

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'users';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'full_name',
        'phone_or_email',
        'avatar_url',
        'password',
        'confirm_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'confirm_password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
EOF
    echo "✓ Modèle User modulaire créé"
fi

# 3. Configurer auth.php pour utiliser App\Modules\Users\Models\User
echo ""
echo "🔐 Configuration de auth.php..."

if [ -f "config/auth.php" ]; then
    # Vérifier et corriger
    if grep -q "'model' => App\\\\Modules\\\\Users\\\\Models\\\\User::class" config/auth.php; then
        echo "✓ auth.php utilise déjà App\Modules\Users\Models\User"
    else
        echo "⚠️  auth.php n'utilise pas le bon modèle. Correction..."
        
        # Créer un backup
        cp config/auth.php config/auth.php.backup
        
        # Remplacer n'importe quel modèle User par App\Modules\Users\Models\User
        sed -i "s/'model' => .*User::class/'model' => App\\\\Modules\\\\Users\\\\Models\\\\User::class/g" config/auth.php
        
        echo "✓ auth.php mis à jour pour utiliser App\Modules\Users\Models\User"
    fi
else
    echo "❌ config/auth.php non trouvé !"
fi

# 4. Vérifier que tous les modèles utilisent App\Modules\Users\Models\User
echo ""
echo "🔄 Vérification des relations dans les modèles..."

# Liste des modèles qui doivent pointer vers User
models_to_check=(
    "Schools/Models/SchoolType"
    "Schools/Models/School" 
    "Schools/Models/SchoolYear"
    "Schools/Models/StudentGroup"
    "Academic/Models/ClassModel"
    "Academic/Models/Student"
    "Academic/Models/Province"
    "Academic/Models/City"
    "Users/Models/Role"
    "Billing/Models/FeeType"
    "Billing/Models/Fee"
    "Billing/Models/FeeInstallment"
    "Billing/Models/StudentFee"
    "Billing/Models/StudentFeeInstallment"
    "Billing/Models/GroupFee"
    "Billing/Models/GroupFeeInstallment"
    "Billing/Models/PaymentMethod"
    "Billing/Models/Invoice"
    "Billing/Models/InvoicePayment"
    "Billing/Models/AccessRule"
    "Billing/Models/InscriptionFee"
    "Billing/Models/StudentInscriptionPayment"
    "Billing/Models/FeeDependency"
    "Shared/Models/AuditLog"
    "Shared/Models/Pivots/UserRole"
)

for model_path in "${models_to_check[@]}"; do
    full_path="app/Modules/$model_path.php"
    
    if [ -f "$full_path" ]; then
        model_name=$(basename "$full_path" .php)
        echo "  Vérification de $model_name..."
        
        # Remplacer App\Models\User par App\Modules\Users\Models\User
        sed -i "s/use App\\\\Models\\\\User;/use App\\\\Modules\\\\Users\\\\Models\\\\User;/g" "$full_path"
        sed -i "s/App\\\\Models\\\\User/App\\\\Modules\\\\Users\\\\Models\\\\User/g" "$full_path"
        
        echo "    ✓ Relations avec User vérifiées"
    fi
done

# 5. Lier les modèles aux tables (CORRIGÉ - AuditLog dans Shared/Models)
echo ""
echo "🔗 Liaison des modèles aux tables..."

declare -A expected_models=(
    ["SchoolType"]="school_types"
    ["School"]="schools"
    ["SchoolYear"]="school_years"
    ["StudentGroup"]="student_groups"
    ["ClassModel"]="classes"
    ["Student"]="students"
    ["Province"]="provinces"
    ["City"]="cities"
    ["User"]="users"
    ["Role"]="roles"
    ["ParentModel"]="parents"
    ["StudentParent"]="student_parent"
    ["FeeType"]="fee_types"
    ["Fee"]="fees"
    ["ClassFee"]="classes_fees"
    ["FeeInstallment"]="fee_installments"
    ["StudentFee"]="student_fees"
    ["StudentFeeInstallment"]="student_fee_installments"
    ["GroupFee"]="group_fees"
    ["GroupFeeInstallment"]="group_fee_installments"
    ["PaymentMethod"]="payment_methods"
    ["Invoice"]="invoices"
    ["InvoicePayment"]="invoice_payments"
    ["AccessRule"]="access_rules"
    ["InscriptionFee"]="inscription_fees"
    ["StudentInscriptionPayment"]="student_inscription_payments"
    ["FeeDependency"]="fee_dependencies"
    ["AuditLog"]="audit_logs"
    ["UserRole"]="user_roles"
)

echo ""
echo "📋 ÉTAT DES MODÈLES :"
echo "---------------------"

for model_name in "${!expected_models[@]}"; do
    table_name="${expected_models[$model_name]}"
    
    # DÉTERMINER LE MODULE (CORRIGÉ POUR AuditLog)
    if [[ $model_name == "User" ]]; then
        model_path="app/Modules/Users/Models/User.php"
    elif [[ $model_name == *"School"* ]] || [[ $model_name == "StudentGroup" ]]; then
        model_path="app/Modules/Schools/Models/$model_name.php"
    elif [[ $model_name == "Student" ]] || [[ $model_name == "ClassModel" ]] || [[ $model_name == "Province" ]] || [[ $model_name == "City" ]]; then
        model_path="app/Modules/Academic/Models/$model_name.php"
    elif [[ $model_name == "Role" ]] || [[ $model_name == "ParentModel" ]]; then
        model_path="app/Modules/Users/Models/$model_name.php"
    elif [[ $model_name == "UserRole" ]] || [[ $model_name == "StudentParent" ]]; then
        model_path="app/Modules/Shared/Models/Pivots/$model_name.php"
    elif [[ $model_name == "AuditLog" ]]; then
        model_path="app/Modules/Shared/Models/$model_name.php"
    else
        model_path="app/Modules/Billing/Models/$model_name.php"
    fi
    
    if [ -f "$model_path" ]; then
        echo "✅ $model_name ($table_name)"
        
        # Vérifier si la table est définie
        if ! grep -q "protected \$table =" "$model_path"; then
            # Ajouter la propriété $table
            sed -i "/class $model_name extends Model/a \    protected \$table = '$table_name';" "$model_path" 2>/dev/null || \
            sed -i "/class $model_name extends Authenticatable/a \    protected \$table = '$table_name';" "$model_path" 2>/dev/null
            echo "   ✓ Table ajoutée : '$table_name'"
        fi
        
        # Vérifier les relations avec User
        if grep -q "created_by\|updated_by\|user_id" "$model_path" 2>/dev/null; then
            if grep -q "App\\\\Modules\\\\Users\\\\Models\\\\User" "$model_path"; then
                echo "   ✓ Relations avec User correctes"
            else
                # Corriger les relations
                sed -i "s/App\\\\Models\\\\User/App\\\\Modules\\\\Users\\\\Models\\\\User/g" "$model_path"
                echo "   ✓ Relations avec User corrigées"
            fi
        fi
    else
        echo "❌ $model_name - MODÈLE MANQUANT"
        echo "   Chemin attendu: $model_path"
        echo "   Astuce: Crée-le avec: php artisan make:model $model_name -m"
    fi
done

# 6. Vérifier la migration users
echo ""
echo "🔍 Vérification de la migration users..."

users_migration=$(find database/migrations -name "*create_users_table.php" 2>/dev/null | head -1)

if [ -n "$users_migration" ]; then
    echo "✓ Migration users trouvée : $(basename $users_migration)"
    
    # Vérifier qu'elle utilise uuid
    if grep -q "uuid('id')" "$users_migration"; then
        echo "  ✓ Utilise uuid pour l'ID"
    else
        echo "  ⚠️  N'utilise pas uuid - À VERIFIER"
    fi
else
    echo "⚠️  Migration users non trouvée"
    echo "   Exécute : php artisan make:migration create_users_table --create=users"
fi

# 7. Créer le modèle AuditLog s'il manque
echo ""
echo "🛠️  Vérification des modèles manquants..."

if [ ! -f "app/Modules/Shared/Models/AuditLog.php" ]; then
    echo "❌ AuditLog manquant - Création..."
    mkdir -p app/Modules/Shared/Models
    
    cat > app/Modules/Shared/Models/AuditLog.php << 'EOF'
<?php

namespace App\Modules\Shared\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'action',
        'entity',
        'entity_id',
        'details',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'entity_id' => 'integer',
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Accessor pour les détails JSON
    public function getDetailsAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    public function setDetailsAttribute($value)
    {
        $this->attributes['details'] = json_encode($value);
    }
}
EOF
    echo "✓ AuditLog créé : app/Modules/Shared/Models/AuditLog.php"
fi

# 8. Finalisation
echo ""
echo "==========================================="
echo "✅ CONFIGURATION TERMINÉE !"
echo ""
echo "🎯 CONFIGURATION APPLIQUÉE :"
echo "   1. ✅ Modèle User par défaut supprimé (app/Models/User.php)"
echo "   2. ✅ Modèle User modulaire utilisé (app/Modules/Users/Models/User.php)"
echo "   3. ✅ auth.php configuré pour App\Modules\Users\Models\User"
echo "   4. ✅ Toutes les relations pointent vers App\Modules\Users\Models\User"
echo "   5. ✅ AuditLog dans Shared/Models (corrigé)"
echo ""
echo "🚀 PROCHAINES ÉTAPES :"
echo "   1. Créer la migration users si nécessaire :"
echo "      php artisan make:migration create_users_table --create=users"
echo "   2. S'assurer que la migration users utilise uuid :"
echo "      \$table->uuid('id')->primary();"
echo "   3. Créer les autres migrations"
echo "   4. Exécuter : php artisan migrate"
echo ""
echo "🧪 POUR TESTER :"
echo "   php artisan tinker"
echo "   >>> use App\Modules\Users\Models\User;"
echo "   >>> User::create(['full_name' => 'Test', 'phone_or_email' => 'test@test.com', 'password' => bcrypt('password')]);"
echo ""
echo "⚠️  IMPORTANT :"
echo "   - Toutes les tables avec 'created_by'/'updated_by' doivent utiliser uuid"
echo "   - Les clés étrangères vers users doivent être : \$table->uuid('user_id');"