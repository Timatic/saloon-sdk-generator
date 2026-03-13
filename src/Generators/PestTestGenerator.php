<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Generators\TestGenerators\CollectionRequestTestGenerator;
use Crescat\SaloonSdkGenerator\Generators\TestGenerators\DeleteRequestTestGenerator;
use Crescat\SaloonSdkGenerator\Generators\TestGenerators\MutationRequestTestGenerator;
use Crescat\SaloonSdkGenerator\Generators\TestGenerators\SingularGetRequestTestGenerator;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpFile;

class PestTestGenerator implements PostProcessor
{
    public function __construct(
        protected bool $generateTestSetup = true,
    ) {}

    protected Config $config;

    protected ApiSpecification $specification;

    protected GeneratedCode $generatedCode;

    protected DtoResolver $dtoResolver;

    protected CollectionRequestTestGenerator $collectionTestGenerator;

    protected SingularGetRequestTestGenerator $singularGetTestGenerator;

    protected MutationRequestTestGenerator $mutationTestGenerator;

    protected DeleteRequestTestGenerator $deleteTestGenerator;

    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        $this->config = $config;
        $this->specification = $specification;
        $this->generatedCode = $generatedCode;

        $this->dtoResolver = $this->createDtoResolver($config, $generatedCode);
        $this->initializeTestGenerators($specification, $generatedCode, $config->namespace, $this->dtoResolver);

        return $this->generatePestTests();
    }

    protected function createDtoResolver(Config $config, GeneratedCode $generatedCode): DtoResolver
    {
        $resolver = new DtoResolver($config);
        $resolver->setGeneratedCode($generatedCode);

        return $resolver;
    }

    protected function initializeTestGenerators(
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
        string $namespace,
        DtoResolver $dtoResolver,
    ): void {
        $this->collectionTestGenerator = new CollectionRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
        $this->singularGetTestGenerator = new SingularGetRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
        $this->mutationTestGenerator = new MutationRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
        $this->deleteTestGenerator = new DeleteRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
    }

    /** @return TaggedOutputFile[] */
    protected function generatePestTests(): array
    {
        $classes = [];

        if ($this->shouldGeneratePestFile()) {
            $classes[] = $this->generateMainPestFile();
        }

        if ($this->shouldGenerateTestCaseFile()) {
            $classes[] = $this->generateTestCaseFile();
        }

        $groupedByCollection = collect($this->specification->endpoints)
            ->filter(fn (Endpoint $endpoint) => $this->shouldIncludeEndpoint($endpoint))
            ->groupBy(function (Endpoint $endpoint) {
                return NameHelper::resourceClassName(
                    $endpoint->collection ?: $this->config->fallbackResourceName
                );
            });

        foreach ($groupedByCollection as $collection => $items) {
            $classes[] = $this->generateTest($collection, $items->toArray());
        }

        return $classes;
    }

    protected function generateMainPestFile(): TaggedOutputFile
    {
        $stub = file_get_contents(__DIR__.'/../Stubs/pest.stub');
        $stub = str_replace('{{ namespace }}', $this->config->namespace, $stub);
        $stub = str_replace('{{ name }}', $this->config->connectorName, $stub);

        return new TaggedOutputFile(
            tag: 'pest',
            file: $stub,
            path: 'tests/Pest.php',
        );
    }

    protected function generateTestCaseFile(): TaggedOutputFile
    {
        $stub = file_get_contents(__DIR__.'/../Stubs/pest-testcase.stub');
        $stub = str_replace('{{ namespace }}', $this->config->namespace, $stub);
        $stub = str_replace('{{ serviceProviderName }}', str_replace('Connector', '', $this->config->connectorName), $stub);

        return new TaggedOutputFile(
            tag: 'pest',
            file: $stub,
            path: 'tests/TestCase.php',
        );
    }

    /** @param Endpoint[] $endpoints */
    public function generateTest(string $resourceName, array $endpoints): TaggedOutputFile
    {
        $fileStub = file_get_contents($this->getTestStubPath());

        $fileStub = str_replace('{{ prelude }}', '', $fileStub);
        $fileStub = str_replace('{{ connectorName }}', $this->config->connectorName, $fileStub);
        $fileStub = str_replace('{{ namespace }}', $this->config->namespace, $fileStub);
        $fileStub = str_replace('{{ name }}', $this->config->connectorName, $fileStub);
        $fileStub = str_replace('{{ clientName }}', NameHelper::safeVariableName($this->config->connectorName), $fileStub);

        $namespace = Arr::first($this->generatedCode->connectorClass->getNamespaces());
        $classType = Arr::first($namespace->getClasses());

        $constructorParameters = $classType->getMethod('__construct')->getParameters();

        $constructorArgs = [];
        foreach ($constructorParameters as $parameter) {

            // TODO: Configurable?
            if ($parameter->isNullable()) {
                continue;
            }

            $defaultValue = match ($parameter->getType()) {
                'string' => "'replace'",
                'bool' => 'true',
                'int' => 0,
                default => 'null',
            };

            $constructorArgs[] = $parameter->getName().': '.$defaultValue;
        }

        $fileStub = str_replace('{{ connectorArgs }}', Str::wrap(implode(",\n\t\t", $constructorArgs), "\n\t\t", "\n\t"), $fileStub);

        $imports = [];
        foreach ($endpoints as $endpoint) {
            $requestClassName = $this->getRequestClassName($endpoint);
            $imports[] = "use {$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}\\{$requestClassName};";
        }

        $fileStub = str_replace('{{ requestImports }}', implode("\n", $imports), $fileStub);

        $dtoImports = $this->generateDtoImports($endpoints);
        $fileStub = str_replace('{{ dtoImports }}', implode("\n", $dtoImports), $fileStub);

        foreach ($endpoints as $endpoint) {
            $requestClassName = $this->getRequestClassName($endpoint);
            $requestClassNameAlias = $requestClassName == $resourceName ? "{$requestClassName}Request" : null;

            $functionStub = file_get_contents($this->getTestFunctionStubPath($endpoint));

            $functionStub = str_replace('{{ clientName }}', NameHelper::safeVariableName($this->config->connectorName), $functionStub);
            $functionStub = str_replace('{{ requestClass }}', $requestClassNameAlias ?? $requestClassName, $functionStub);
            $functionStub = str_replace('{{ resourceName }}', $resourceNameSafe = NameHelper::safeVariableName($resourceName), $functionStub);
            $functionStub = str_replace('{{ methodName }}', $methodNameSafe = $this->getMethodName($endpoint, $requestClassName), $functionStub);
            $functionStub = str_replace('{{ fixtureName }}', Str::camel($resourceNameSafe.'.'.$methodNameSafe), $functionStub);
            $description = "calls the {$methodNameSafe} method in the {$resourceName} resource";
            $functionStub = str_replace('{{ testDescription }}', $description, $functionStub);

            $methodArguments = [];

            $withoutIgnoredQueryParams = collect($endpoint->queryParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredQueryParams))
                ->values()
                ->toArray();

            $withoutIgnoredHeaderParams = collect($endpoint->headerParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredHeaderParams))
                ->values()
                ->toArray();

            $combined = [
                ...$endpoint->pathParameters,
                ...$endpoint->bodyParameters,
                ...$withoutIgnoredQueryParams,
                ...$withoutIgnoredHeaderParams,
            ];

            foreach ($combined as $param) {
                $paramName = $this->getTestParameterName($param, $endpoint);

                $methodArguments[] = sprintf('%s: %s', $paramName, match ($param->type) {
                    'string' => "'test string'",
                    'int', 'integer' => '123',
                    'float', 'float|int', 'int|float' => '123.45',
                    'bool', 'boolean' => 'true',
                    'array' => '[]',
                    default => 'null',
                });
            }

            $methodArguments = Str::wrap(implode(",\n\t\t", $methodArguments), "\n\t\t", "\n\t");
            $functionStub = str_replace('{{ methodArguments }}', $methodArguments, $functionStub);

            $functionStub = $this->replaceAdditionalStubVariables($functionStub, $endpoint, $resourceName, $requestClassName);

            $fileStub .= "\n\n{$functionStub}";
        }

        return new TaggedOutputFile(
            tag: 'pest',
            file: $fileStub,
            path: $this->getTestPath($resourceName),
        );
    }

    /** @param Endpoint[] $endpoints */
    protected function generateDtoImports(array $endpoints): array
    {
        $dtoTypes = [];
        $enumImports = [];

        foreach ($endpoints as $endpoint) {
            foreach ($endpoint->allParameters() as $parameter) {
                if ($this->isDtoType($parameter->type)) {
                    $dtoTypes[$parameter->type] = true;
                }
            }

            if ($this->mutationTestGenerator->isApplicable($endpoint)) {
                $bodyDtoClass = $this->mutationTestGenerator->getRequestBodyDtoClassName($endpoint);
                if ($bodyDtoClass && $this->isDtoType($bodyDtoClass)) {
                    $dtoTypes[$bodyDtoClass] = true;
                }
            }

            $generator = $this->getTestGeneratorForEndpoint($endpoint);
            if ($generator && method_exists($generator, 'getEnumImports')) {
                foreach ($generator->getEnumImports($endpoint) as $fqn) {
                    $enumImports[$fqn] = true;
                }
            }
        }

        $imports = [];
        foreach (array_keys($dtoTypes) as $dtoType) {
            $imports[] = "use {$dtoType};";
        }
        foreach (array_keys($enumImports) as $fqn) {
            $imports[] = "use {$fqn};";
        }

        sort($imports);

        return $imports;
    }

    protected function isDtoType(string $type): bool
    {
        if (! str_contains($type, '\\')) {
            return false;
        }

        if (! str_starts_with($type, $this->config->namespace)) {
            return false;
        }

        return str_contains($type, "\\{$this->config->dtoNamespaceSuffix}\\");
    }

    protected function shouldGeneratePestFile(): bool
    {
        return $this->generateTestSetup;
    }

    protected function shouldGenerateTestCaseFile(): bool
    {
        return $this->generateTestSetup;
    }

    protected function shouldIncludeEndpoint(Endpoint $endpoint): bool
    {
        return true;
    }

    protected function getTestStubPath(): string
    {
        return __DIR__.'/../Stubs/pest-resource-test.stub';
    }

    protected function getTestFunctionStubPath(Endpoint $endpoint): string
    {
        $generator = $this->getTestGeneratorForEndpoint($endpoint);

        if ($generator) {
            return $generator->getStubPath($endpoint);
        }

        return __DIR__.'/../Stubs/pest-resource-test-func.stub';
    }

    protected function getTestGeneratorForEndpoint(Endpoint $endpoint): CollectionRequestTestGenerator|SingularGetRequestTestGenerator|MutationRequestTestGenerator|DeleteRequestTestGenerator|null
    {
        if ($this->collectionTestGenerator->isApplicable($endpoint)) {
            return $this->collectionTestGenerator;
        }

        if ($this->singularGetTestGenerator->isApplicable($endpoint)) {
            return $this->singularGetTestGenerator;
        }

        if ($this->mutationTestGenerator->isApplicable($endpoint)) {
            return $this->mutationTestGenerator;
        }

        if ($this->deleteTestGenerator->isApplicable($endpoint)) {
            return $this->deleteTestGenerator;
        }

        return null;
    }

    protected function getRequestClassName(Endpoint $endpoint): string
    {
        $className = NameHelper::requestClassName($endpoint->name);

        if ($this->config->suffixRequestClasses && ! str_ends_with($className, 'Request')) {
            $className .= 'Request';
        }

        return $className;
    }

    protected function getMethodName(Endpoint $endpoint, string $requestClassName): string
    {
        return NameHelper::safeVariableName($requestClassName);
    }

    protected function getTestPath(string $resourceName): string
    {
        return "tests/Requests/{$resourceName}Test.php";
    }

    protected function replaceAdditionalStubVariables(
        string $functionStub,
        Endpoint $endpoint,
        string $resourceName,
        string $requestClassName,
    ): string {
        $generator = $this->getTestGeneratorForEndpoint($endpoint);

        if ($generator) {
            $functionStub = $generator->replaceStubVariables($functionStub, $endpoint);
        }

        return $functionStub;
    }

    protected function getTestParameterName(Parameter $parameter, Endpoint $endpoint): string
    {
        return NameHelper::safeVariableName($parameter->name);
    }
}
