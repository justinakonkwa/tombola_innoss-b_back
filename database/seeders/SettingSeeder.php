<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Paramètres applicatifs par défaut (cahier des charges §17, §36).
 *
 * `is_public = true` marque les valeurs exposables au frontend via
 * GET /api/v1/settings/public ; les autres restent internes au back-office.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'site.name',
                'value' => "Tombola Innoss’B",
                'group' => 'site',
                'description' => 'Nom public de la plateforme.',
                'is_public' => true,
            ],
            [
                'key' => 'site.tagline',
                'value' => 'Tentez votre chance et remportez des lots exceptionnels.',
                'group' => 'site',
                'description' => 'Accroche affichée sur la page d’accueil.',
                'is_public' => true,
            ],
            [
                'key' => 'legal.terms_url',
                'value' => '/legal/conditions-generales',
                'group' => 'legal',
                'description' => 'Conditions générales de participation.',
                'is_public' => true,
            ],
            [
                'key' => 'legal.privacy_url',
                'value' => '/legal/confidentialite',
                'group' => 'legal',
                'description' => 'Politique de confidentialité des données personnelles.',
                'is_public' => true,
            ],
            [
                'key' => 'legal.min_age',
                'value' => 18,
                'group' => 'legal',
                'description' => 'Âge minimum requis pour participer.',
                'is_public' => true,
            ],
            [
                'key' => 'support.email',
                'value' => 'support@tombola-innossb.cd',
                'group' => 'support',
                'description' => 'Adresse e-mail du support participant.',
                'is_public' => true,
            ],
            [
                'key' => 'support.phone',
                'value' => '+243820000000',
                'group' => 'support',
                'description' => 'Numéro de téléphone du support participant.',
                'is_public' => true,
            ],
            [
                'key' => 'fraud.max_risk_score',
                'value' => 80,
                'group' => 'fraud',
                'description' => 'Score de risque (0-100) au-delà duquel une commande est bloquée automatiquement.',
                'is_public' => false,
            ],
            [
                'key' => 'notifications.channels',
                'value' => ['email'],
                'group' => 'notifications',
                'description' => 'Canaux de notification actifs (email, sms, whatsapp, push).',
                'is_public' => false,
            ],
        ];

        foreach ($settings as $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'group' => $setting['group'],
                    'description' => $setting['description'],
                    'is_public' => $setting['is_public'],
                ]
            );
        }
    }
}
