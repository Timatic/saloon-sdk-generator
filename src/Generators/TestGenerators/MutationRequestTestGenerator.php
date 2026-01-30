<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Generators\Traits\DtoAssertions;

class MutationRequestTestGenerator
{
    use DtoAssertions;

    protected ApiSpecification $specification;

    protected GeneratedCode $generatedCode;

    protected string $namespace;

    public function __construct(ApiSpecification $specification, GeneratedCode $generatedCode, string $namespace)
    {
        $this->specification = $specification;
        $this->generatedCode = $generatedCode;
        $this->namespace = $namespace;
    }

    /**
     * Check if this generator applies to the endpoint
     * POST, PUT, PATCH requests are mutations
     */
    public function isApplicable(Endpoint $endpoint): bool
    {
        return $endpoint->method->isPost()
            || $endpoint->method->isPut()
            || $endpoint->method->isPatch();
    }

    /**
     * Get the stub path for mutation request tests
     */
    public function getStubPath(): string
    {
        return __DIR__.'/../../Stubs/TestGenerators/mutation-request-test-func.stub';
    }

    /**
     * Replace stub variables with mutation-specific content
     */
    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        // Get DTO class name and generate instantiation code
        $dtoClassName = $this->getRequestBodyDtoClassName($endpoint);

        if ($dtoClassName) {
            // Generate DTO instantiation with named parameters
            $mockBodyData = $this->generateMockBodyData($endpoint);
            $dtoInstantiation = $this->generateDtoInstantiation($dtoClassName, $mockBodyData);

            $functionStub = str_replace(
                '{{ mockBodyData }}',
                $dtoInstantiation,
                $functionStub
            );

            // Add DTO class name for import
            $parts = explode('\\', $dtoClassName);
            $shortClassName = end($parts);
            $functionStub = str_replace(
                '{{ dtoClassName }}',
                $shortClassName,
                $functionStub
            );
        } else {
            // Fallback to array if no DTO found
            $mockBodyData = $this->generateMockBodyData($endpoint);
            $mockBodyDataPhp = $this->formatArrayAsPhp($mockBodyData);

            $functionStub = str_replace(
                '{{ mockBodyData }}',
                $mockBodyDataPhp,
                $functionStub
            );

            $functionStub = str_replace(
                '{{ dtoClassName }}',
                '',
                $functionStub
            );
        }

        return $functionStub;
    }

    /**
     * Generate DTO instantiation code with named parameters
     */
    protected function generateDtoInstantiation(string $dtoClassName, array $mockData): string
    {
        $parts = explode('\\', $dtoClassName);
        $shortClassName = end($parts);

        if (empty($mockData)) {
            return "new {$shortClassName}()";
        }

        // Reflect on the DTO to get parameter types
        $parametersByName = [];
        try {
            $reflection = new \ReflectionClass($dtoClassName);
            $constructor = $reflection->getConstructor();

            if ($constructor) {
                foreach ($constructor->getParameters() as $param) {
                    $parametersByName[$param->getName()] = $param;
                }
            }
        } catch (\ReflectionException $e) {
            // If reflection fails, continue without type info
        }

        $parameters = [];
        foreach ($mockData as $key => $value) {
            $param = $parametersByName[$key] ?? null;
            $formattedValue = $this->formatValueForParameter($value, $param);
            $parameters[] = "$key: $formattedValue";
        }

        $paramString = implode(",\n        ", $parameters);

        return "new {$shortClassName}(\n        {$paramString}\n    )";
    }

    /**
     * Format a value for a specific parameter, using type information if available
     */
    protected function formatValueForParameter(mixed $value, ?\ReflectionParameter $param): string
    {
        // Check if parameter is Carbon/DateTime type
        if ($param) {
            $type = $param->getType();

            // Handle both ReflectionNamedType and ReflectionUnionType
            $typeName = null;
            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
            } elseif ($type instanceof \ReflectionUnionType) {
                // For union types, check each type
                foreach ($type->getTypes() as $unionType) {
                    if ($unionType instanceof \ReflectionNamedType) {
                        $name = $unionType->getName();
                        if ($name !== 'null') {
                            $typeName = $name;
                            break;
                        }
                    }
                }
            }

            if ($typeName && (str_contains($typeName, 'Carbon') || str_contains($typeName, 'DateTime'))) {
                if (is_string($value)) {
                    return "\\Carbon\\Carbon::parse('{$value}')";
                }
            }
        }

        // Use existing formatValue logic for other types
        return $this->formatValue($value);
    }

    /**
     * Format a value for PHP code
     */
    protected function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return $this->formatArrayAsPhp($value);
        } elseif (is_object($value)) {
            return $this->formatArrayAsPhp((array) $value);
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            return 'null';
        } elseif (is_string($value)) {
            return "'".addslashes($value)."'";
        } else {
            return (string) $value;
        }
    }

    /**
     * Generate mock body data for mutation request
     */
    protected function generateMockBodyData(Endpoint $endpoint): array
    {
        // Get DTO class name from request body
        $dtoClassName = $this->getRequestBodyDtoClassName($endpoint);

        if (! $dtoClassName) {
            return [];
        }

        // Generate mock data based on DTO constructor parameters
        return $this->generateMockAttributesFromDto($dtoClassName);
    }

    /**
     * Get the DTO class name from request body
     */
    public function getRequestBodyDtoClassName(Endpoint $endpoint): ?string
    {
        if (isset($endpoint->requestBodySchema)) {
            $schemaName = $endpoint->requestBodySchema;

            return $this->namespace.'\\Dto\\'.$schemaName;
        }

        return null;
    }

    /**
     * Format array as PHP code
     */
    protected function formatArrayAsPhp(array $data, int $indent = 0): string
    {
        if (empty($data)) {
            return '[]';
        }

        $spaces = str_repeat('    ', $indent);
        $lines = ['['];

        foreach ($data as $key => $value) {
            $formattedKey = is_string($key) ? "'".addslashes($key)."'" : $key;

            if (is_array($value)) {
                $formattedValue = $this->formatArrayAsPhp($value, $indent + 1);
            } elseif (is_object($value)) {
                // Convert objects to arrays for formatting
                $formattedValue = $this->formatArrayAsPhp((array) $value, $indent + 1);
            } elseif (is_bool($value)) {
                $formattedValue = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $formattedValue = 'null';
            } elseif (is_string($value)) {
                $formattedValue = "'".addslashes($value)."'";
            } else {
                $formattedValue = $value;
            }

            $lines[] = "    $spaces$formattedKey => $formattedValue,";
        }

        $lines[] = "$spaces]";

        return implode("\n", $lines);
    }
}
