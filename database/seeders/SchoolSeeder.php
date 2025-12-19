<?php

namespace Database\Seeders;

use App\Modules\Schools\Models\School;
use App\Modules\Schools\Models\SchoolType;
use App\Modules\Users\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchoolSeeder extends Seeder
{
    /**
     * Exécute le seed de la base de données.
     */
    public function run(): void
    {
        // Désactiver les contraintes de clé étrangère temporairement
        Schema::disableForeignKeyConstraints();
        DB::table('schools')->truncate();
        DB::table('school_types')->truncate();
        Schema::enableForeignKeyConstraints();

        // Récupérer un utilisateur pour les champs created_by/updated_by
        $adminUser = User::first(); // Peut être null si aucun utilisateur

        // 1. Créer seulement 2 types d'écoles
        $schoolTypes = [
            ['name' => 'Primaire'],
            ['name' => 'Secondaire'],
        ];

        $createdTypes = [];
        foreach ($schoolTypes as $typeData) {
            $type = SchoolType::create([
                'name' => $typeData['name'],
                'created_by' => $adminUser ? $adminUser->id : null,
                'updated_by' => $adminUser ? $adminUser->id : null,
            ]);
            $createdTypes[$type->name] = $type->id; // Pour référence ultérieure
            $this->command->info("Type d'école créé : {$type->name} (ID: {$type->id})");
        }

        $this->command->info(count($schoolTypes) . ' types d\'écoles créés.');

        // 2. Créer les écoles de Kinshasa avec les 2 types seulement
        $kinshasaSchools = [
            // Écoles Primaires
            [
                'name' => 'École Primaire Sainte Thérèse',
                'type_id' => $createdTypes['Primaire'],
                'address' => 'Commune de Lingwala, Kinshasa',
                'phone' => '+243 81 700 0011',
            ],
            [
                'name' => 'École Primaire de la Gombe',
                'type_id' => $createdTypes['Primaire'],
                'address' => 'Avenue de la Gombe, Kinshasa',
                'phone' => '+243 81 700 0013',
            ],
            [
                'name' => 'École Primaire Saint Michel',
                'type_id' => $createdTypes['Primaire'],
                'address' => 'Commune de Kasa-Vubu, Kinshasa',
                'phone' => '+243 81 700 0014',
            ],
            
            // Écoles Secondaires
            [
                'name' => 'Collège Boboto',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Commune de la Gombe, Kinshasa',
                'phone' => '+243 81 700 0002',
                'note' => 'Institution jésuite historique',
            ],
            [
                'name' => 'Lycée Prince de Liège',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Avenue de la Justice, Gombe, Kinshasa',
                'phone' => '+243 81 700 0001',
                'note' => 'École internationale',
            ],
            [
                'name' => 'École Française de Kinshasa (EFK)',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Quartier Binza, Kinshasa',
                'phone' => '+243 81 700 0003',
                'note' => 'Établissement homologué français',
            ],
            [
                'name' => 'Lycée Français René Descartes',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Commune de la Gombe, Kinshasa',
                'phone' => '+243 81 700 0004',
                'note' => 'Réseau AEFE',
            ],
            [
                'name' => 'Institut de la Gombe',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Avenue des Aviateurs, Gombe, Kinshasa',
                'phone' => '+243 81 700 0005',
                'note' => 'Enseignement technique',
            ],
            [
                'name' => 'Complexe Scolaire Mgr. Shaumba',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Commune de Ngaliema, Kinshasa',
                'phone' => '+243 81 700 0007',
                'note' => 'Institution catholique',
            ],
            [
                'name' => 'École Secondaire Monseigneur Pelé',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Avenue de l\'OUA, Kinshasa',
                'phone' => '+243 81 700 0008',
                'note' => 'École de référence catholique',
            ],
            [
                'name' => 'Institut Motema Mpiko',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Commune de Kalamu, Kinshasa',
                'phone' => '+243 81 700 0009',
                'note' => 'Sciences commerciales',
            ],
            [
                'name' => 'Collège Saint Joseph',
                'type_id' => $createdTypes['Secondaire'],
                'address' => 'Avenue Batetela, Kinshasa',
                'phone' => '+243 81 700 0012',
                'note' => 'Institution salésienne',
            ],
        ];

        foreach ($kinshasaSchools as $schoolData) {
            $school = School::create([
                'name' => $schoolData['name'],
                'type_id' => $schoolData['type_id'],
                'address' => $schoolData['address'],
                'phone' => $schoolData['phone'],
                'created_by' => $adminUser ? $adminUser->id : null,
                'updated_by' => $adminUser ? $adminUser->id : null,
            ]);
            
            $typeName = array_search($schoolData['type_id'], $createdTypes);
            $this->command->info("École créée : {$school->name} ({$typeName})");
        }

        $this->command->info(count($kinshasaSchools) . ' écoles de Kinshasa créées avec succès.');
        
        // Statistiques
        $primaryCount = School::where('type_id', $createdTypes['Primaire'])->count();
        $secondaryCount = School::where('type_id', $createdTypes['Secondaire'])->count();
        
        $this->command->info("📊 Statistiques :");
        $this->command->info("   • Primaire : {$primaryCount} écoles");
        $this->command->info("   • Secondaire : {$secondaryCount} écoles");
        $this->command->info('✅  Types et écoles créés avec succès.');
    }
}