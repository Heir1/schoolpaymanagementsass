<?php

namespace Database\Seeders;

use App\Modules\Users\Models\Role; // Assurez-vous que c'est le bon namespace pour votre modèle Role
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoleSeeder extends Seeder
{
    /**
     * Exécute le seed de la base de données.
     */
    public function run(): void
    {
        // Désactiver les contraintes de clé étrangère temporairement (si besoin pour d'autres tables)
        Schema::disableForeignKeyConstraints();
        DB::table('roles')->truncate();
        Schema::enableForeignKeyConstraints();

        $roles = [
            [
                'name' => 'superadmin',
                'guard_name' => 'web', // ◀ AJOUTÉ
                'description' => 'Administrateur système avec accès complet à toutes les fonctionnalités, toutes les écoles et tous les utilisateurs. Peut gérer la configuration globale.',
            ],
            [
                'name' => 'school_admin',
                'guard_name' => 'web', // ◀ AJOUTÉ
                'description' => 'Administrateur d\'une école spécifique. Peut gérer les classes, les enseignants, les élèves, les frais et les paramètres de son école.',
            ],
            [
                'name' => 'school_staff',
                'guard_name' => 'web', // ◀ AJOUTÉ
                'description' => 'Personnel administratif d\'une école (secrétaire, comptable). Peut gérer les inscriptions, les paiements et les communications, avec des permissions limitées.',
            ],
            [
                'name' => 'teacher',
                'guard_name' => 'web', // ◀ AJOUTÉ
                'description' => 'Enseignant. Peut gérer les notes, les présences, le contenu des cours et communiquer avec les parents pour ses classes assignées.',
            ],
            [
                'name' => 'accountant',
                'guard_name' => 'web', // ◀ AJOUTÉ
                'description' => 'Comptable d\'école. Gère exclusivement les aspects financiers : frais, factures, rapports de paiement et rapports financiers.',
            ],
            [
                'name' => 'parent',
                'guard_name' => 'web', // ◀ AJOUTÉ
                'description' => 'Parent d\'élève. Peut voir les informations de son enfant (notes, présences, emploi du temps), payer les frais et communiquer avec les enseignants.',
            ],
            [
                'name' => 'student',
                'guard_name' => 'web', // ◀ AJOUTÉ
                'description' => 'Élève. Accède à son emploi du temps, ses notes, ses devoirs et peut télécharger des ressources éducatives.',
            ],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }

        $this->command->info(count($roles) . ' rôles insérés avec succès.');
        $this->command->info('✅  Rôles par défaut : ' . implode(', ', array_column($roles, 'name')));
    }
}