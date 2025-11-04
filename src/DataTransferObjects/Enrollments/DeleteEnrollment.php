<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Enrollments;


use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\DeleteEnrollmentInterface;

final class DeleteEnrollment implements DeleteEnrollmentInterface
{
    public function __construct(
        public int  $enrollment_id,
        public ?int $user_id,
        public ?int $course_id
    )
    {
    }
}
