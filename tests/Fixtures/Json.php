<?php

namespace Tests\Fixtures;

/**
 * JSON fixtures under tests/Fixtures/{resource}/, as Kajabi returns them
 */
final class Json
{
    public static function load(string $path): array
    {
        return json_decode(file_get_contents(__DIR__ . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The resource ("data") of a fixture
     */
    public static function data(string $path): array
    {
        return self::load($path)['data'];
    }

    /**
     * GET /v1/products answering with these product fixtures, one page
     */
    public static function productsPage(string ...$paths): array
    {
        return ['data' => array_map(fn(string $path) => self::data($path), $paths), 'links' => []];
    }

    /**
     * The site catalog the bundle tests share: courses A and B, access group C
     */
    public static function catalog(): array
    {
        return self::productsPage('products/course-a.json', 'products/course-b.json', 'products/access-group-c.json');
    }
}
