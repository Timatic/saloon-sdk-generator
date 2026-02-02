<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;

class DeleteRequestTestGenerator
{
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
     */
    public function isApplicable(Endpoint $endpoint): bool
    {
        return $endpoint->method->isDelete();
    }

    /**
     * Get the stub path for DELETE request tests
     */
    public function getStubPath(Endpoint $endpoint): string
    {
        return __DIR__.'/../../Stubs/TestGenerators/delete-request-test-func.stub';
    }

    /**
     * Replace stub variables (DELETE requests don't need custom replacements)
     */
    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        // DELETE requests use the standard stub without custom replacements
        return $functionStub;
    }
}
