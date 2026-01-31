<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Generators\Traits\DtoAssertions;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;

class CollectionRequestTestGenerator
{
    use DtoAssertions;

    protected ApiSpecification $specification;

    protected GeneratedCode $generatedCode;

    protected string $namespace;

    protected DtoResolver $dtoResolver;

    public function __construct(
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
        string $namespace,
        DtoResolver $dtoResolver
    ) {
        $this->specification = $specification;
        $this->generatedCode = $generatedCode;
        $this->namespace = $namespace;
        $this->dtoResolver = $dtoResolver;
    }

    /**
     * Check if this generator applies to the endpoint
     * GET requests WITHOUT path parameters are collection requests
     */
    public function isApplicable(Endpoint $endpoint): bool
    {
        return $endpoint->method->isGet() && empty($endpoint->pathParameters);
    }

    /**
     * Get the stub path for collection request tests
     */
    public function getStubPath(): string
    {
        return __DIR__.'/../../Stubs/TestGenerators/collection-request-test-func.stub';
    }

    /**
     * Replace stub variables with collection-specific content
     */
    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        // Generate mock response body (array with 2 items)
        $mockData = $this->generateMockData($endpoint);
        $mockResponseBody = $this->formatArrayAsPhp($mockData);

        $functionStub = str_replace(
            '{{ mockResponseBody }}',
            $mockResponseBody,
            $functionStub
        );

        // Generate DTO assertions for the first item
        $firstItem = $mockData[0] ?? [];
        $dtoAssertions = $this->generateDtoAssertions($firstItem);

        $functionStub = str_replace('{{ dtoAssertions }}', $dtoAssertions, $functionStub);

        return $functionStub;
    }

    /**
     * Generate mock data for collection response (array of plain JSON objects)
     */
    protected function generateMockData(Endpoint $endpoint): array
    {
        // Get DTO class name from endpoint response
        $dtoClassName = $this->getDtoClassName($endpoint);

        // Generate mock data for a single item
        $singleItem = $this->generateMockAttributesFromDto($dtoClassName);

        // Return array with 2 items
        return [$singleItem, $singleItem];
    }

    /**
     * Get the DTO class name from endpoint response
     */
    protected function getDtoClassName(Endpoint $endpoint): string
    {
        // Use DtoResolver to get the response DTO
        $dtoFqn = $this->dtoResolver->resolveResponseDto($endpoint);

        if ($dtoFqn) {
            // If it's a collection wrapper, try to extract the item type
            $className = $this->dtoResolver->extractClassName($dtoFqn);
            if (str_contains($className, 'Collection') || str_contains($className, 'Pagination')) {
                // Try to get the base name
                $baseName = str_replace(['Collection', 'Pagination', 'DtoOf'], '', $className);
                if ($baseName) {
                    return $this->dtoResolver->buildDtoFqn($baseName);
                }
            }

            return $dtoFqn;
        }

        // Fallback: use collection name
        if ($endpoint->collection) {
            $schemaName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $endpoint->collection)));

            return $this->dtoResolver->buildDtoFqn($schemaName);
        }

        // Final fallback: construct from endpoint name
        $schemaName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $endpoint->name)));

        return $this->dtoResolver->buildDtoFqn($schemaName);
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
