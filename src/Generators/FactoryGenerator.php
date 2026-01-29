<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Support\Factory;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PromotedParameter;

class FactoryGenerator implements PostProcessor
{
    protected array $generated = [];

    protected Config $config;

    protected ApiSpecification $specification;

    public function process(Config $config, ApiSpecification $specification, GeneratedCode $generatedCode): PhpFile|array|null
    {
        $this->config = $config;
        $this->specification = $specification;

        foreach ($generatedCode->dtoClasses as $dtoClassName => $dtoClass) {
            $this->generateFactoryClass($dtoClassName, $dtoClass);
        }

        return $this->generated;
    }

    protected function generateFactoryClass(string $dtoClassName, PhpFile $dtoClass): PhpFile
    {
        $factoryName = $dtoClassName.'Factory';

        $classType = new ClassType($factoryName);
        $classFile = new PhpFile;
        $namespace = $classFile->addNamespace("{$this->config->namespace}\\{$this->config->factoryNamespaceSuffix}");

        $classType->setExtends(Factory::class);

        $namespace->addUse(Factory::class);
        $dtoFullClass = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClassName}";
        $namespace->addUse($dtoFullClass);

        $properties = $this->getDtoPropertiesFromPhpFile($dtoClass);

        $definitionMethod = $classType->addMethod('definition')
            ->setReturnType('array')
            ->setProtected();

        $definitionBody = $this->generateDefinitionBody($dtoClassName, $properties, $namespace);
        $definitionMethod->setBody($definitionBody);

        $namespace->add($classType);

        $this->generated[$factoryName] = new TaggedOutputFile(
            tag: 'factories',
            file: (string) $classFile,
            path: "{$this->config->factoryNamespaceSuffix}/{$factoryName}.php",
        );

        return $classFile;
    }

    /**
     * Get properties to skip when generating factory definitions.
     *
     * @return array<int, string>
     */
    protected function getPropertiesToSkip(): array
    {
        return [];
    }

    /**
     * Get DTO properties from PhpFile (handles both promoted params and public properties).
     *
     * @return array<array{name: string, type: ?string, isDateTime: bool}>
     */
    protected function getDtoPropertiesFromPhpFile(PhpFile $dtoClass): array
    {
        $properties = [];
        $propertiesToSkip = $this->getPropertiesToSkip();

        foreach ($dtoClass->getNamespaces() as $ns) {
            foreach ($ns->getClasses() as $class) {
                // First, collect properties from promoted constructor parameters (Spatie Data style)
                foreach ($class->getMethods() as $method) {
                    if ($method->getName() === '__construct') {
                        foreach ($method->getParameters() as $param) {
                            if (! $param instanceof PromotedParameter) {
                                continue;
                            }

                            $propName = $param->getName();

                            if (in_array($propName, $propertiesToSkip, true)) {
                                continue;
                            }

                            $typeName = $param->getType();
                            $typeName = is_string($typeName) ? $typeName : null;

                            $isDateTime = $this->isDateTimeType($typeName);

                            $properties[] = [
                                'name' => $propName,
                                'type' => $typeName,
                                'isDateTime' => $isDateTime,
                            ];
                        }
                    }
                }

                // Then, collect from public properties (Model-based style)
                foreach ($class->getProperties() as $property) {
                    $propName = $property->getName();

                    if (in_array($propName, $propertiesToSkip, true)) {
                        continue;
                    }

                    if ($property->isStatic() || $property->isPrivate()) {
                        continue;
                    }

                    // Skip if already added from promoted params
                    $alreadyAdded = collect($properties)->contains('name', $propName);
                    if ($alreadyAdded) {
                        continue;
                    }

                    // Skip relationship properties (they have Relationship attribute)
                    $hasRelationshipAttribute = false;
                    foreach ($property->getAttributes() as $attribute) {
                        $attrName = $attribute->getName();
                        if ($attrName === 'Relationship' || str_ends_with($attrName, '\\Relationship')) {
                            $hasRelationshipAttribute = true;
                            break;
                        }
                    }

                    if ($hasRelationshipAttribute) {
                        continue;
                    }

                    // Check if property has DateTime attribute
                    $isDateTime = false;
                    foreach ($property->getAttributes() as $attribute) {
                        $attrName = $attribute->getName();
                        if ($attrName === 'DateTime' || str_ends_with($attrName, '\\DateTime')) {
                            $isDateTime = true;
                            break;
                        }
                    }

                    $typeName = $property->getType();
                    $typeName = is_string($typeName) ? $typeName : null;

                    if (! $isDateTime) {
                        $isDateTime = $this->isDateTimeType($typeName);
                    }

                    $properties[] = [
                        'name' => $propName,
                        'type' => $typeName,
                        'isDateTime' => $isDateTime,
                    ];
                }

                break 2;
            }
        }

        return $properties;
    }

    /**
     * Check if a type string represents a DateTime/Carbon type.
     */
    protected function isDateTimeType(?string $typeName): bool
    {
        if (! $typeName) {
            return false;
        }

        $normalized = ltrim($typeName, '?\\');

        return str_contains($normalized, 'Carbon\\Carbon')
            || str_ends_with($normalized, 'Carbon')
            || str_contains($normalized, 'DateTime');
    }

    /**
     * Generate the body of the definition() method.
     */
    protected function generateDefinitionBody(string $dtoClassName, array $properties, PhpNamespace $namespace): string
    {
        $lines = ['return ['];

        foreach ($properties as $property) {
            $propertyName = $property['name'];

            $referencedDtoClass = $this->getReferencedDtoClass($property['type']);

            $fakerCall = $this->generateFakerCall(
                $propertyName,
                $property['type'],
                $property['isDateTime'],
                $referencedDtoClass
            );

            $lines[] = "    '{$propertyName}' => {$fakerCall},";
        }

        $lines[] = '];';

        $hasDateTime = collect($properties)->contains('isDateTime', true);
        if ($hasDateTime) {
            $namespace->addUse('Carbon\\Carbon');
        }

        return implode("\n", $lines);
    }

    /**
     * Generate appropriate Faker call for a property.
     */
    protected function generateFakerCall(string $propertyName, ?string $propertyType, bool $isDateTime, ?string $referencedDtoClass = null): string
    {
        $lowerName = strtolower($propertyName);

        if ($isDateTime || $propertyType === 'Carbon\\Carbon') {
            return 'Carbon::now()->subDays($this->faker->numberBetween(0, 365))';
        }

        if ($referencedDtoClass !== null) {
            return "{$referencedDtoClass}Factory::new()->make()";
        }

        if ($propertyType) {
            $baseType = ltrim($propertyType, '?\\');

            if ($baseType === 'bool' || $baseType === 'boolean') {
                return '$this->faker->boolean()';
            }

            if ($baseType === 'int' || $baseType === 'integer') {
                if (str_ends_with($propertyName, 'Id') || str_ends_with($lowerName, '_id')) {
                    return '$this->faker->numberBetween(1, 1000)';
                }

                if (str_contains($lowerName, 'minute')) {
                    return '$this->faker->numberBetween(15, 480)';
                }

                return '$this->faker->numberBetween(1, 100)';
            }

            if ($baseType === 'float' || $baseType === 'double') {
                return '$this->faker->randomFloat(2, 0, 1000)';
            }

            if ($baseType === 'object') {
                return '(object) []';
            }

            if ($baseType === 'array') {
                return '[]';
            }
        }

        if (str_contains($lowerName, 'email')) {
            return '$this->faker->safeEmail()';
        }

        if ((str_ends_with($propertyName, 'Id') || str_ends_with($lowerName, '_id')) && (! $propertyType || str_contains($propertyType, 'string'))) {
            return '$this->faker->uuid()';
        }

        if (($lowerName === 'hourlyrate' || $lowerName === 'hourly_rate' || str_contains($lowerName, 'rate')) && (! $propertyType || str_contains($propertyType, 'string'))) {
            return "number_format(\$this->faker->randomFloat(2, 50, 150), 2, '.', '')";
        }

        if (str_contains($lowerName, 'description')) {
            return '$this->faker->sentence()';
        }

        if (str_contains($lowerName, 'title')) {
            return '$this->faker->sentence()';
        }

        if (str_ends_with($propertyName, 'Name') && ! str_starts_with($lowerName, 'user')) {
            return '$this->faker->company()';
        }

        if (str_contains($lowerName, 'name')) {
            return '$this->faker->name()';
        }

        if (str_contains($lowerName, 'number')) {
            return '$this->faker->word()';
        }

        if ($propertyType == 'string') {
            return '$this->faker->word()';
        }

        return 'null';
    }

    /**
     * Check if a property type references another DTO in the schema.
     */
    protected function getReferencedDtoClass(?string $propertyType): ?string
    {
        if (! $propertyType) {
            return null;
        }

        $className = Str::afterLast($propertyType, '\\');

        if (array_key_exists($className, $this->specification->components->schemas ?? [])) {
            return ucfirst($className);
        }

        return null;
    }
}
