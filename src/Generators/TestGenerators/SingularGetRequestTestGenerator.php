<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Generators\Traits\DtoAssertions;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;

class SingularGetRequestTestGenerator
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
     * GET requests WITH path parameters are singular GET requests
     */
    public function isApplicable(Endpoint $endpoint): bool
    {
        return $endpoint->method->isGet() && ! empty($endpoint->pathParameters);
    }

    /**
     * Get the stub path for singular GET request tests
     */
    public function getStubPath(Endpoint $endpoint): string
    {
        // Check if DTO exists for this endpoint
        $dtoClassName = $this->getDtoClassName($endpoint);
        $hasDtoResponse = $dtoClassName && $this->dtoResolver->dtoExists($dtoClassName);

        if ($hasDtoResponse) {
            return __DIR__.'/../../Stubs/TestGenerators/singular-get-request-test-func.stub';
        }

        return __DIR__.'/../../Stubs/TestGenerators/singular-get-request-no-dto-test-func.stub';
    }

    /**
     * Replace stub variables with singular GET-specific content
     */
    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        // Early return if no DTO exists (no-dto stub doesn't need replacements)
        $dtoClassName = $this->getDtoClassName($endpoint);
        if (! $dtoClassName || ! $this->dtoResolver->dtoExists($dtoClassName)) {
            return $functionStub;
        }

        // Generate mock response body (plain JSON object)
        $mockData = $this->generateMockData($endpoint);
        $mockResponseBody = $this->formatArrayAsPhp($mockData);

        $functionStub = str_replace(
            '{{ mockResponseBody }}',
            $mockResponseBody,
            $functionStub
        );

        // Generate DTO assertions based on mock data
        $dtoAssertions = $this->generateDtoAssertions($mockData);

        $functionStub = str_replace('{{ dtoAssertions }}', $dtoAssertions, $functionStub);

        return $functionStub;
    }

    /**
     * Generate mock data for singular GET response (plain JSON object)
     */
    protected function generateMockData(Endpoint $endpoint): array
    {
        // Get DTO class name from endpoint response
        $dtoClassName = $this->getDtoClassName($endpoint);

        if (! $dtoClassName) {
            return [];
        }

        // Generate mock data based on DTO constructor parameters
        return $this->generateMockAttributesFromDto($dtoClassName);
    }

    /**
     * Get the DTO class name from endpoint response
     * Returns null if no DTO can be resolved
     */
    protected function getDtoClassName(Endpoint $endpoint): ?string
    {
        // Use DtoResolver to get the response DTO
        $dtoFqn = $this->dtoResolver->resolveResponseDto($endpoint);

        if ($dtoFqn) {
            return $dtoFqn;
        }

        // No DTO found
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
