<?php

namespace WooNinja\KajabiSaloon\Requests\Concerns;

/**
 * Fluent JSON:API query helpers for requests sent directly through the connector
 *
 * ```php
 * $connector->send(
 *     (new GetCustomers())->fields('customers', 'contact')->pageSize(1000)
 * );
 * $connector->send((new GetContact(123))->include('customer', 'tags'));
 * ```
 *
 * Sparse fieldsets drop relationships that aren't named: to keep one, list it
 * in fields() alongside the attributes.
 */
trait JsonApiQuery
{
    /**
     * Only these attributes/relationships of $type (fields[type]=a,b)
     */
    public function fields(string $type, string ...$fields): static
    {
        $this->query()->add("fields[{$type}]", implode(',', $fields));

        return $this;
    }

    /**
     * Side-load related resources into "included" (adds to any existing include)
     */
    public function include(string ...$relationships): static
    {
        $existing = array_filter(explode(',', (string) $this->query()->get('include', '')));
        $this->query()->add('include', implode(',', array_unique([...$existing, ...$relationships])));

        return $this;
    }

    /**
     * Sort by fields; prefix with "-" for descending (sort=-created_at)
     */
    public function sort(string ...$fields): static
    {
        $this->query()->add('sort', implode(',', $fields));

        return $this;
    }

    /**
     * filter[$name]=$value. Kajabi ignores unsupported filters silently - see
     * supportedFilters() where the request declares them.
     */
    public function filter(string $name, mixed $value): static
    {
        $this->query()->add("filter[{$name}]", $value instanceof \DateTimeInterface
            ? $value->format(\DateTimeInterface::ATOM)
            : $value);

        return $this;
    }

    public function pageSize(int $size): static
    {
        $this->query()->add('page[size]', $size);

        return $this;
    }
}
