<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\DeleteEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\ReadEnrollment;

/**
 * A class whose signature drifts from an lms-contracts interface only fails
 * when it's first loaded - at runtime, in the consumer's app. Load every class.
 */
class AllClassesLoadTest extends TestCase
{
    public function test_every_class_in_src_loads(): void
    {
        $src = realpath(__DIR__ . '/../../src');
        $loaded = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'WooNinja\\KajabiSaloon\\' . str_replace(
                ['/', '.php'],
                ['\\', ''],
                substr($file->getPathname(), strlen($src) + 1)
            );

            $this->assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class),
                "{$class} does not load"
            );
            $loaded++;
        }

        $this->assertGreaterThan(90, $loaded);
    }

    public function test_enrollment_request_dtos_accept_string_ids(): void
    {
        // Enrollment ids are "{offer}{contact}" strings, too large for an int
        $this->assertSame('29000000012800000001', (new ReadEnrollment('29000000012800000001', 1, 2))->enrollment_id);
        $this->assertSame('29000000012800000001', (new DeleteEnrollment('29000000012800000001', 1, 2))->enrollment_id);
        $this->assertSame(0, (new DeleteEnrollment(0, 1, 2))->enrollment_id);
    }
}
