<?php

namespace Crescat\SaloonSdkGenerator\Generators\Traits;

use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Nette\PhpGenerator\Parameter;
use Nette\PhpGenerator\PromotedParameter;
use Nette\PhpGenerator\Property;

/**
 * DTO Assertions Trait
 *
 * Provides generic functionality for generating test assertions and mock data
 * from DTOs in the generated code. This trait can be used by any test generator
 * that needs to inspect DTOs and generate realistic test fixtures.
 */
trait DtoAssertions
{
    /**
     * The GeneratedCode instance (must be provided by the class using this trait)
     */
    protected GeneratedCode $generatedCode;

    /**
     * The namespace for the generated SDK (must be provided by the class using this trait)
     */
    protected string $namespace;

    /**
     * Generate DTO assertions based on mock data
     *
     * Override this method in child classes to customize assertion generation
     * based on your API's response format (e.g., JSON:API, plain JSON, etc.)
     */
    protected function generateDtoAssertions(array $mockData, string $prefix = ''): string
    {
        // Default implementation - expects simple key-value structure
        $attributes = $mockData;

        if (empty($attributes)) {
            return '        // No attributes to validate';
        }

        $assertions = [];

        foreach ($attributes as $key => $value) {
            // Handle nested objects recursively
            if (is_array($value) && ! empty($value)) {
                // Generate assertions for nested object
                $nestedPrefix = $prefix ? "{$prefix}->{$key}" : $key;
                $nestedAssertions = $this->generateNestedAssertions($value, $nestedPrefix);
                if ($nestedAssertions) {
                    $assertions[] = $nestedAssertions;
                }

                continue;
            }

            // Skip null/empty arrays
            if (is_null($value) || (is_array($value) && empty($value))) {
                continue;
            }

            $propertyPath = $prefix ? "{$prefix}->{$key}" : $key;
            $assertion = $this->generateAssertionForValue($propertyPath, $value);
            $assertions[] = $assertion;
        }

        if (empty($assertions)) {
            return '        // No attributes to validate';
        }

        return implode("\n", $assertions);
    }

    /**
     * Generate assertions for nested object properties
     */
    protected function generateNestedAssertions(array $nestedData, string $prefix): string
    {
        $assertions = [];

        foreach ($nestedData as $key => $value) {
            if (is_array($value) && ! empty($value)) {
                // Recursively handle deeper nesting
                $assertions[] = $this->generateNestedAssertions($value, "{$prefix}->{$key}");

                continue;
            }

            if (is_null($value) || (is_array($value) && empty($value))) {
                continue;
            }

            $propertyPath = "{$prefix}->{$key}";
            $assertions[] = $this->generateAssertionForValue($propertyPath, $value);
        }

        return implode("\n", $assertions);
    }

    /**
     * Get DTO constructor parameters from generated code
     *
     * @return array<string, \Nette\PhpGenerator\Parameter|\Nette\PhpGenerator\PromotedParameter>
     */
    protected function getDtoPropertiesFromGeneratedCode(string $dtoClassName): array
    {
        // Strip namespace from fully qualified class name to get just the class name
        $parts = explode('\\', $dtoClassName);
        $classNameOnly = end($parts);

        // Check if DTO exists in generated code (case-sensitive first)
        if (! isset($this->generatedCode->dtoClasses[$classNameOnly])) {
            // Try case-insensitive lookup as fallback
            $classNameLower = strtolower($classNameOnly);
            foreach ($this->generatedCode->dtoClasses as $key => $value) {
                if (strtolower($key) === $classNameLower) {
                    $classNameOnly = $key;
                    break;
                }
            }

            // If still not found, return empty
            if (! isset($this->generatedCode->dtoClasses[$classNameOnly])) {
                return [];
            }
        }

        $phpFile = $this->generatedCode->dtoClasses[$classNameOnly];
        $parameters = [];

        // Get the first namespace in the file
        $namespace = array_values($phpFile->getNamespaces())[0] ?? null;
        if (! $namespace) {
            return [];
        }

        // Get the first class in the namespace
        $classType = array_values($namespace->getClasses())[0] ?? null;
        if (! $classType) {
            return [];
        }

        // Get the constructor
        $constructor = $classType->getMethod('__construct');
        if (! $constructor) {
            return [];
        }

        // Extract parameters from the constructor
        foreach ($constructor->getParameters() as $parameter) {

            // Skip parameters with specific attributes that indicate they shouldn't be in tests
            // Child classes can override this logic
            if ($this->shouldSkipParameter($parameter)) {
                continue;
            }

            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    /**
     * Determine if a parameter should be skipped based on its attributes
     * Override this method in child classes to customize filtering
     */
    protected function shouldSkipParameter(Parameter|PromotedParameter $parameter): bool
    {
        // Default: don't skip based on attributes
        // Child classes can check for specific attributes (e.g., Relationship)
        return false;
    }

    /**
     * Get list of property names to skip when generating mock test data
     * Override this method in child classes to customize which properties to skip
     *
     * @return string[]
     */
    protected function getPropertiesToSkipInTests(): array
    {
        // Skip ID and timestamps - these are typically read-only
        return ['id', 'createdAt', 'updatedAt', 'deletedAt'];
    }

    /**
     * Generate mock attributes from DTO constructor parameters
     */
    protected function generateMockAttributesFromDto(string $dtoClassName): array
    {
        $parameters = $this->getDtoPropertiesFromGeneratedCode($dtoClassName);

        if (empty($parameters)) {
            // Don't fallback to fake data - return empty array to expose the real issue
            return [];
        }

        $attributes = [];

        foreach ($parameters as $parameter) {

            // Skip parameters that are typically read-only or handled separately
            if (in_array($parameter->getName(), $this->getPropertiesToSkipInTests())) {
                continue;
            }

            $attributes[$parameter->getName()] = $this->generateMockValueForDtoParameter($parameter);
        }

        return $attributes;
    }

    /**
     * Check if a type string represents a DTO class
     */
    protected function isDtoClass(?string $typeName): bool
    {
        if (! $typeName || ! str_contains($typeName, '\\')) {
            return false;
        }

        // Check if it's in our DTO namespace
        if (! isset($this->namespace)) {
            return false;
        }

        $dtoNamespacePart = '\\Dto\\';

        return str_contains($typeName, $dtoNamespacePart);
    }

    /**
     * Generate a mock value for a DTO parameter based on its type
     */
    protected function generateMockValueForDtoParameter(Parameter|PromotedParameter $parameter): mixed
    {
        $nullable = $parameter->isNullable();

        // Normalize type name (remove nullable prefix)
        $typeName = $parameter->getType();

        // Handle union types (e.g., "string|null", "string|float", "int|null")
        if ($typeName && str_contains($typeName, '|')) {
            $types = explode('|', $typeName);
            // Filter out 'null' and 'mixed', get the first concrete type
            $concreteTypes = array_filter($types, fn ($t) => ! in_array(trim($t), ['null', 'mixed']));

            if (! empty($concreteTypes)) {
                // Use the first concrete type
                $typeName = trim(reset($concreteTypes));
            } else {
                // If all types are null/mixed, default to string
                $typeName = 'string';
            }
        }

        // Handle nested DTO types recursively
        if ($this->isDtoClass($typeName)) {
            // Recursively generate mock data for nested DTO
            return $this->generateMockAttributesFromDto($typeName);
        }

        // DateTime fields
        if ($typeName && (str_contains($typeName, 'Carbon') || str_contains($typeName, 'DateTime'))) {
            return '2025-11-22T10:40:04+00:00';
        }

        // Type-based generation (type takes precedence over name-based heuristics)
        if ($typeName === 'bool') {
            return true;
        }

        if ($typeName === 'int') {
            return 42;
        }

        if ($typeName === 'float') {
            return 3.14;
        }

        if ($typeName === 'array') {
            return [];
        }

        if ($typeName === 'object') {
            return (object) [];
        }

        if ($typeName === 'mixed' || $typeName === null) {
            return 'Mixed value';
        }

        // String type - apply name-based heuristics
        if ($typeName === 'string') {
            // ID fields
            if (str_ends_with($parameter->getName(), 'Id')) {
                return 'mock-id-123';
            }

            // Email fields
            if (str_contains($parameter->getName(), 'email') || str_contains($parameter->getName(), 'Email')) {
                return 'test@example.com';
            }

            return 'String value';
        }

        if ($nullable) {
            return null;
        }

        // Fallback for unknown types
        return 'Mock value';
    }

    /**
     * Generate an assertion for a specific attribute value
     */
    protected function generateAssertionForValue(string $propertyPath, mixed $value): string
    {
        // Handle different value types
        if (is_bool($value)) {
            $expected = $value ? 'true' : 'false';

            return "        ->{$propertyPath}->toBe({$expected})";
        }

        if (is_int($value)) {
            return "        ->{$propertyPath}->toBe({$value})";
        }

        if (is_float($value)) {
            return "        ->{$propertyPath}->toBe({$value})";
        }

        if (is_null($value)) {
            return "        ->{$propertyPath}->toBeNull()";
        }

        if (is_object($value)) {
            return "        ->{$propertyPath}->toBeInstanceOf(stdClass::class)";
        }

        if (is_array($value)) {
            return "        ->{$propertyPath}->toBeArray()";
        }

        // Check if it's a datetime string
        if (is_string($value) && $this->isDateTimeString($value)) {
            return "        ->{$propertyPath}->toEqual(new \\Carbon\\Carbon(\"{$value}\"))";
        }

        // Default: string value
        $escapedValue = addslashes($value);

        return "        ->{$propertyPath}->toBe(\"{$escapedValue}\")";
    }

    /**
     * Check if a string is a datetime format
     */
    protected function isDateTimeString(string $value): bool
    {
        // Check for ISO 8601 format (e.g., 2025-11-22T10:40:04+00:00)
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value);
    }
}
