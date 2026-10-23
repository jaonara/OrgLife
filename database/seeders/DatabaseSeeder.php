<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
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
        $president = User::firstOrNew(['email' => 'jaonara@gmail.com']);
        if (! $president->exists) {
            $president->password = Hash::make('password');
        }
        $president->name = 'Jao Nara';
        $president->is_admin = true;
        $president->save();

        $vicePresident = $this->firstOrCreateOfficer(
            'Samantha Eve Magbaril',
            'magbaril@gmail.com',
            'orglife.vice_president_password',
        );
        $treasurer = $this->firstOrCreateOfficer(
            'Asnawi Sapra',
            'sapra@gmail.com',
            'orglife.treasurer_password',
        );

        $legacyUser = User::where('email', 'test@example.com')->first();
        if ($legacyUser) {
            OrganizationAnnouncement::where('user_id', $legacyUser->id)
                ->update(['user_id' => $president->id]);
            $legacyUser->tokens()->delete();
            $legacyUser->delete();
        }

        $organizations = [
            [
                'name' => 'College of Computer Studies',
                'category' => 'Academic',
                'college' => 'CCS',
                'cause' => 'Technology and Innovation',
                'mission' => 'The College of Computer Studies is committed to delivering transformative computing education that fosters innovation, ethical leadership, and community empowerment through technology-driven solutions aligned with national and global development goals.',
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
                'name' => 'College of Engineering',
                'category' => 'Academic',
                'college' => 'COE',
                'cause' => 'Civil Engineering and Innovation',
                'mission' => 'Develop future engineers through quality education, professional growth, collaboration, and service to the community.',
                'open_membership' => true,
                'color' => '#ede9fe',
            ],
            [
                'name' => 'College of Business Administration',
                'category' => 'Academic',
                'college' => 'CBA',
                'cause' => 'Business Education and Research',
                'mission' => 'Advance business education, research, and continuous learning in service of the community.',
                'open_membership' => true,
                'color' => '#fef3c7',
            ],
            [
                'name' => 'College of Criminology',
                'category' => 'Academic',
                'college' => 'COC',
                'cause' => 'Criminology and Forensic Science',
                'mission' => 'Support quality criminology education through academic growth, professional development, and forensic science.',
                'open_membership' => true,
                'color' => '#fee2e2',
            ],
            [
                'name' => 'College of Education',
                'category' => 'Academic',
                'college' => 'CED',
                'cause' => 'Teacher Education and Learning',
                'mission' => 'Prepare dedicated educators through quality teacher education, lifelong learning, and service to the community.',
                'open_membership' => true,
                'color' => '#dbeafe',
            ],
            [
                'name' => 'College of Arts and Sciences',
                'category' => 'Academic',
                'college' => 'CAS',
                'cause' => 'Arts, Culture, and Heritage',
                'mission' => 'Promote arts, culture, and heritage through learning, creativity, and community engagement.',
                'open_membership' => true,
                'color' => '#ffedd5',
            ],
        ];

        Organization::where('name', 'Computer Society')
            ->where('college', 'CCS')
            ->update(['name' => 'College of Computer Studies']);

        Organization::where('name', 'Environmental Advocates')
            ->where('college', 'COE')
            ->update(['name' => 'College of Engineering']);

        Organization::where('name', 'Campus Creatives')->delete();

        foreach ($organizations as $attributes) {
            $organization = Organization::updateOrCreate(
                ['name' => $attributes['name']],
                $attributes,
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

        $pastEvents = [
            [
                'organization' => 'College of Computer Studies',
                'title' => 'CCS Teachers’ Day Appreciation',
                'description' => 'A celebration honoring the teachers who inspire, guide, and support the College of Computer Studies community.',
                'location' => 'SPC Campus',
                'starts_at' => '2026-10-19 10:00:00',
            ],
            [
                'organization' => 'Student Government',
                'title' => 'Student Leadership and Service Forum',
                'description' => 'A campus forum for student leaders to share ideas and discuss service initiatives for the academic community.',
                'location' => 'SPC Campus',
                'starts_at' => '2026-10-16 13:00:00',
            ],
            [
                'organization' => 'College of Engineering',
                'title' => 'PICE ICLDN Chapter General Assembly 2026',
                'description' => 'The SPC-JPICE Chapter joined civil engineering professionals and student representatives for the PICE Iligan City–Lanao del Norte Chapter General Assembly.',
                'location' => 'DPWH 1st District Engineering Office, Del Carmen, Iligan City',
                'starts_at' => '2026-10-17 09:00:00',
            ],
            [
                'organization' => 'College of Business Administration',
                'title' => 'Faculty and Alumni Research Publication Celebration',
                'description' => 'A celebration of the College of Business Administration faculty and alumni research publication and their contribution to academic excellence.',
                'location' => 'SPC Campus',
                'starts_at' => '2026-10-20 14:00:00',
            ],
            [
                'organization' => 'College of Criminology',
                'title' => 'Forensic Examination Signature Techniques Training',
                'description' => 'Faculty participated in training on questioned document examination and forensic signature analysis at Christ the King College de Maranding.',
                'location' => 'Christ the King College de Maranding',
                'starts_at' => '2026-10-14 09:00:00',
            ],
            [
                'organization' => 'College of Education',
                'title' => 'Teachers’ Day 2026',
                'description' => 'A campus celebration honoring the dedication, passion, and commitment of SPC educators.',
                'location' => 'SPC Covered Court',
                'starts_at' => '2026-10-21 15:30:00',
            ],
            [
                'organization' => 'College of Arts and Sciences',
                'title' => 'Museums and Galleries Month Heritage Celebration',
                'description' => 'A celebration of arts, culture, and heritage under the theme “Crafting Opportunities for the New World.”',
                'location' => 'SPC Campus',
                'starts_at' => '2026-10-18 10:00:00',
            ],
        ];

        foreach ($pastEvents as $event) {
            $organization = Organization::where('name', $event['organization'])->firstOrFail();
            $organization->events()->updateOrCreate(
                ['title' => $event['title']],
                [
                    'description' => $event['description'],
                    'location' => $event['location'],
                    'starts_at' => $event['starts_at'],
                ],
            );
        }

        foreach (Organization::query()->get() as $organization) {
            $organization->members()
                ->where('user_id', '!=', $president->id)
                ->where('role', 'President')
                ->update(['role' => 'Officer']);

            OrganizationMember::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $president->id],
                ['role' => 'President'],
            );
            OrganizationMember::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $vicePresident->id],
                ['role' => 'Vice President'],
            );
            OrganizationMember::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $treasurer->id],
                ['role' => 'Treasurer'],
            );
        }

        $criminology = Organization::where('name', 'College of Criminology')->firstOrFail();
        $criminology->announcements()->updateOrCreate(
            [
                'user_id' => $president->id,
                'title' => 'Advancing Forensic Knowledge and Skills',
            ],
            [
                'body' => "The College of Criminology proudly acknowledges Ms. Jaycelyn V. Abucay, Mr. Jamaloding D. Disari, and Mr. Riel D. Miculob for their participation in the Forensic Examination Signature Techniques training held on October 14–15, 2026, at Christ the King College de Maranding.\n\nTheir participation reflects the College’s dedication to continuous faculty development, enhancing expertise in questioned document examination and forensic signature analysis to support quality criminology education.\n\nCongratulations on this valuable professional learning experience!\n\n#SPCCollegeOfCriminology #ForensicScience #QuestionedDocumentExamination #ForensicSignatureAnalysis",
            ],
        );

        $education = Organization::where('name', 'College of Education')->firstOrFail();
        $education->announcements()->updateOrCreate(
            [
                'user_id' => $president->id,
                'title' => 'HAPPY TEACHERS’ DAY! 💙📚',
            ],
            [
                'body' => "To all our beloved educators, this day is dedicated to YOU! 🌼\nSt. Peter’s College warmly invites everyone to join us in celebrating Teachers’ Day 2026, a special occasion to honor the dedication, passion, and unwavering commitment of our teachers who continue to inspire, guide, and shape our future.\n📅 Date: October 21, 2026\n⏰ Time: 3:30 PM\n📍 Venue: SPC Covered Court\n🌱 You plant the seeds, you help us grow. You light our paths and help us glow. Your lessons live beyond today.\nLet us come together to express our heartfelt gratitude and appreciation to our amazing teachers for their endless patience, wisdom, and love.\nSee you there, Peterians! Let’s make this celebration memorable! 💐\nHAPPY TEACHERS’ DAY! 💙",
                'created_at' => '2026-10-20 00:00:00',
            ],
        );

        $businessAdministration = Organization::where('name', 'College of Business Administration')->firstOrFail();
        $businessAnnouncement = $businessAdministration->announcements()
            ->where('title', 'like', '%College of Business Administration Faculty and Alumni%')
            ->first();

        if (! $businessAnnouncement) {
            $businessAnnouncement = new OrganizationAnnouncement();
            $businessAnnouncement->organization()->associate($businessAdministration);
        }

        $businessAnnouncement->user_id = $president->id;
        $businessAnnouncement->title = '🎉 Congratulations to the College of Business Administration Faculty and Alumni on their Research Publication!';
        $businessAnnouncement->body = "The College of Business Administration proudly celebrates this achievement, reflecting our commitment to academic excellence, research, and continuous learning. We also extend our sincere appreciation to ABL Publisher Opc for providing a platform for scholarly research and the advancement of knowledge.\n\nWe are proud of you! 💙\n\n#CBA #StPetersCollege #ResearchPublication #AcademicExcellence";
        $businessAnnouncement->created_at = now()->startOfDay();
        $businessAnnouncement->save();

        $artsAndSciences = Organization::where('name', 'College of Arts and Sciences')->firstOrFail();
        $artsAnnouncement = $artsAndSciences->announcements()
            ->where('title', 'Museums and Galleries Month Celebration')
            ->firstOrNew();
        $artsAnnouncement->user_id = $president->id;
        $artsAnnouncement->title = 'Museums and Galleries Month Celebration';
        $artsAnnouncement->body = "St. Peter’s College joins the nationwide celebration of Museums and Galleries Month, recognizing the vital role of arts, culture, and heritage in shaping our identity and inspiring future generations.\n\nWith the theme “Crafting Opportunities for the New World,” we celebrate the creativity, history, and cultural treasures that connect our past to the present and inspire a brighter future.\n\nTogether, let us continue to appreciate, preserve, and promote our rich Filipino cultural heritage! 🇵🇭\n\n#MuseumsAndGalleriesMonth #ExhibitsAndReflections #SPC #CommittedToExcellence";
        $artsAnnouncement->created_at = now()->startOfDay();
        $artsAnnouncement->save();

        $engineering = Organization::where('name', 'College of Engineering')->firstOrFail();
        $engineeringAnnouncement = $engineering->announcements()
            ->where('title', '𝗦𝗣𝗖-𝗝𝗣𝗜𝗖𝗘 | 𝗦𝗧𝗥𝗘𝗡𝗚𝗧𝗛𝗘𝗡𝗜𝗡𝗚 𝗖𝗢𝗡𝗡𝗘𝗖𝗧𝗜𝗢𝗡𝗦 𝗔𝗧 𝗧𝗛𝗘 𝗣𝗜𝗖𝗘–𝗜𝗖𝗟𝗗𝗡 𝗚𝗘𝗡𝗘𝗥𝗔𝗟 𝗔𝗦𝗦𝗘𝗠𝗕𝗟𝗬 𝟮𝟬𝟮𝟲 🏗️👷')
            ->firstOrNew();
        $engineeringAnnouncement->user_id = $president->id;
        $engineeringAnnouncement->title = '𝗦𝗣𝗖-𝗝𝗣𝗜𝗖𝗘 | 𝗦𝗧𝗥𝗘𝗡𝗚𝗧𝗛𝗘𝗡𝗜𝗡𝗚 𝗖𝗢𝗡𝗡𝗘𝗖𝗧𝗜𝗢𝗡𝗦 𝗔𝗧 𝗧𝗛𝗘 𝗣𝗜𝗖𝗘–𝗜𝗖𝗟𝗗𝗡 𝗚𝗘𝗡𝗘𝗥𝗔𝗟 𝗔𝗦𝗦𝗘𝗠𝗕𝗟𝗬 𝟮𝟬𝟮𝟲 🏗️👷';
        $engineeringAnnouncement->body = "The Junior Philippine Institute of Civil Engineers (JPICE) – St. Peter’s College Chapter proudly participated in the 𝐏𝐡𝐢𝐥𝐢𝐩𝐩𝐢𝐧𝐞 𝐈𝐧𝐬𝐭𝐢𝐭𝐮𝐭𝐞 𝐨𝐟 𝐂𝐢𝐯𝐢𝐥 𝐄𝐧𝐠𝐢𝐧𝐞𝐞𝐫𝐬 (𝐏𝐈𝐂𝐄) 𝐈𝐥𝐢𝐠𝐚𝐧 𝐂𝐢𝐭𝐲–𝐋𝐚𝐧𝐚𝐨 𝐝𝐞𝐥 𝐍𝐨𝐫𝐭𝐞 𝐂𝐡𝐚𝐩𝐭𝐞𝐫 𝐆𝐞𝐧𝐞𝐫𝐚𝐥 𝐀𝐬𝐬𝐞𝐦𝐛𝐥𝐲, 𝐡𝐞𝐥𝐝 𝐨𝐧 𝐎𝐜𝐭𝐨𝐛𝐞𝐫 𝟏𝟕, 𝟐𝟎𝟐𝟔, 𝐚𝐭 𝐭𝐡𝐞 𝐃𝐏𝐖𝐇 𝟏𝐬𝐭 𝐃𝐢𝐬𝐭𝐫𝐢𝐜𝐭 𝐄𝐧𝐠𝐢𝐧𝐞𝐞𝐫𝐢𝐧𝐠 𝐎𝐟𝐟𝐢𝐜𝐞, 𝐃𝐞𝐥 𝐂𝐚𝐫𝐦𝐞𝐧, 𝐈𝐥𝐢𝐠𝐚𝐧 𝐂𝐢𝐭𝐲. The event brought together professional civil engineers, former chapter leaders, members of the Board of Directors, and student representatives from various academic institutions, fostering professional connections, collaboration, and unity within the civil engineering community.\n\nThe assembly highlighted chapter accomplishments, upcoming programs and activities, and the introduction of candidates for Calendar Year 2027. The gathering was further enriched by special performances from participating student chapters, including representatives from 𝑺𝒕. 𝑷𝒆𝒕𝒆𝒓’𝒔 𝑪𝒐𝒍𝒍𝒆𝒈𝒆, 𝑴𝑺𝑼–𝑰𝑰𝑻, 𝒂𝒏𝒅 𝑺𝒕. 𝑴𝒊𝒄𝒉𝒂𝒆𝒍’𝒔 𝑪𝒐𝒍𝒍𝒆𝒈𝒆. For the SPC-JPICE Chapter, this meaningful experience reinforced the importance of leadership, professional development, collaboration, and commitment to public service in shaping the next generation of civil engineers.\n\nThe 𝐉𝐏𝐈𝐂𝐄-𝐒𝐏𝐂 𝐂𝐡𝐚𝐩𝐭𝐞𝐫 extends its sincere appreciation to 𝐏𝐈𝐂𝐄 𝐈𝐥𝐢𝐠𝐚𝐧 𝐂𝐢𝐭𝐲–𝐋𝐚𝐧𝐚𝐨 𝐝𝐞𝐥 𝐍𝐨𝐫𝐭𝐞 𝐂𝐡𝐚𝐩𝐭𝐞𝐫 for the invitation and the opportunity to participate in this meaningful gathering. We look forward to stronger partnerships and future collaborations in advancing the civil engineering profession.\n\n𝐓𝐨𝐠𝐞𝐭𝐡𝐞𝐫, 𝐰𝐞 𝐛𝐮𝐢𝐥𝐝. 𝐓𝐨𝐠𝐞𝐭𝐡𝐞𝐫, 𝐰𝐞 𝐫𝐢𝐬𝐞. 𝐓𝐨𝐠𝐞𝐭𝐡𝐞𝐫, 𝐰𝐞 𝐬𝐞𝐫𝐯𝐞. 🏗️✨\nCAPTION & LAYOUT | VP-TECHNICAL\n#SPCJPICE2627\n#CEnteredOnGreatness\n#PICEICLDN\n#PICEGeneralAssembly2026\n#BuildingBreakthroughs\n#VivaCivil";
        $engineeringAnnouncement->created_at = now()->startOfDay();
        $engineeringAnnouncement->save();

        $computerStudies = Organization::where('name', 'College of Computer Studies')->firstOrFail();
        $teachersDayCcs = $computerStudies->announcements()
            ->where('title', 'Happy Teachers’ Day! Thank you for being our mentors')
            ->firstOrNew();
        $teachersDayCcs->user_id = $president->id;
        $teachersDayCcs->title = 'Happy Teachers’ Day! Thank you for being our mentors';
        $teachersDayCcs->body = "Behind every lesson is a teacher who inspires, guides, and believes in us. Today, we celebrate the people who do more than teach—they shape minds, build confidence, and leave a lasting impact on every student they meet.\n\nTo all our dedicated teachers, thank you for your patience, passion, and endless support. Your hard work may not always be seen, but it will always be remembered.\n\nHappy Teachers’ Day! Thank you for being our mentors, our motivators, and our everyday heroes!\n\n#SPC #CCS #CSSO #HappyTeachersDay";
        $teachersDayCcs->created_at = now()->subDays(4)->startOfDay();
        $teachersDayCcs->save();
    }

    private function firstOrCreateOfficer(string $name, string $email, string $passwordConfigKey): User
    {
        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $password = config($passwordConfigKey);
            if (! is_string($password) || $password === '') {
                throw new \RuntimeException("Set {$passwordConfigKey} before creating {$name}.");
            }
            $user->password = $password;
        }

        $user->name = $name;
        $user->save();

        return $user;
    }
}
