<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Enrollments;

use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\ReadEnrollmentInterface;

final class ReadEnrollment implements ReadEnrollmentInterface
{
    public function __construct(
        public int|string $enrollment_id, // enrollment ids are "{offer}{contact}" strings
        public ?int       $user_id,
        public ?int       $course_id,
    )
    {
    }
}
