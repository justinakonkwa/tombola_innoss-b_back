<?php

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\PrizeStatus;
use App\Models\Campaign;
use App\Models\Prize;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Campagnes et lots de lancement (cahier des charges §18, §19).
 *
 * Idempotent : les campagnes sont identifiées par leur slug, les lots par
 * (campagne, slug). Rejouer le seeder met à jour sans dupliquer.
 */
class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = (string) env('TOMBOLA_ADMIN_EMAIL', 'admin@tombola-innossb.cd');

        /** @var User|null $admin */
        $admin = User::query()->where('email', $adminEmail)->first();

        if ($admin === null) {
            $admin = User::query()->where('is_admin', true)->orderBy('created_at')->first();
        }

        $this->seedLamborghini($admin);
        $this->seedTesla($admin);
    }

    private function seedLamborghini(?User $admin): void
    {
        $campaign = Campaign::query()->updateOrCreate(
            ['slug' => 'lamborghini'],
            [
                'name' => "Lamborghini Innoss’B",
                'short_description' => 'Gagnez la Lamborghini Urus d’Innoss’B : un ticket à 5 $ et peut-être le volant de vos rêves.',
                'description' => "La grande tombola Innoss’B met en jeu une Lamborghini Urus, ainsi que des motos, "
                    ."des iPhone 16 Pro, des Smart TV et des primes en espèces. Chaque ticket coûte 5 USD et "
                    ."vous donne une chance supplémentaire de gagner. Le tirage au sort est public, "
                    ."vérifiable et réalisé à partir d’un aléa cryptographique dont toutes les preuves sont publiées.",
                'hero_media_url' => '/media/lamborghini-hero.jpg',
                'og_image_url' => '/media/lamborghini-og.jpg',
                'ticket_price' => '5.00',
                'currency' => 'USD',
                'max_tickets' => 100000,
                'tickets_sold' => 0,
                'tickets_reserved' => 0,
                'min_tickets_per_order' => 1,
                'max_tickets_per_order' => 100,
                'max_tickets_per_user' => 500,
                'starts_at' => now()->subDays(7),
                'ends_at' => now()->addDays(60),
                'draw_at' => now()->addDays(75),
                'status' => CampaignStatus::Active,
                'is_featured' => true,
                'terms_url' => '/legal/conditions-generales',
                'created_by' => $admin?->id,
            ]
        );

        $drawAt = now()->addDays(75);

        $prizes = [
            [
                'name' => "Lamborghini Urus Innoss’B",
                'slug' => 'lamborghini-urus',
                'description' => "Le lot principal : une Lamborghini Urus aux couleurs d’Innoss’B, "
                    ."remise lors d’une cérémonie officielle à Kinshasa.",
                'indicative_value' => '250000.00',
                'quantity' => 1,
                'is_main' => true,
                'position' => 0,
                'media' => [
                    ['type' => 'image', 'url' => '/media/prizes/lamborghini-urus.jpg', 'alt' => "Lamborghini Urus Innoss’B"],
                    ['type' => 'video', 'url' => '/media/prizes/lamborghini-urus-presentation.mp4', 'alt' => "Présentation de la Lamborghini Urus"],
                ],
            ],
            [
                'name' => 'Moto',
                'slug' => 'moto',
                'description' => 'Une moto neuve, idéale pour circuler en ville, remise au gagnant avec les papiers.',
                'indicative_value' => '3500.00',
                'quantity' => 2,
                'is_main' => false,
                'position' => 1,
                'media' => [
                    ['type' => 'image', 'url' => '/media/prizes/moto.jpg', 'alt' => 'Moto neuve'],
                ],
            ],
            [
                'name' => 'iPhone 16 Pro',
                'slug' => 'iphone-16-pro',
                'description' => 'Un iPhone 16 Pro neuf, débloqué, livré avec ses accessoires.',
                'indicative_value' => '1200.00',
                'quantity' => 5,
                'is_main' => false,
                'position' => 2,
                'media' => [
                    ['type' => 'image', 'url' => '/media/prizes/iphone-16-pro.jpg', 'alt' => 'iPhone 16 Pro'],
                ],
            ],
            [
                'name' => 'Smart TV 55"',
                'slug' => 'smart-tv-55',
                'description' => 'Un téléviseur intelligent de 55 pouces, parfait pour suivre les tirages en famille.',
                'indicative_value' => '600.00',
                'quantity' => 8,
                'is_main' => false,
                'position' => 3,
                'media' => [
                    ['type' => 'image', 'url' => '/media/prizes/smart-tv-55.jpg', 'alt' => 'Smart TV 55 pouces'],
                ],
            ],
            [
                'name' => 'Prime en espèces',
                'slug' => 'prime-en-especes',
                'description' => 'Une prime en espèces versée directement au gagnant, sans frais.',
                'indicative_value' => '500.00',
                'quantity' => 10,
                'is_main' => false,
                'position' => 4,
                'media' => [
                    ['type' => 'image', 'url' => '/media/prizes/prime-especes.jpg', 'alt' => 'Prime en espèces'],
                ],
            ],
        ];

        foreach ($prizes as $prize) {
            Prize::query()->updateOrCreate(
                ['campaign_id' => $campaign->id, 'slug' => $prize['slug']],
                [
                    'name' => $prize['name'],
                    'description' => $prize['description'],
                    'media' => $prize['media'],
                    'indicative_value' => $prize['indicative_value'],
                    'currency' => 'USD',
                    'quantity' => $prize['quantity'],
                    'is_main' => $prize['is_main'],
                    'position' => $prize['position'],
                    'draw_at' => $drawAt,
                    'status' => PrizeStatus::Published,
                ]
            );
        }
    }

    private function seedTesla(?User $admin): void
    {
        $campaign = Campaign::query()->updateOrCreate(
            ['slug' => 'tesla-model-3'],
            [
                'name' => 'Tesla Model 3',
                'short_description' => 'La prochaine tombola Innoss’B : une Tesla Model 3 à gagner.',
                'description' => "Deuxième édition de la tombola Innoss’B : une Tesla Model 3 électrique est mise en jeu. "
                    ."Les ventes ouvriront prochainement ; les participants peuvent déjà consulter la campagne.",
                'hero_media_url' => '/media/tesla-model-3-hero.jpg',
                'ticket_price' => '10.00',
                'currency' => 'USD',
                'max_tickets' => 50000,
                'tickets_sold' => 0,
                'tickets_reserved' => 0,
                'min_tickets_per_order' => 1,
                'max_tickets_per_order' => 50,
                'max_tickets_per_user' => 250,
                'starts_at' => now()->addDays(3),
                'ends_at' => now()->addDays(90),
                'draw_at' => now()->addDays(105),
                'status' => CampaignStatus::Scheduled,
                'is_featured' => false,
                'terms_url' => '/legal/conditions-generales',
                'created_by' => $admin?->id,
            ]
        );

        Prize::query()->updateOrCreate(
            ['campaign_id' => $campaign->id, 'slug' => 'tesla-model-3'],
            [
                'name' => 'Tesla Model 3',
                'description' => 'Une Tesla Model 3 neuve, livrée avec son kit de recharge.',
                'media' => [
                    ['type' => 'image', 'url' => '/media/prizes/tesla-model-3.jpg', 'alt' => 'Tesla Model 3'],
                ],
                'indicative_value' => '45000.00',
                'currency' => 'USD',
                'quantity' => 1,
                'is_main' => true,
                'position' => 0,
                'draw_at' => now()->addDays(105),
                'status' => PrizeStatus::Published,
            ]
        );
    }
}
