<?php

namespace WooNinja\KajabiSaloon\Traits;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

trait MapperTrait
{
    /**
     * Attempts to map a source DTO to a target DTO
     *
     * @param $sourceDTO
     * @param $targetDTO
     * @return mixed
     * @throws ReflectionException
     */
    public function mapDTO($sourceDTO, $targetDTO): mixed
    {
        $targetReflectionClass = new ReflectionClass($targetDTO);
        $targetProperties = $targetReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);

        $values = [];

        foreach ($targetProperties as $property) {
            $propertyName = $property->getName();

            if (property_exists($sourceDTO, $propertyName)) {
                $values[$propertyName] = $sourceDTO->$propertyName;
            }
        }

        return new $targetDTO(...$values);
    }
}