<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Academic\Models\Province;
use App\Modules\Academic\Models\City;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        // 🔹 Kinshasa (ville-province)
        $kinshasa = Province::where('name', 'Kinshasa')->first();

        $kinshasaCommunes = [
            'Gombe', 'Lingwala', 'Barumbu', 'Kinshasa',
            'Kintambo', 'Ngaliema', 'Bandalungwa',
            'Kalamu', 'Makala', 'Bumbu', 'Selembao',
            'Mont-Ngafula', 'Ngaba', 'Lemba',
            'Matete', 'Kisenso', 'Masina',
            'Ndjili', 'Kimbanseke', 'Nsele',
            'Maluku'
        ];

        foreach ($kinshasaCommunes as $commune) {
            City::create([
                'province_id' => $kinshasa->id,
                'name' => $commune,
                'type' => 'territory', // communes traitées comme territoires
                'created_by' => 1,
                'updated_by' => 1,
            ]);
        }

        // 🔹 Autres villes principales (exemples)
        $cities = [
            'Kongo Central' => ['Matadi', 'Boma'],
            'Haut-Katanga' => ['Lubumbashi', 'Likasi', 'Kolwezi'],
            'Nord-Kivu' => ['Goma', 'Beni', 'Butembo'],
            'Sud-Kivu' => ['Bukavu', 'Uvira'],
            'Kasaï Oriental' => ['Mbuji-Mayi'],
            'Tshopo' => ['Kisangani'],
        ];

        foreach ($cities as $provinceName => $cityList) {
            $province = Province::where('name', $provinceName)->first();

            foreach ($cityList as $city) {
                City::create([
                    'province_id' => $province->id,
                    'name' => $city,
                    'type' => 'city',
                    'created_by' => 1,
                    'updated_by' => 1,
                ]);
            }
        }
    }
}
