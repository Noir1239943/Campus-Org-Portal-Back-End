<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExcuseRequest;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\Organization;
use App\Models\OrganizationProposal;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sample data for Cordova Public College. All dates are relative to "today",
 * so the demo always has past events, upcoming events and live data.
 *
 * Every demo account uses the password: password123
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    private string $hash;

    /** @var Collection<int, User> */
    private Collection $students;

    /** @var array<string, Organization> */
    private array $orgs = [];

    /** @var array<string, Event> keyed by "slug:title" */
    private array $events = [];

    private User $adviser;

    private User $kyla;

    private User $johnRey;

    public function run(): void
    {
        mt_srand(20261031);
        $this->hash = Hash::make(self::PASSWORD);

        $this->createUsers();
        $this->createOrganizations();
        $this->createMemberships();
        $this->createEvents();
        $this->createRegistrationsAndAttendance();
        $this->createApplications();
        $this->createProposals();
        $this->createExcuses();
        $this->createAnnouncements();
        $this->createNotifications();
    }

    /* ================================================================= */
    /* Users                                                              */
    /* ================================================================= */

    private function createUsers(): void
    {
        $this->adviser = $this->user([
            'id_number' => 'FAC-0012',
            'first_name' => 'Maricel',
            'last_name' => 'Gabutero',
            'email' => 'osa@cpc.edu.ph',
            'role' => User::ROLE_ADVISER,
            'title' => 'OSA Coordinator',
        ]);

        $this->user([
            'id_number' => 'FAC-0027',
            'first_name' => 'Dennis',
            'last_name' => 'Cabahug',
            'email' => 'dennis.cabahug@cpc.edu.ph',
            'role' => User::ROLE_ADVISER,
            'title' => 'Faculty Adviser, SSG',
        ]);

        $this->kyla = $this->user([
            'id_number' => '2024-00145',
            'first_name' => 'Kyla Mae',
            'last_name' => 'Abellana',
            'email' => 'kyla.abellana@cpc.edu.ph',
            'program' => 'BSIT',
            'year_level' => 3,
            'section' => 'BSIT 3-A',
            'phone' => '0917 555 0145',
        ]);

        $this->johnRey = $this->user([
            'id_number' => '2023-00089',
            'first_name' => 'John Rey',
            'last_name' => 'Ybañez',
            'email' => 'johnrey.ybanez@cpc.edu.ph',
            'program' => 'BSIT',
            'year_level' => 4,
            'section' => 'BSIT 4-A',
        ]);

        $first = [
            'Trisha Mae', 'Kenneth', 'Angel', 'Mark Anthony', 'Jessa', 'Christian', 'Princess', 'Jerome',
            'Rhea', 'Paul Andrew', 'Mary Joy', 'Vincent', 'Jasmine', 'Carlo', 'Shiela', 'Ralph',
            'Nicole', 'Jay-ar', 'Kimberly', 'Bryan', 'Andrea', 'Kyle', 'Hazel', 'Renz',
            'Charlene', 'Dexter', 'Lovely', 'Joshua', 'April', 'Neil', 'Camille', 'Rodel',
            'Bea', 'Jun', 'Ella', 'Marvin', 'Ivy', 'Patrick', 'Lyka', 'Arnel',
            'Grace', 'Dominic', 'Faith', 'Elmer', 'Joanna', 'Ryan', 'Clarisse', 'Jake',
        ];

        $last = [
            'Cañete', 'Bacalso', 'Tumulak', 'Dela Cruz', 'Enriquez', 'Flores', 'Navarro', 'Ochoa',
            'Pepito', 'Quiñones', 'Rosales', 'Sanchez', 'Ursal', 'Villaflor', 'Yap', 'Zosa',
            'Alcoseba', 'Baguio', 'Codilla', 'Dacalos', 'Echavez', 'Fernandez', 'Go', 'Hermosilla',
            'Ignacio', 'Lapitan', 'Mendoza', 'Abella', 'Bontuyan', 'Cuizon', 'Dumdum', 'Escario',
            'Gonzaga', 'Jumao-as', 'Labra', 'Manatad', 'Nacua', 'Ompad', 'Pacaña', 'Remedio',
            'Sarmiento', 'Tabanao', 'Ugbinada', 'Vasquez', 'Withe', 'Ybas', 'Arcenas', 'Bendanillo',
        ];

        $programs = ['BSIT', 'BSIT', 'BEEd', 'BSEd', 'BSBA', 'BSHM'];
        $counter = 200;

        foreach ($first as $i => $firstName) {
            $program = $programs[$i % count($programs)];
            $year = ($i % 4) + 1;
            $idYear = 2027 - $year; // 4th years enrolled in 2023
            $lastName = $last[$i];

            $this->user([
                'id_number' => sprintf('%d-%05d', $idYear, $counter += 7),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $this->email($firstName, $lastName),
                'program' => $program,
                'year_level' => $year,
                'section' => $program.' '.$year.'-'.(['A', 'B'][$i % 2]),
            ]);
        }

        $this->students = User::query()->students()->get()->keyBy('id');
    }

    private function user(array $attributes): User
    {
        return User::query()->create(array_merge([
            'password' => $this->hash,
            'role' => User::ROLE_STUDENT,
        ], $attributes));
    }

    private function email(string $first, string $last): string
    {
        $clean = fn ($s) => Str::of(Str::ascii($s))->lower()->replaceMatches('/[^a-z]/', '');

        return $clean($first).'.'.$clean($last).'@cpc.edu.ph';
    }

    private function student(string $idNumber): User
    {
        return $this->students->firstWhere('id_number', $idNumber);
    }

    /** Students in a given program (optionally excluding some). */
    private function inProgram(array $programs): Collection
    {
        return $this->students->filter(fn (User $u) => in_array($u->program, $programs, true));
    }

    /* ================================================================= */
    /* Organizations                                                      */
    /* ================================================================= */

    private function createOrganizations(): void
    {
        $data = [
            [
                'name' => 'Supreme Student Government', 'acronym' => 'SSG', 'category' => 'Governance', 'color' => '#1e3a8a',
                'tagline' => 'The voice of every CPC student.',
                'description' => 'The highest student governing body of Cordova Public College. Every enrolled student is a member.',
                'about' => "The SSG represents all students of Cordova Public College in school policy, student welfare and campus-wide activities such as the General Assembly, Intramurals and outreach programs.\n\nAttendance at SSG general assemblies is required for semester clearance.",
                'adviser_name' => 'Prof. Dennis Cabahug', 'tags' => ['Student welfare', 'Leadership', 'Campus events'],
                'requires_all_students' => true, 'accepting_members' => false, 'meeting_schedule' => 'General assembly every semester',
            ],
            [
                'name' => 'Junior Information Technology Society', 'acronym' => 'JITS', 'category' => 'Academic', 'color' => '#2563eb',
                'tagline' => 'Build, learn and ship together.',
                'description' => 'Hands-on workshops, study jams and hackathons for students who love technology.',
                'about' => "JITS connects BSIT students — and anyone curious about tech — with practical learning outside the classroom: coding workshops, peer study groups, industry talks and the annual Hack Cordova civic tech hackathon.\n\nNo prior programming experience is required to join.",
                'adviser_name' => 'Engr. Ramil Lapitan', 'tags' => ['Software development', 'Responsible AI', 'Career readiness', 'Peer mentoring'],
                'capacity' => 120, 'application_question' => 'Which tech skill do you most want to learn this semester?',
                'meeting_schedule' => 'Thursdays, 3:00 PM · Computer Laboratory 2',
            ],
            [
                'name' => "Future Educators' Society", 'acronym' => 'FES', 'category' => 'Academic', 'color' => '#7c3aed',
                'tagline' => 'Teaching is a calling — we practice it together.',
                'description' => 'Demo teaching clinics, literacy outreach and LET review circles for BEEd and BSEd students.',
                'about' => "FES helps future teachers grow through demo teaching clinics, peer feedback, reading programs in partner elementary schools and LET review circles led by alumni.",
                'adviser_name' => 'Dr. Lourdes Navarro', 'tags' => ['Demo teaching', 'Literacy outreach', 'LET review'],
                'capacity' => 150, 'meeting_schedule' => 'Every other Tuesday, 4:00 PM · Room 204',
            ],
            [
                'name' => 'Young Entrepreneurs Society', 'acronym' => 'YES', 'category' => 'Academic', 'color' => '#0891b2',
                'tagline' => 'From classroom ideas to real businesses.',
                'description' => 'Pitch nights, financial literacy seminars and a student-run campus market for BSBA students and aspiring founders.',
                'about' => 'YES gives students a safe place to test business ideas — from the CPC weekend market to startup pitch nights judged by local entrepreneurs.',
                'adviser_name' => 'Ms. Grace Rosales, CPA', 'tags' => ['Entrepreneurship', 'Financial literacy', 'Pitching'],
                'capacity' => 100, 'meeting_schedule' => 'Wednesdays, 3:30 PM · Room 110',
            ],
            [
                'name' => 'Hospitality Management Society', 'acronym' => 'HMS', 'category' => 'Academic', 'color' => '#db2777',
                'tagline' => 'Service with heart, Cebuano style.',
                'description' => 'Skills competitions, food safety training and industry exposure for BSHM students.',
                'about' => 'HMS prepares hospitality students for hotel, restaurant and tourism work through skills competitions, seminars and partnerships with resorts around Mactan and Cordova.',
                'adviser_name' => 'Chef Arnold Zosa', 'tags' => ['Food & beverage', 'Tourism', 'Skills competition'],
                'capacity' => 90, 'status' => Organization::STATUS_PROBATION, 'meeting_schedule' => 'Mondays, 4:00 PM · HM Laboratory',
            ],
            [
                'name' => 'CPC Kultura Dance Troupe', 'acronym' => 'KDT', 'category' => 'Arts & Culture', 'color' => '#dc2626',
                'tagline' => 'Dancing the stories of Cebu.',
                'description' => 'Folk, festival and contemporary dance. We represent CPC in cultural competitions and town fiestas.',
                'about' => "Kultura Dance Troupe performs Philippine folk dances, festival routines and contemporary pieces. Members train twice a week and perform at school programs, the Cordova town fiesta and inter-school competitions.",
                'adviser_name' => 'Mr. Jun Ompad', 'tags' => ['Folk dance', 'Festival dance', 'Performances'],
                'capacity' => 40, 'application_question' => 'Do you have any dance experience? (none is fine!)',
                'meeting_schedule' => 'Tuesdays & Saturdays · Multipurpose Hall',
            ],
            [
                'name' => 'CPC Chorale', 'acronym' => 'CHR', 'category' => 'Arts & Culture', 'color' => '#ca8a04',
                'tagline' => 'One campus, many voices.',
                'description' => 'The official choir of Cordova Public College, singing at school ceremonies, masses and the annual Christmas concert.',
                'about' => 'CPC Chorale welcomes singers of all levels. We rehearse weekly and perform at commencement, school programs and our annual Christmas concert.',
                'adviser_name' => 'Ms. Hazel Codilla', 'tags' => ['Choir', 'Vocal training', 'Concerts'],
                'capacity' => 45, 'meeting_schedule' => 'Fridays, 3:00 PM · AVR',
            ],
            [
                'name' => 'Mangrove Guardians Club', 'acronym' => 'MGC', 'category' => 'Service', 'color' => '#16a34a',
                'tagline' => 'Protecting Cordova’s coastline, one seedling at a time.',
                'description' => 'Mangrove planting, coastal cleanups and environmental education with the Cordova municipal environment office.',
                'about' => "Cordova's mangroves protect our barangays from storm surges and feed our fisherfolk. MGC organizes monthly planting and monitoring activities, coastal cleanups and school talks.\n\nActivities count toward community service hours.",
                'adviser_name' => 'Mr. Elmer Dacalos', 'tags' => ['Environment', 'Community service', 'Volunteering'],
                'capacity' => 80, 'meeting_schedule' => 'Monthly meeting, first Friday · Room 105',
            ],
            [
                'name' => 'The Cordovan', 'acronym' => 'TC', 'category' => 'Media', 'color' => '#475569',
                'tagline' => 'The official student publication of CPC.',
                'description' => 'News, features, photojournalism and campus radio. Writers, layout artists and photographers welcome.',
                'about' => 'The Cordovan publishes campus news online and in print, and trains student journalists for press conferences.',
                'adviser_name' => 'Ms. Ivy Escario', 'tags' => ['Journalism', 'Photography', 'Layout'],
                'capacity' => 30, 'meeting_schedule' => 'By assignment',
            ],
            [
                'name' => 'CPC Sports Club', 'acronym' => 'CSC', 'category' => 'Sports', 'color' => '#ea580c',
                'tagline' => 'Train hard, play fair.',
                'description' => 'Basketball, volleyball, sepak takraw and athletics — for varsity hopefuls and weekend players alike.',
                'about' => 'CSC organizes training sessions, intramural teams and coaching clinics, and supports CPC athletes in inter-school meets.',
                'adviser_name' => 'Coach Marvin Labra', 'tags' => ['Basketball', 'Volleyball', 'Athletics'],
                'capacity' => 100, 'meeting_schedule' => 'Daily training, 4:30 PM · Covered Court',
            ],
        ];

        foreach ($data as $org) {
            $organization = Organization::query()->create(array_merge([
                'contact_email' => strtolower($org['acronym']).'@cpc.edu.ph',
                'accepting_members' => true,
                'status' => Organization::STATUS_ACTIVE,
                'recognized_at' => now()->subYears(2)->startOfYear()->addMonths(7),
                'application_deadline' => now()->addDays(30)->toDateString(),
            ], $org));

            $this->orgs[$organization->acronym] = $organization;
        }

        $this->orgs['TC']->update(['accepting_members' => false]);
    }

    /* ================================================================= */
    /* Memberships                                                        */
    /* ================================================================= */

    private function createMemberships(): void
    {
        $semesterStart = now()->subDays(80);

        // Everyone is in the student government.
        foreach ($this->students as $student) {
            $this->join($student, 'SSG', $semesterStart->copy()->subMonths(2));
        }

        $this->join($this->kyla, 'JITS', now()->subYear());
        $this->join($this->kyla, 'MGC', now()->subDays(70));
        $this->join($this->johnRey, 'JITS', now()->subYears(2), 'President', true);

        // Program-based organizations.
        $programOrgs = ['BSIT' => 'JITS', 'BEEd' => 'FES', 'BSEd' => 'FES', 'BSBA' => 'YES', 'BSHM' => 'HMS'];

        foreach ($this->students as $student) {
            if (in_array($student->id, [$this->kyla->id, $this->johnRey->id], true)) {
                continue;
            }

            $acronym = $programOrgs[$student->program];

            if ($this->chance(0.82)) {
                $this->join($student, $acronym, $semesterStart->copy()->subDays(mt_rand(10, 300)));
            }

            // Interest-based organizations.
            foreach (['KDT' => 0.14, 'CHR' => 0.14, 'MGC' => 0.22, 'TC' => 0.1, 'CSC' => 0.2] as $interest => $p) {
                if ($this->chance($p)) {
                    $this->join($student, $interest, $semesterStart->copy()->subDays(mt_rand(5, 300)));
                }
            }
        }

        // Officers.
        $officers = [
            'SSG' => ['President', 'Vice President', 'Secretary'],
            'JITS' => ['Secretary', 'Events Committee'],
            'FES' => ['President', 'Secretary'],
            'YES' => ['President', 'Treasurer'],
            'HMS' => ['President'],
            'KDT' => ['Dance Captain'],
            'CHR' => ['President'],
            'MGC' => ['President', 'Volunteer Coordinator'],
            'TC' => ['Editor-in-Chief'],
            'CSC' => ['President'],
        ];

        foreach ($officers as $acronym => $positions) {
            $candidates = Membership::query()
                ->where('organization_id', $this->orgs[$acronym]->id)
                ->where('is_officer', false)
                ->whereNotIn('user_id', [$this->kyla->id, $this->johnRey->id])
                ->orderByDesc('user_id')
                ->get()
                ->filter(fn ($m) => $this->students[$m->user_id]->year_level >= 2)
                ->values();

            foreach ($positions as $i => $position) {
                $candidates->get($i * 2)?->update(['position' => $position, 'is_officer' => true]);
            }
        }

        // John Rey also sits on the SSG as BSIT representative (not an officer there).
        Membership::query()
            ->where('user_id', $this->johnRey->id)
            ->where('organization_id', $this->orgs['SSG']->id)
            ->update(['position' => 'BSIT Representative']);
    }

    private function join(User $user, string $acronym, Carbon $joinedAt, string $position = 'Member', bool $officer = false): void
    {
        Membership::query()->updateOrCreate(
            ['user_id' => $user->id, 'organization_id' => $this->orgs[$acronym]->id],
            ['position' => $position, 'is_officer' => $officer, 'status' => Membership::STATUS_ACTIVE, 'joined_at' => $joinedAt]
        );
    }

    /* ================================================================= */
    /* Events                                                             */
    /* ================================================================= */

    private function createEvents(): void
    {
        // [org, title, type, dayOffset, start, end, venue, visibility, mandatory, hours, capacity, speaker, status, description]
        $rows = [
            ['SSG', 'General Assembly — 1st Semester', 'Assembly', -62, '08:00', '11:00', 'Multipurpose Hall', 'members', true, 0, null, null, 'published',
                'Opening assembly for all students: SSG officers, school policies, organization fair and the semester calendar.'],
            ['SSG', 'Student Leadership Training', 'Seminar', -26, '13:00', '17:00', 'AVR', 'open', false, 4, 80, 'Hon. Marites Pepito, SK Federation', 'published',
                'Training on project planning, budgeting and servant leadership for student leaders.'],
            ['SSG', 'Intramurals 2026 Opening & Oath-taking', 'Assembly', 9, '07:30', '11:30', 'Covered Court', 'members', true, 0, null, null, 'published',
                'Parade of teams, oath of sportsmanship and opening games. Wear your team color.'],
            ['SSG', 'Paskuhan Outreach Program', 'Outreach', 41, '08:00', '15:00', 'Barangay Gabi Covered Court', 'open', false, 6, 60, null, 'published',
                'Gift-giving and a feeding program for children in Barangay Gabi. Volunteers earn community service hours.'],

            ['JITS', 'Web Dev Bootcamp: Intro to React', 'Workshop', -50, '13:00', '17:00', 'Computer Laboratory 2', 'open', false, 0, 40, 'Engr. Ramil Lapitan', 'published',
                'Build your first React app. Bring a laptop if you have one — lab PCs are available.'],
            ['JITS', 'JITS General Meeting', 'Meeting', -35, '15:00', '16:30', 'Room 301', 'members', true, 0, null, null, 'published',
                'Semester plans, committee sign-ups and the Hack Cordova timeline.'],
            ['JITS', 'Cybersecurity Awareness Talk', 'Seminar', -12, '14:00', '16:00', 'AVR', 'open', false, 0, 100, 'DICT Region VII Cybersecurity Team', 'published',
                'How scams, phishing and account takeovers target students — and how to protect yourself and your family.'],
            ['JITS', 'IT Seminar: Responsible AI', 'Seminar', 5, '14:00', '16:00', 'AVR', 'open', false, 0, 100, 'Dr. Lena Foster, guest speaker', 'published',
                'An expert-led discussion on responsible AI practice, academic integrity and the social impact of emerging technologies.'],
            ['JITS', 'Algorithms Study Jam', 'Workshop', 12, '16:30', '18:00', 'Computer Laboratory 2', 'members', false, 0, 35, null, 'published',
                'Peer study session on sorting, searching and recursion before the midterm exams.'],
            ['JITS', 'Hack Cordova: Civic Tech Hackathon', 'Competition', 20, '08:00', '20:00', 'Computer Laboratory 1 & 2', 'open', false, 0, 60, null, 'published',
                'Teams of three build apps that solve real problems in Cordova — from tricycle fare guides to barangay announcements.'],
            ['JITS', 'Intro to Git & GitHub Workshop', 'Workshop', 26, '14:00', '17:00', 'Computer Laboratory 2', 'open', false, 0, 40, 'Kenneth Bacalso', 'pending',
                'Version control basics for group projects and capstone teams.'],
            ['JITS', 'Year-End Tech Fair', 'Social', 45, '09:00', '16:00', 'Multipurpose Hall', 'open', false, 0, null, null, 'draft',
                'Showcase of student projects, capstone demos and games.'],

            ['FES', 'Demo Teaching Clinic', 'Workshop', -40, '13:00', '16:00', 'Room 204', 'members', false, 0, 30, 'Dr. Lourdes Navarro', 'published',
                'Practice demo lessons with feedback from faculty and 4th-year student teachers.'],
            ['FES', "World Teachers' Day Tribute", 'Social', -26, '15:00', '17:00', 'Multipurpose Hall', 'open', false, 0, null, null, 'published',
                'A short program honoring CPC faculty and our partner school teachers.'],
            ['FES', 'Reading Buddies at Cordova Central School', 'Outreach', 15, '08:00', '12:00', 'Cordova Central School', 'members', false, 4, 25, null, 'published',
                'Read-aloud and phonics activities with Grade 2 learners. Service hours credited.'],
            ['FES', 'Storytelling Contest', 'Competition', 33, '13:00', '16:00', 'AVR', 'open', false, 0, 50, null, 'pending',
                'Inter-section storytelling contest in English and Cebuano.'],

            ['YES', 'Startup Pitch Night', 'Competition', -30, '17:00', '20:00', 'Multipurpose Hall', 'open', false, 0, 80, 'Cordova MSME Council', 'published',
                'Student teams pitch business ideas to local entrepreneurs.'],
            ['YES', 'Financial Literacy for Students', 'Seminar', 8, '13:00', '15:00', 'Room 110', 'open', false, 0, 60, 'Ms. Grace Rosales, CPA', 'published',
                'Budgeting your allowance, saving with digital wallets and avoiding online lending traps.'],

            ['HMS', 'Food Safety & Sanitation Seminar', 'Seminar', -45, '13:00', '16:00', 'HM Laboratory', 'members', true, 0, null, 'Municipal Health Office', 'published',
                'Required food handler orientation for all HMS members.'],
            ['HMS', 'Table Setting Competition', 'Competition', -20, '09:00', '12:00', 'HM Laboratory', 'open', false, 0, 30, null, 'published',
                'Formal and casual table setting showdown between sections.'],
            ['HMS', 'Barista Basics Workshop', 'Workshop', 18, '13:00', '16:00', 'HM Laboratory', 'open', false, 0, 20, 'Chef Arnold Zosa', 'rejected',
                'Espresso, milk texturing and latte art basics.'],

            ['KDT', 'Troupe Auditions', 'Meeting', -55, '15:00', '18:00', 'Multipurpose Hall', 'open', false, 0, null, null, 'published',
                'Open auditions for new dancers. Wear comfortable clothes.'],
            ['KDT', 'Festival Dance Workshop', 'Workshop', -15, '15:00', '18:00', 'Multipurpose Hall', 'members', true, 0, null, 'Sinulog choreographers', 'published',
                'Required workshop on festival choreography and props.'],
            ['KDT', 'Kultura Dance Showcase', 'Social', 25, '18:00', '20:30', 'Multipurpose Hall', 'open', false, 0, 300, null, 'published',
                'An evening of folk and festival dances. Free admission for CPC students.'],

            ['CHR', 'Vocal Technique Workshop', 'Workshop', -33, '15:00', '17:00', 'AVR', 'members', true, 0, null, 'Ms. Hazel Codilla', 'published',
                'Breathing, warm-ups and part singing.'],
            ['CHR', 'Christmas Concert Rehearsal', 'Meeting', 30, '15:00', '18:00', 'AVR', 'members', true, 0, null, null, 'published',
                'Full rehearsal for the annual Christmas concert.'],

            ['MGC', 'Coastal Cleanup Drive', 'Outreach', -48, '06:00', '10:00', 'Day-as Coastline, Cordova', 'open', false, 4, 60, null, 'published',
                'International Coastal Cleanup Day activity with the Municipal Environment Office.'],
            ['MGC', 'Mangrove Planting Activity', 'Outreach', -21, '06:00', '11:00', 'Bangbang Mangrove Area', 'open', false, 6, 50, null, 'published',
                'Plant propagules with the local fisherfolk association. Wear boots and bring water.'],
            ['MGC', 'MGC Monthly Meeting', 'Meeting', -3, '15:00', '16:00', 'Room 105', 'members', true, 0, null, null, 'published',
                'Volunteer schedules for November and December.'],
            ['MGC', 'Mangrove Monitoring Day', 'Outreach', 14, '06:00', '10:00', 'Bangbang Mangrove Area', 'open', false, 4, 40, null, 'published',
                'Measure seedling survival from our September planting.'],

            ['TC', 'Campus Journalism Workshop', 'Workshop', -52, '13:00', '17:00', 'Library', 'open', false, 0, 40, 'Sun.Star Cebu alumni writers', 'published',
                'News writing, photojournalism and layout basics.'],

            ['CSC', 'Intramurals Team Tryouts', 'Meeting', -10, '16:00', '18:30', 'Covered Court', 'open', false, 0, null, null, 'published',
                'Basketball, volleyball and sepak takraw tryouts.'],
            ['CSC', 'Basketball Coaching Clinic', 'Workshop', 6, '16:00', '18:00', 'Covered Court', 'open', false, 0, 40, 'Coach Marvin Labra', 'published',
                'Fundamentals and game IQ clinic before intramurals.'],
        ];

        foreach ($rows as [$org, $title, $type, $day, $start, $end, $venue, $visibility, $mandatory, $hours, $capacity, $speaker, $status, $description]) {
            $organization = $this->orgs[$org];
            $officer = Membership::query()->where('organization_id', $organization->id)->where('is_officer', true)->first();
            $startsAt = $this->at($day, $start);

            $event = Event::query()->create([
                'organization_id' => $organization->id,
                'created_by' => $org === 'JITS' ? $this->johnRey->id : $officer?->user_id,
                'title' => $title,
                'type' => $type,
                'description' => $description,
                'venue' => $venue,
                'starts_at' => $startsAt,
                'ends_at' => $this->at($day, $end),
                'capacity' => $capacity,
                'visibility' => $visibility,
                'is_mandatory' => $mandatory,
                'service_hours' => $hours,
                'speaker' => $speaker,
                'status' => $status,
                'submitted_at' => $status !== 'draft' ? $startsAt->copy()->subDays(14) : null,
                'reviewed_by' => in_array($status, ['published', 'rejected'], true) ? $this->adviser->id : null,
                'reviewed_at' => in_array($status, ['published', 'rejected'], true) ? $startsAt->copy()->subDays(12) : null,
                'review_note' => $status === 'rejected'
                    ? 'Please attach the budget for coffee supplies and confirm the HM Laboratory booking with the Dean’s office, then resubmit.'
                    : null,
                'check_in_secret' => $day < 0 ? Str::random(40) : null,
                'check_in_opened_at' => $day < 0 ? $startsAt->copy()->subMinutes(20) : null,
                'check_in_closed_at' => $day < 0 ? $this->at($day, $end) : null,
            ]);

            $this->events["{$org}:{$title}"] = $event;
        }
    }

    private function at(int $days, string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()->startOfDay()->addDays($days)->setTime($hour, $minute);
    }

    /* ================================================================= */
    /* Registrations & attendance                                         */
    /* ================================================================= */

    private function createRegistrationsAndAttendance(): void
    {
        $rates = ['SSG' => 0.87, 'JITS' => 0.9, 'FES' => 0.88, 'YES' => 0.84, 'HMS' => 0.64, 'KDT' => 0.86, 'CHR' => 0.62, 'MGC' => 0.92, 'TC' => 0.8, 'CSC' => 0.78];

        // Kyla's own story: registered for these, and missed two on purpose.
        $kylaRegistrations = ['JITS:Web Dev Bootcamp: Intro to React', 'JITS:Cybersecurity Awareness Talk', 'MGC:Coastal Cleanup Drive', 'MGC:Mangrove Planting Activity',
            'SSG:Student Leadership Training', 'JITS:IT Seminar: Responsible AI', 'MGC:Mangrove Monitoring Day', 'JITS:Hack Cordova: Civic Tech Hackathon'];
        $kylaMisses = ['JITS:Cybersecurity Awareness Talk', 'MGC:MGC Monthly Meeting'];

        foreach ($this->events as $key => $event) {
            if (! $event->isPublished()) {
                continue;
            }

            $acronym = explode(':', $key)[0];
            $memberIds = Membership::query()
                ->where('organization_id', $event->organization_id)
                ->where('status', Membership::STATUS_ACTIVE)
                ->pluck('user_id');

            // Registrations (mandatory events don't need registration: members are expected).
            $registrants = collect();

            if (! $event->is_mandatory) {
                $memberShare = $event->visibility === Event::VISIBILITY_MEMBERS ? 0.75 : 0.55;
                $registrants = $memberIds->filter(fn () => $this->chance($memberShare));

                if ($event->visibility === Event::VISIBILITY_OPEN) {
                    $others = $this->students->keys()->diff($memberIds)->filter(fn () => $this->chance(0.12));
                    $registrants = $registrants->merge($others);
                }

                $registrants = $registrants->reject(fn ($id) => $id === $this->kyla->id);

                if ($event->capacity) {
                    $registrants = $registrants->take(max(1, $event->capacity - 3));
                }
            }

            if (in_array($key, $kylaRegistrations, true)) {
                $registrants->push($this->kyla->id);
            }

            if ($key === 'JITS:IT Seminar: Responsible AI') {
                $registrants->push($this->johnRey->id);
            }

            foreach ($registrants->unique() as $userId) {
                EventRegistration::query()->create([
                    'event_id' => $event->id,
                    'user_id' => $userId,
                    'created_at' => $event->starts_at->copy()->subDays(mt_rand(2, 10)),
                ]);
            }

            if (! $event->hasEnded()) {
                continue;
            }

            // Who was expected?
            $expected = $registrants->unique();
            if ($event->is_mandatory) {
                $expected = $expected->merge($memberIds)->unique();
            }

            foreach ($expected as $userId) {
                if ($userId === $this->kyla->id) {
                    if (in_array($key, $kylaMisses, true)) {
                        continue;
                    }
                    $this->attend($event, $userId, $this->chance(0.15) ? 'late' : 'present', 'qr');

                    continue;
                }

                if ($userId === $this->johnRey->id) {
                    $this->attend($event, $userId, 'present', 'qr');

                    continue;
                }

                $roll = mt_rand() / mt_getrandmax();

                if ($roll < $rates[$acronym]) {
                    $this->attend($event, $userId, $this->chance(0.11) ? 'late' : 'present', $this->chance(0.8) ? 'qr' : 'code');
                } elseif ($roll < $rates[$acronym] + 0.03) {
                    $this->attend($event, $userId, 'excused', 'excuse');
                }
            }

            // A few walk-ins at open events.
            if ($event->visibility === Event::VISIBILITY_OPEN) {
                $walkIns = $this->students->keys()->diff($expected)->shuffle()->take(mt_rand(1, 4));
                foreach ($walkIns as $userId) {
                    if ($userId !== $this->kyla->id) {
                        $this->attend($event, $userId, 'present', 'walk_in');
                    }
                }
            }
        }
    }

    private function attend(Event $event, int $userId, string $status, string $method): void
    {
        $checkedIn = match ($status) {
            'present' => $event->starts_at->copy()->subMinutes(mt_rand(0, 20))->addMinutes(mt_rand(0, 12)),
            'late' => $event->starts_at->copy()->addMinutes(mt_rand(16, 50)),
            default => null,
        };

        Attendance::query()->create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'status' => $status,
            'method' => $method,
            'checked_in_at' => $checkedIn,
        ]);
    }

    /* ================================================================= */
    /* Applications                                                       */
    /* ================================================================= */

    private function createApplications(): void
    {
        // Kyla: approved MGC application (history) and a pending one for KDT.
        $this->application($this->kyla, 'MGC', 'approved', now()->subDays(72),
            'I grew up in Barangay Gabi and saw how much the mangroves protect us during typhoons. I want to help plant and monitor them.',
            'Joined coastal cleanups with our SK.', 'Saturday mornings');

        $this->application($this->kyla, 'KDT', 'pending', now()->subDays(3),
            'I have always wanted to learn our festival dances properly and perform for CPC. I want a creative break from coding and to meet students from other programs.',
            'Danced in our barangay fiesta presentations in high school.', 'Tuesdays after 3:00 PM and Saturday mornings',
            'Yes — barangay fiesta folk dances (Tinikling, Sayaw sa Bangko).');

        // Pending applications for JITS (to review in the officer view).
        $jitsMembers = Membership::query()->where('organization_id', $this->orgs['JITS']->id)->pluck('user_id');
        $applicants = $this->students->reject(fn ($u) => $jitsMembers->contains($u->id))->take(4)->values();

        $reasons = [
            ['I want to learn web development outside class and build a portfolio before OJT.', 'Basic HTML/CSS from senior high ICT strand.', 'Thursdays after 3:00 PM', 'React and Git'],
            ['I am shifting interest to tech and want to join study jams to keep up with programming subjects.', 'Excel and a little Python on YouTube.', 'Weekday afternoons', 'Python basics'],
            ['I want to join Hack Cordova and meet teammates who also want to build apps for our town.', 'Made a simple Android app in a TESDA short course.', 'Thursdays and Saturdays', 'Mobile development'],
            ['I would like to help organize tech seminars for students and improve my leadership skills.', 'Class secretary for two years.', 'Thursdays', 'Cybersecurity'],
        ];

        foreach ($applicants as $i => $applicant) {
            [$reason, $experience, $availability, $answer] = $reasons[$i];
            $this->application($applicant, 'JITS', $i === 3 ? 'rejected' : 'pending', now()->subDays([1, 2, 5, 15][$i]), $reason, $experience, $availability, $answer);
        }

        // A few pending applications elsewhere.
        foreach (['KDT', 'CHR', 'MGC', 'TC'] as $acronym) {
            $members = Membership::query()->where('organization_id', $this->orgs[$acronym]->id)->pluck('user_id');
            $applicant = $this->students->reject(fn ($u) => $members->contains($u->id) || $u->id === $this->kyla->id)->shuffle()->first();

            $this->application($applicant, $acronym, 'pending', now()->subDays(mt_rand(1, 9)),
                'I would like to join to develop my skills, meet new friends and contribute to CPC activities this semester.',
                null, 'Weekday afternoons');
        }
    }

    private function application(User $user, string $acronym, string $status, Carbon $when, string $reason, ?string $experience, ?string $availability, ?string $answer = null): void
    {
        $organization = $this->orgs[$acronym];
        $reviewer = Membership::query()->where('organization_id', $organization->id)->where('is_officer', true)->value('user_id');

        $application = MembershipApplication::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => $status,
            'reason' => $reason,
            'experience' => $experience,
            'availability' => $availability,
            'answer' => $answer,
            'eligibility_verified_at' => $when->copy()->addHours(2),
            'reviewed_by' => $status === 'pending' ? null : $reviewer,
            'reviewed_at' => $status === 'pending' ? null : $when->copy()->addDays(2),
            'review_note' => $status === 'rejected' ? 'Thank you for applying! Our officer slots for this semester are full — please apply again next semester.' : null,
        ]);

        $application->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
    }

    /* ================================================================= */
    /* New organization proposals                                         */
    /* ================================================================= */

    private function createProposals(): void
    {
        $proposer = $this->students->first(fn (User $u) => $u->program === 'BSIT' && $u->year_level === 2 && $u->id !== $this->kyla->id);

        $proposal = OrganizationProposal::query()->create([
            'user_id' => $proposer->id,
            'name' => 'CPC Esports & Game Dev Club',
            'acronym' => 'EGDC',
            'category' => 'Arts & Culture',
            'description' => 'A community for CPC students who play competitive games and want to learn how games are designed and built.',
            'purpose' => 'Many CPC students already play and organize informal tournaments, but there is no recognized group to represent the school or to teach game development. The club would give gamers a safe, structured space and introduce students to game design and programming.',
            'planned_activities' => "Inter-program esports tournament during Intramurals\nMonthly game jam for beginners\nIntro to Unity and Godot workshops\nWatch parties and sportsmanship seminars",
            'adviser_name' => 'Mr. Renato Villanueva',
            'expected_members' => 35,
        ]);

        $proposal->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
    }

    /* ================================================================= */
    /* Excuses                                                            */
    /* ================================================================= */

    private function createExcuses(): void
    {
        $event = $this->events['JITS:Cybersecurity Awareness Talk'];
        $attendedIds = Attendance::query()->where('event_id', $event->id)->pluck('user_id');
        $absentees = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotIn('user_id', $attendedIds)
            ->whereNotIn('user_id', [$this->kyla->id])
            ->pluck('user_id')
            ->take(3)
            ->values();

        $texts = [
            ['illness', 'I had a fever and was advised by our barangay health worker to rest. I can show my medical slip.'],
            ['class_conflict', 'Our Physics laboratory exam was moved to the same afternoon by our instructor.'],
            ['family', 'I had to accompany my mother to her check-up at Vicente Sotto Memorial Medical Center.'],
        ];

        foreach ($absentees as $i => $userId) {
            $excuse = ExcuseRequest::query()->create([
                'event_id' => $event->id,
                'user_id' => $userId,
                'reason' => $texts[$i][0],
                'details' => $texts[$i][1],
                'status' => $i === 2 ? 'approved' : 'pending',
                'reviewed_by' => $i === 2 ? $this->johnRey->id : null,
                'reviewed_at' => $i === 2 ? now()->subDays(8) : null,
            ]);
            $excuse->forceFill(['created_at' => now()->subDays(11 - $i)])->save();

            if ($i === 2) {
                Attendance::query()->create(['event_id' => $event->id, 'user_id' => $userId, 'status' => 'excused', 'method' => 'excuse']);
            }
        }
    }

    /* ================================================================= */
    /* Announcements & notifications                                     */
    /* ================================================================= */

    private function createAnnouncements(): void
    {
        $items = [
            [null, $this->adviser->id, 'Semester clearance signing starts December 8', "Clearance signing for the 1st Semester runs December 8–12. Check the Attendance & Clearance page before then: if an organization shows \"Action needed\", file an excuse (within 7 days of the event) or talk to the officers now — not on the last day.\n\nBring your school ID to the OSA.", 'all', true, 2],
            [null, $this->adviser->id, 'No classes on November 1–2', "In observance of All Saints' Day and All Souls' Day, there are no classes and no organization activities on November 1 and 2. Classes resume on November 3. Ingat sa biyahe!", 'all', false, 1],
            [null, $this->adviser->id, 'Organization renewal: updated officer lists due November 15', 'All recognized organizations must update their officer list and adviser in this portal by November 15 for renewal of recognition.', 'all', false, 6],
            ['SSG', null, 'Intramurals 2026 schedule is out', 'Team assignments are by program: BSIT (Blue), BEEd/BSEd (Violet), BSBA (Cyan), BSHM (Pink). The opening and oath-taking is required for all students — check in at the Covered Court gate using the QR.', 'all', true, 4],
            ['JITS', $this->johnRey->id, 'Hack Cordova registration is open', 'Form teams of three and register through the event page. Non-IT students are welcome — we need designers and people who know Cordova well!', 'all', false, 3],
            ['JITS', $this->johnRey->id, 'Study Jam this month: bring your past quizzes', 'For the Algorithms Study Jam, bring your previous quizzes so we can review the hardest items together.', 'members', false, 5],
            ['MGC', null, 'What to bring for Mangrove Monitoring Day', 'Boots or old rubber shoes, hat, water bottle and a change of clothes. We leave CPC at 5:30 AM sharp.', 'members', false, 2],
        ];

        foreach ($items as [$org, $author, $title, $body, $audience, $pinned, $daysAgo]) {
            $organization = $org ? $this->orgs[$org] : null;
            $authorId = $author ?? ($organization
                ? Membership::query()->where('organization_id', $organization->id)->where('is_officer', true)->value('user_id')
                : $this->adviser->id);

            Announcement::query()->create([
                'organization_id' => $organization?->id,
                'user_id' => $authorId,
                'title' => $title,
                'body' => $body,
                'audience' => $audience,
                'is_pinned' => $pinned,
                'published_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    private function createNotifications(): void
    {
        $seminar = $this->events['JITS:IT Seminar: Responsible AI'];
        $meeting = $this->events['MGC:MGC Monthly Meeting'];

        $forKyla = [
            ['event_published', 'New JITS event', "\"{$seminar->title}\" — ".$seminar->starts_at->format('D, M j · g:i A').' at AVR.', "/events/{$seminar->id}", null, 1],
            ['attendance_missed', 'You missed a required meeting', "You were marked absent at \"{$meeting->title}\". File an excuse within 7 days if you had a valid reason.", '/clearance', null, 2],
            ['announcement', 'OSA: Semester clearance signing starts December 8', 'Check your Attendance & Clearance page before then.', '/announcements', null, 2],
            ['application_submitted', 'Application received', 'Your application to CPC Kultura Dance Troupe was received. Officers usually reply within 5 school days.', '/applications', now()->subDays(3), 3],
            ['application_approved', 'Welcome to MGC!', 'Your application to Mangrove Guardians Club was approved.', '/organizations/mangrove-guardians-club', now()->subDays(69), 70],
        ];

        foreach ($forKyla as [$type, $title, $body, $link, $readAt, $daysAgo]) {
            $n = UserNotification::query()->create([
                'user_id' => $this->kyla->id, 'type' => $type, 'title' => $title, 'body' => $body, 'link' => $link, 'read_at' => $readAt,
            ]);
            $n->forceFill(['created_at' => now()->subDays($daysAgo)->subHours(mt_rand(1, 8))])->save();
        }

        $pending = MembershipApplication::query()->where('organization_id', $this->orgs['JITS']->id)->where('status', 'pending')->with('user')->get();

        foreach ($pending as $application) {
            $n = UserNotification::query()->create([
                'user_id' => $this->johnRey->id,
                'type' => 'application_submitted',
                'title' => 'New membership application',
                'body' => "{$application->user->name} ({$application->user->program_label}) applied to join JITS.",
                'link' => '/officer/applications',
            ]);
            $n->forceFill(['created_at' => $application->created_at])->save();
        }

        $n = UserNotification::query()->create([
            'user_id' => $this->adviser->id,
            'type' => 'event_submitted',
            'title' => 'Event waiting for approval',
            'body' => 'JITS submitted "Intro to Git & GitHub Workshop".',
            'link' => '/adviser/events',
        ]);
        $n->forceFill(['created_at' => now()->subDays(1)])->save();
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }
}
