<?php

declare(strict_types=1);

namespace WooNinja\KajabiSaloon\DataTransferObjects\CustomProfileFieldDefinitions;

use WooNinja\LMSContracts\Contracts\DTOs\CustomProfileFieldDefinitions\CustomProfileFieldDefinitionInterface;

class CustomProfileFieldDefinition implements CustomProfileFieldDefinitionInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly string $field_type,
        public readonly bool $required,
        public readonly ?string $created_at = null
    ) {
    }

    /**
     * Create DTO from Kajabi custom field data
     *
     * Maps Kajabi format to Thinkific format:
     * - id: extracted from string ID
     * - label: from attributes.title
     * - field_type: always "text" (as per Thinkific format)
     * - required: from attributes.required
     * - created_at: current timestamp (Kajabi doesn't provide this)
     */
    public static function fromKajabiCustomField(array $data): self
    {
        $attributes = $data['attributes'] ?? [];

        // Extract ID from string (Kajabi uses string IDs)
        $id = is_numeric($data['id']) ? (int) $data['id'] : (int) filter_var($data['id'], FILTER_SANITIZE_NUMBER_INT);

        return new self(
            id: $id,
            label: $attributes['title'] ?? '',
            field_type: 'text', // Always "text" as per requirements
            required: $attributes['required'] ?? false,
            created_at: date('c') // ISO 8601 format
        );
    }

    /**
     * Create from array (for compatibility with lms-contracts)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            label: $data['label'],
            field_type: $data['field_type'] ?? 'text',
            required: $data['required'],
            created_at: $data['created_at'] ?? null
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'label' => $this->label,
            'field_type' => $this->field_type,
            'required' => $this->required,
        ];

        if ($this->created_at !== null) {
            $result['created_at'] = $this->created_at;
        }

        return $result;
    }

    // Interface implementations
    public function getId(): int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getFieldType(): string
    {
        return $this->field_type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }
}