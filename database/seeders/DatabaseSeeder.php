<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationEvent;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $officer = User::firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => Hash::make('password'),
        ]);

        $organizations = [
            [
                'name' => 'Computer Society',
                'category' => 'Academic',
                'college' => 'CCS',
                'cause' => 'Technology and Innovation',
                'mission' => 'Build an inclusive community for students to learn, create, and share technology.',
                'open_membership' => true,
                'color' => '#dbeafe',
            ],
            [
                'name' => 'Student Government',
                'category' => 'Leadership',
                'college' => 'All Colleges',
                'cause' => 'Student Leadership and Service',
                'mission' => 'Represent student voices and make campus life better through service and leadership.',
                'open_membership' => true,
                'color' => '#dcfce7',
            ],
            [
                'name' => 'Environmental Advocates',
                'category' => 'Advocacy',
                'college' => 'COE',
                'cause' => 'Environmental Protection',
                'mission' => 'Promote environmental responsibility through education, advocacy, and local action.',
                'open_membership' => false,
                'color' => '#fef3c7',
            ],
            [
                'name' => 'Campus Creatives',
                'category' => 'Arts',
                'college' => 'CBA',
                'cause' => 'Arts, Media, and Creativity',
                'mission' => 'Give student artists a welcoming place to collaborate, practice, and share their work.',
                'open_membership' => true,
                'color' => '#f3e8ff',
            ],
        ];

        foreach ($organizations as $attributes) {
            $organization = Organization::updateOrCreate(
                ['name' => $attributes['name']],
                $attributes,
            );

            OrganizationMember::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $officer->id],
                ['role' => 'President'],
            );

            OrganizationEvent::updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'title' => 'Open Community Meetup',
                ],
                [
                    'description' => 'Meet the officers, learn what the organization is planning, and connect with members.',
                    'location' => 'Student Center',
                    'starts_at' => now()->addDays(14)->setTime(16, 0),
                ],
            );
        }
    }
}
