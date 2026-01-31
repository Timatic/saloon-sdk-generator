<?php

namespace Crescat\SaloonSdkGenerator\Helpers;

use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Nette\PhpGenerator\Parameter;
use Nette\PhpGenerator\PhpFile;

/**
 * Centralized DTO resolution and property extraction.
 *
 * Handles finding DTOs, converting schema names to class names,
 * and extracting properties from generated DTOs.
 */
class DtoResolver
{
    protected ?GeneratedCode $generatedCode = null;

    public function __construct(
        protected Config $config
    ) {}

    /**
     * Set the GeneratedCode instance (required for DTO lookup operations).
     * Should be called by PostProcessors after generation is complete.
     */
    public function setGeneratedCode(?GeneratedCode $generatedCode): self
    {
        $this->generatedCode = $generatedCode;

        return $this;
    }

    /**
     * Convert schema name to DTO class name using canonical naming convention.
     * Works in both early (Generator) and late (PostProcessor) phases.
     */
    public function schemaNameToDtoClassName(string $schemaName): string
    {
        return NameHelper::dtoClassName($schemaName);
    }

    /**
     * Build fully qualified DTO class name from schema name.
     * Returns FQN without leading backslash (e.g., "Company\Integration\Dto\UserDto")
     */
    public function buildDtoFqn(string $schemaName): string
    {
        $className = $this->schemaNameToDtoClassName($schemaName);

        return "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$className}";
    }

    /**
     * Extract DTO class name from FQN (handles both FQN and short names).
     */
    public function extractClassName(string $classNameOrFqn): string
    {
        $parts = explode('\\', $classNameOrFqn);

        return end($parts);
    }

    /**
     * Find DTO in GeneratedCode (requires GeneratedCode to be set).
     * Uses case-insensitive fallback if exact match fails.
     * Returns null if not found.
     */
    public function findDto(string $classNameOrFqn): ?PhpFile
    {
        if (! $this->generatedCode) {
            return null;
        }

        $className = $this->extractClassName($classNameOrFqn);

        // Try exact match first
        if (isset($this->generatedCode->dtoClasses[$className])) {
            return $this->generatedCode->dtoClasses[$className];
        }

        // Case-insensitive fallback
        $classNameLower = strtolower($className);
        foreach ($this->generatedCode->dtoClasses as $key => $value) {
            if (strtolower($key) === $classNameLower) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Check if a DTO exists in GeneratedCode.
     */
    public function dtoExists(string $classNameOrFqn): bool
    {
        return $this->findDto($classNameOrFqn) !== null;
    }

    /**
     * Extract constructor parameters from a DTO PhpFile.
     * Returns array of Nette Parameter objects keyed by parameter name.
     *
     * @return array<string, Parameter>
     */
    public function extractDtoProperties(
        PhpFile $dtoPhpFile,
        ?callable $shouldSkip = null
    ): array {
        $parameters = [];

        $namespace = array_values($dtoPhpFile->getNamespaces())[0] ?? null;
        if (! $namespace) {
            return [];
        }

        $classType = array_values($namespace->getClasses())[0] ?? null;
        if (! $classType) {
            return [];
        }

        $constructor = $classType->getMethod('__construct');
        if (! $constructor) {
            return [];
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($shouldSkip && $shouldSkip($parameter)) {
                continue;
            }

            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    /**
     * Get DTO properties by class name or FQN (requires GeneratedCode).
     *
     * @return array<string, Parameter>
     */
    public function getDtoProperties(
        string $classNameOrFqn,
        ?callable $shouldSkip = null
    ): array {
        $dto = $this->findDto($classNameOrFqn);
        if (! $dto) {
            return [];
        }

        return $this->extractDtoProperties($dto, $shouldSkip);
    }

    /**
     * Resolve endpoint response DTO class name (without namespace).
     * Returns just the class name (e.g., "UserDto") for use by RequestGenerator.
     */
    public function resolveResponseDtoClassName(Endpoint $endpoint): ?string
    {
        // Try response schema reference
        if (isset($endpoint->response['schema'])) {
            $schema = $endpoint->response['schema'];

            if (is_string($schema)) {
                return $this->schemaNameToDtoClassName($schema);
            }

            if (is_array($schema) && isset($schema['$ref'])) {
                $schemaName = $this->extractSchemaNameFromRef($schema['$ref']);

                return $this->schemaNameToDtoClassName($schemaName);
            }
        }

        return null;
    }

    /**
     * Resolve endpoint response DTO from various schema formats.
     * Returns FQN without leading backslash (e.g., "Company\Integration\Dto\UserDto")
     */
    public function resolveResponseDto(Endpoint $endpoint): ?string
    {
        $className = $this->resolveResponseDtoClassName($endpoint);

        if (! $className) {
            return null;
        }

        return $this->buildDtoFqn($className);
    }

    /**
     * Resolve endpoint request body DTO class name (without namespace).
     * Returns just the class name for building FQNs.
     */
    public function resolveRequestBodyDtoClassName(Endpoint $endpoint): ?string
    {
        if (! isset($endpoint->requestBodySchema)) {
            return null;
        }

        $schema = $endpoint->requestBodySchema;

        if (is_string($schema)) {
            return $this->schemaNameToDtoClassName($schema);
        }

        if (is_array($schema) && isset($schema['$ref'])) {
            $schemaName = $this->extractSchemaNameFromRef($schema['$ref']);

            return $this->schemaNameToDtoClassName($schemaName);
        }

        return null;
    }

    /**
     * Resolve endpoint request body DTO from schema.
     * Returns FQN without leading backslash (e.g., "Company\Integration\Dto\UserDto")
     */
    public function resolveRequestBodyDtoFqn(Endpoint $endpoint): ?string
    {
        $className = $this->resolveRequestBodyDtoClassName($endpoint);

        if (! $className) {
            return null;
        }

        return $this->buildDtoFqn($className);
    }

    /**
     * Resolve endpoint request body DTO from schema.
     * Returns FQN with leading backslash for use in type hints.
     */
    public function resolveRequestBodyDto(Endpoint $endpoint): ?string
    {
        $fqn = $this->resolveRequestBodyDtoFqn($endpoint);

        if (! $fqn) {
            return null;
        }

        return '\\'.$fqn;
    }

    /**
     * Extract schema name from OpenAPI $ref string.
     * Example: "#/components/schemas/User" -> "User"
     */
    protected function extractSchemaNameFromRef(string $ref): string
    {
        return basename(str_replace('#/components/schemas/', '', $ref));
    }
}
