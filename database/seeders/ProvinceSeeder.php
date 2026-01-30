<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Academic\Models\Province;

class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = [
            'Kinshasa',
            'Kongo Central',
            'Kwilu',
            'Kwango',
            'Mai-Ndombe',
            'Équateur',
            'Nord-Kivu',
            'Sud-Kivu',
            'Ituri',
            'Haut-Katanga',
            'Lualaba',
            'Kasaï',
            'Kasaï Central',
            'Kasaï Oriental',
            'Haut-Lomami',
            'Tshopo',
            'Bas-Uélé',
            'Haut-Uélé',
            'Tanganyika',
            'Maniema',
            'Sud-Ubangi',
            'Nord-Ubangi',
            'Tshuapa',
            'Sankuru',
            'Lomami',
        ];

        foreach ($provinces as $province) {
            Province::create([
                'name' => $province,
                'created_by' => 1,
                'updated_by' => 1,
            ]);
        }
    }
}
