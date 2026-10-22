<?php

namespace Tests\Fixtures;

/**
 * Member activity webhook deliveries and course progress responses
 * (made-up ids and data, in Kajabi's delivery shape)
 */
class MemberActivityPayloads
{
    public static function customer(array $meta = []): array
    {
        return [
            'id' => '5000001',
            'type' => 'customers',
            'attributes' => [
                'name' => 'Jane Example',
                'email' => 'jane@example.com',
                'avatar' => null,
                'external_user_id' => null,
                'public_bio' => null,
                'public_location' => null,
                'public_website' => null,
                'socials' => null,
                'net_revenue' => '0.0',
                'sign_in_count' => 12,
                'last_request_at' => '2026-10-20T19:03:40.512Z',
                'bounced_at' => null,
                'created_at' => '2026-09-01T15:22:10.004Z',
                'updated_at' => '2026-10-20T19:03:40.515Z',
                'last_sign_in_at' => '2026-10-20T18:55:02.118Z',
            ],
            'links' => [
                'contact' => 'https://app.kajabi.com/api/v1/contacts/6000001',
            ],
            'relationships' => [
                'site' => ['data' => ['id' => '111111', 'type' => 'sites']],
                'contact' => ['data' => ['id' => '6000001', 'type' => 'contacts']],
                'offers' => [
                    'data' => [['id' => '7000001', 'type' => 'offers']],
                    'links' => [
                        'self' => 'https://app.kajabi.com/api/v1/customers/5000001/relationships/offers',
                    ],
                ],
                'products' => [
                    'data' => [['id' => '2000001', 'type' => 'products']],
                ],
            ],
            'meta' => $meta,
        ];
    }

    public static function lesson(bool $withMedia = false): array
    {
        $lesson = [
            'id' => '3000001',
            'type' => 'lessons',
            'attributes' => [
                'title' => 'Welcome and setup',
                'position' => 1,
                'status' => 'published',
                'publishing_option' => 'published',
            ],
            'relationships' => [
                'module' => ['data' => ['id' => '4000001', 'type' => 'modules']],
            ],
        ];

        if ($withMedia) {
            $lesson['relationships']['media'] = ['data' => ['id' => '4500001', 'type' => 'media']];
        }

        return $lesson;
    }

    public static function course(): array
    {
        return [
            'id' => '2000001',
            'type' => 'courses',
            'attributes' => [
                'created_at' => '2026-08-15T14:00:00.000Z',
                'title' => 'Example Course',
                'description' => 'An example course',
                'thumbnail_url' => 'https://example.com/thumbnail.jpg',
            ],
            'relationships' => [
                'site' => ['data' => ['id' => '111111', 'type' => 'sites']],
                'offers' => ['data' => [['id' => '7000001', 'type' => 'offers']]],
                'modules' => ['data' => [['id' => '4000001', 'type' => 'modules']]],
                'lessons' => [
                    'data' => [
                        ['id' => '3000001', 'type' => 'lessons'],
                        ['id' => '3000002', 'type' => 'lessons'],
                    ],
                ],
            ],
        ];
    }

    public static function contact(): array
    {
        return [
            'id' => '6000001',
            'type' => 'contacts',
            'attributes' => [
                'name' => 'Jane Example',
                'email' => 'jane@example.com',
                'address_line_1' => null,
                'address_line_2' => null,
                'address_city' => null,
                'address_country' => null,
                'address_state' => null,
                'address_zip' => null,
                'phone_number' => null,
                'business_number' => null,
                'subscribed' => true,
                'external_user_id' => null,
                'created_at' => '2026-09-01T15:22:09.871Z',
                'updated_at' => '2026-10-20T19:30:05.120Z',
            ],
            'links' => [
                'customer' => 'https://app.kajabi.com/api/v1/customers/5000001',
            ],
            'relationships' => [
                'site' => ['data' => ['id' => '111111', 'type' => 'sites']],
                'customer' => ['data' => ['id' => '5000001', 'type' => 'customers']],
            ],
        ];
    }

    public static function lessonCompleted(string $id = '6b1f2c3e-0000-4000-8000-000000000001'): array
    {
        return [
            'id' => $id,
            'event' => 'lesson_completed',
            'payload' => [
                self::customer(['completed_at' => '2026-10-20T19:04:11.000Z']),
                self::lesson(),
                self::course(),
            ],
        ];
    }

    public static function courseStarted(): array
    {
        return [
            'id' => '6b1f2c3e-0000-4000-8000-000000000002',
            'event' => 'course_started',
            'payload' => [
                self::customer(['started_at' => '2026-10-20T19:04:12.207Z']),
                self::course(),
            ],
        ];
    }

    public static function courseCompleted(): array
    {
        return [
            'id' => '6b1f2c3e-0000-4000-8000-000000000003',
            'event' => 'course_completed',
            'payload' => [
                self::customer(['completed_at' => '2026-10-20T19:20:31.845Z']),
                self::course(),
            ],
        ];
    }

    public static function assessmentCompleted(?bool $passed = true, ?int $grade = 80): array
    {
        return [
            'id' => '6b1f2c3e-0000-4000-8000-000000000004',
            'event' => 'assessment_completed',
            'payload' => [
                self::contact(),
                [
                    'id' => '8000001',
                    'type' => 'assessments',
                    'attributes' => ['title' => 'Module 1 quiz'],
                ],
                [
                    'id' => '9000001',
                    'type' => 'assessment_results',
                    'attributes' => [
                        'passed' => $passed,
                        'grade' => $grade,
                        'submitted_at' => '2026-10-20T19:30:04.902Z',
                    ],
                ],
            ],
        ];
    }

    public static function memberSignedIn(): array
    {
        return [
            'id' => '6b1f2c3e-0000-4000-8000-000000000005',
            'event' => 'member_signed_in',
            'payload' => [
                self::customer(['signed_in_at' => '2026-10-20T18:55:02.118Z']),
            ],
        ];
    }

    public static function hook(array $attributes = []): array
    {
        return [
            'id' => '990001',
            'type' => 'hooks',
            'attributes' => $attributes + [
                'event' => 'lesson_completed',
                'target_url' => 'https://hooks.example.com/kajabi/lesson-completed',
                'resource_id' => null,
                'created_at' => '2026-10-20T19:00:00.000Z',
                'updated_at' => '2026-10-20T19:00:00.000Z',
            ],
            'relationships' => [
                'site' => ['data' => ['id' => '111111', 'type' => 'sites']],
            ],
        ];
    }

    public static function progressRowComplete(): array
    {
        return [
            'id' => '900001',
            'type' => 'course_progress',
            'attributes' => [
                'customer_id' => 5000001,
                'completed_lessons' => 2,
                'total_lessons' => 2,
                'percent_complete' => 100,
                'completed' => true,
                'updated_at' => '2026-10-20T19:20:31.845Z',
                'lessons' => [
                    ['lesson_id' => 3000001, 'completed_at' => '2026-10-20T19:04:11.000Z', 'progress_percent' => 100],
                    ['lesson_id' => 3000002, 'completed_at' => '2026-10-20T19:20:31.000Z', 'progress_percent' => null],
                ],
            ],
        ];
    }

    public static function progressRowPartial(): array
    {
        return [
            'id' => '900002',
            'type' => 'course_progress',
            'attributes' => [
                'customer_id' => 5000002,
                'completed_lessons' => 1,
                'total_lessons' => 2,
                'percent_complete' => 50,
                'completed' => false,
                'updated_at' => '2026-10-20T19:41:07.310Z',
                'lessons' => [
                    ['lesson_id' => 3000001, 'completed_at' => '2026-10-20T19:41:07.000Z', 'progress_percent' => 100],
                    ['lesson_id' => 3000002, 'completed_at' => null, 'progress_percent' => 40],
                ],
            ],
        ];
    }

    public static function progressRowEmpty(string $id = '900003', int $customerId = 5000003): array
    {
        return [
            'id' => $id,
            'type' => 'course_progress',
            'attributes' => [
                'customer_id' => $customerId,
                'completed_lessons' => 0,
                'total_lessons' => 3,
                'percent_complete' => 0,
                'completed' => false,
                'updated_at' => '2026-10-19T08:00:00.000Z',
                'lessons' => [
                    ['lesson_id' => 3100001, 'completed_at' => null, 'progress_percent' => null],
                    ['lesson_id' => 3100002, 'completed_at' => null, 'progress_percent' => null],
                    ['lesson_id' => 3100003, 'completed_at' => null, 'progress_percent' => null],
                ],
            ],
        ];
    }

    /**
     * A course_progress page; $nextAfter adds links.next with page[after]
     */
    public static function progressPage(array $rows, ?string $nextAfter = null, int $productId = 2000001): array
    {
        $links = ['self' => "https://api.kajabi.com/v1/course_progress?filter[product_id]={$productId}&page[size]=2"];

        if ($nextAfter !== null) {
            $links['next'] = "https://api.kajabi.com/v1/course_progress?filter[product_id]={$productId}&page[size]=2&page[after]={$nextAfter}";
        }

        return ['data' => $rows, 'links' => $links];
    }
}
