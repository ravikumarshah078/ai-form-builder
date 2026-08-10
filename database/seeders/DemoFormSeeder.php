<?php

namespace Database\Seeders;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One realistic, published form with responses, so a reviewer landing on the
 * demo URL sees a working product rather than an empty state.
 *
 * The form is the exact example the brief uses for AI generation ("internship
 * application with education history, skills and resume upload"), which makes
 * it easy to compare the seeded version against what the AI produces.
 */
class DemoFormSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', DemoUserSeeder::EMAIL)->firstOrFail();

        // Idempotent: re-seeding should not pile up duplicate demo forms.
        if (Form::where('user_id', $user->id)->where('slug', 'internship-application')->exists()) {
            return;
        }

        DB::transaction(function () use ($user) {
            $form = Form::create([
                'user_id' => $user->id,
                'title' => 'Internship Application',
                'slug' => 'internship-application',
                'description' => 'Tell us about yourself and attach your resume.',
                'status' => FormStatus::Published,
                'published_at' => now(),
                'settings' => ['multi_step' => false, 'submit_label' => 'Submit application'],
            ]);

            $schema = $this->schema();

            $version = FormVersion::create([
                'form_id' => $form->id,
                'version_number' => 1,
                'schema' => $schema,
                'checksum' => FormVersion::checksumFor($schema),
                'origin' => 'manual',
                'note' => 'Seeded demo form',
                'created_by' => $user->id,
            ]);

            $form->update(['current_version_id' => $version->id]);

            foreach ($this->responses() as $data) {
                FormSubmission::create([
                    'form_id' => $form->id,
                    'form_version_id' => $version->id,
                    'data' => $data,
                    'meta' => ['ip' => '127.0.0.1', 'source' => 'seeder'],
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'version' => 1,
            'title' => 'Internship Application',
            'description' => 'Tell us about yourself and attach your resume.',
            'settings' => ['multi_step' => false],
            'sections' => [
                [
                    'id' => 'sec_personal',
                    'title' => 'Personal details',
                    'description' => null,
                    'fields' => [
                        [
                            'id' => 'fld_full_name',
                            'key' => 'full_name',
                            'type' => 'text',
                            'label' => 'Full name',
                            'placeholder' => 'Jane Doe',
                            'help' => null,
                            'default' => null,
                            'required' => true,
                            'options' => [],
                            'validation' => ['min_length' => 2, 'max_length' => 120],
                        ],
                        [
                            'id' => 'fld_email',
                            'key' => 'email',
                            'type' => 'email',
                            'label' => 'Email address',
                            'placeholder' => 'jane@example.com',
                            'help' => 'We will only use this to contact you about your application.',
                            'default' => null,
                            'required' => true,
                            'options' => [],
                            'validation' => [],
                        ],
                        [
                            'id' => 'fld_phone',
                            'key' => 'phone',
                            'type' => 'phone',
                            'label' => 'Phone number',
                            'placeholder' => '+91 98765 43210',
                            'help' => null,
                            'default' => null,
                            'required' => false,
                            'options' => [],
                            'validation' => ['regex' => '^[0-9+\\-\\s()]{7,20}$'],
                        ],
                    ],
                ],
                [
                    'id' => 'sec_education',
                    'title' => 'Education history',
                    'description' => 'Your most recent qualification.',
                    'fields' => [
                        [
                            'id' => 'fld_institution',
                            'key' => 'institution',
                            'type' => 'text',
                            'label' => 'Institution',
                            'placeholder' => 'University name',
                            'help' => null,
                            'default' => null,
                            'required' => true,
                            'options' => [],
                            'validation' => ['max_length' => 200],
                        ],
                        [
                            'id' => 'fld_degree',
                            'key' => 'degree',
                            'type' => 'dropdown',
                            'label' => 'Degree',
                            'placeholder' => 'Select a degree',
                            'help' => null,
                            'default' => null,
                            'required' => true,
                            'options' => [
                                ['value' => 'bsc', 'label' => 'B.Sc.'],
                                ['value' => 'btech', 'label' => 'B.Tech.'],
                                ['value' => 'bca', 'label' => 'BCA'],
                                ['value' => 'other', 'label' => 'Other'],
                            ],
                            'validation' => [],
                        ],
                        [
                            'id' => 'fld_graduation',
                            'key' => 'graduation_date',
                            'type' => 'date',
                            'label' => 'Graduation date',
                            'placeholder' => null,
                            'help' => null,
                            'default' => null,
                            'required' => false,
                            'options' => [],
                            'validation' => [],
                        ],
                    ],
                ],
                [
                    'id' => 'sec_skills',
                    'title' => 'Skills and resume',
                    'description' => null,
                    'fields' => [
                        [
                            'id' => 'fld_skills',
                            'key' => 'skills',
                            'type' => 'checkbox',
                            'label' => 'Which of these have you worked with?',
                            'placeholder' => null,
                            'help' => 'Select all that apply.',
                            'default' => null,
                            'required' => false,
                            'options' => [
                                ['value' => 'php', 'label' => 'PHP'],
                                ['value' => 'laravel', 'label' => 'Laravel'],
                                ['value' => 'mysql', 'label' => 'MySQL'],
                                ['value' => 'javascript', 'label' => 'JavaScript'],
                                ['value' => 'python', 'label' => 'Python'],
                            ],
                            'validation' => [],
                        ],
                        [
                            'id' => 'fld_experience',
                            'key' => 'experience',
                            'type' => 'textarea',
                            'label' => 'Briefly describe your relevant experience',
                            'placeholder' => 'Projects, internships, open source…',
                            'help' => null,
                            'default' => null,
                            'required' => false,
                            'options' => [],
                            'validation' => ['max_length' => 2000],
                        ],
                        [
                            'id' => 'fld_resume',
                            'key' => 'resume',
                            'type' => 'file',
                            'label' => 'Resume',
                            'placeholder' => null,
                            'help' => 'PDF or Word document, up to 5 MB.',
                            'default' => null,
                            'required' => true,
                            'options' => [],
                            'validation' => ['mimes' => ['pdf', 'doc', 'docx'], 'max_kb' => 5120],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function responses(): array
    {
        return [
            [
                'full_name' => 'Priya Sharma',
                'email' => 'priya.sharma@example.com',
                'phone' => '+91 98765 43210',
                'institution' => 'Indian Institute of Technology, Delhi',
                'degree' => 'btech',
                'graduation_date' => '2026-05-30',
                'skills' => ['php', 'laravel', 'mysql'],
                'experience' => 'Built a ticketing system in Laravel for my college fest.',
            ],
            [
                'full_name' => 'Arjun Mehta',
                'email' => 'arjun.mehta@example.com',
                'phone' => '+91 91234 56789',
                'institution' => 'University of Mumbai',
                'degree' => 'bca',
                'graduation_date' => '2025-06-15',
                'skills' => ['javascript', 'python'],
                'experience' => 'Two open source contributions to a charting library.',
            ],
            [
                'full_name' => 'Fatima Khan',
                'email' => 'fatima.khan@example.com',
                'phone' => null,
                'institution' => 'Anna University',
                'degree' => 'bsc',
                'graduation_date' => '2026-04-20',
                'skills' => ['php', 'mysql'],
                'experience' => 'Freelance WordPress work for three local businesses.',
            ],
        ];
    }
}
