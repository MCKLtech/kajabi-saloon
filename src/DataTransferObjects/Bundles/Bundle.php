<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Bundles;

use WooNinja\LMSContracts\Contracts\DTOs\Bundles\BundleInterface;

/**
 * A Kajabi offer as a Thinkific bundle: id is the offer id, course_ids are
 * the ids of the course products it grants
 */
final class Bundle implements BundleInterface
{
    /**
     * @param int[] $course_ids
     */
    public function __construct(
        public int|string  $id,
        public string      $name,
        public string|null $description,
        public string      $banner_image_url,
        public array       $course_ids,
        public string      $bundle_card_image_url,
        public string|null $tagline,
        public string      $slug,
    )
    {
    }

    /**
     * @param array $offer Offer resource (JSON:API data)
     * @param int[] $courseProductIds
     */
    public static function fromKajabiOffer(array $offer, array $courseProductIds): self
    {
        $description = $offer['attributes']['description'] ?? null;

        return new self(
            id: (int) $offer['id'],
            name: $offer['attributes']['title'] ?? $offer['attributes']['internal_title'] ?? '',
            description: $description !== null ? trim(preg_replace('/\s+/', ' ', strip_tags((string) $description))) : null,
            banner_image_url: '',
            course_ids: array_values($courseProductIds),
            bundle_card_image_url: '',
            tagline: null,
            slug: $offer['attributes']['token'] ?? '',
        );
    }
}
