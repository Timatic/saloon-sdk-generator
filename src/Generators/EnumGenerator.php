<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Generator;
use Illuminate\Support\Str;
use Nette\PhpGenerator\EnumType;
use Nette\PhpGenerator\PhpFile;

class EnumGenerator extends Generator
{
    /**
     * Registry of generated enums for deduplication.
     * Structure: ['enum_hash' => ['className' => 'FooEnum', 'values' => [...], 'description' => '...', 'phpFile' => PhpFile]]
     */
    protected array $enumRegistry = [];

    /**
     * Generate enums from registered enum specifications.
     * Returns TaggedOutputFile objects for proper file path handling.
     */
    public function generate(ApiSpecification $specification): PhpFile|array
    {
        // Return the generated enums from registry as TaggedOutputFile
        // The registry is populated by registerEnum() calls from DtoGenerator
        $result = [];

        $suffix = $this->config->enumNamespaceSuffix ?? 'Enums';

        foreach ($this->enumRegistry as $enumInfo) {
            $className = $enumInfo['className'];
            $phpFile = $enumInfo['phpFile'];

            $result[] = new TaggedOutputFile(
                tag: 'enum',
                file: $phpFile,
                path: "src/{$suffix}/{$className}.php"
            );
        }

        return $result;
    }

    /**
     * Register an enum for generation (called by DtoGenerator).
     * Returns enum info or null if already registered.
     */
    public function registerEnum(array $enumValues, string $description, string $dtoClassName, string $propertyName): ?array
    {
        // Create hash of enum values for deduplication
        $enumHash = md5(json_encode($enumValues));

        // If already generated, return existing
        if (isset($this->enumRegistry[$enumHash])) {
            return $this->enumRegistry[$enumHash];
        }

        // Generate new enum class
        $enumClassName = $this->generateEnumClassName($dtoClassName, $propertyName);
        $phpFile = $this->generateEnumClass($enumClassName, $enumValues, $description);

        $enumInfo = [
            'className' => $enumClassName,
            'values' => $enumValues,
            'phpFile' => $phpFile,
        ];

        $this->enumRegistry[$enumHash] = $enumInfo;

        return $enumInfo;
    }

    /**
     * Generate enum class name from DTO and property name.
     */
    protected function generateEnumClassName(string $dtoClassName, string $propertyName): string
    {
        // Remove "Dto" suffix if present
        $baseName = str_replace('Dto', '', $dtoClassName);

        // Convert property name to PascalCase
        $propertyPascal = Str::studly($propertyName);

        return $baseName.$propertyPascal.'Enum';
    }

    /**
     * Generate PHP 8.1+ backed enum class from enum values.
     */
    protected function generateEnumClass(string $enumClassName, array $enumValues, string $description): PhpFile
    {
        $classFile = new PhpFile;
        $suffix = $this->config->enumNamespaceSuffix ?? 'Enums';
        $namespace = $classFile->addNamespace("{$this->config->namespace}\\{$suffix}");

        $enumType = new EnumType($enumClassName);
        $enumType->setType('string');

        // Add class-level description
        if ($description) {
            $cleanDescription = $this->cleanDescription($description);
            $enumType->addComment($cleanDescription);
        }

        // Add enum cases
        foreach ($enumValues as $value) {
            $caseName = $this->generateEnumCaseName($value);
            $enumType->addCase($caseName, $value);
        }

        $namespace->add($enumType);

        return $classFile;
    }

    /**
     * Generate valid PHP enum case name from enum value.
     */
    protected function generateEnumCaseName(string $value): string
    {
        // Convert to SCREAMING_SNAKE_CASE
        // Handle camelCase: "DebitNote" -> "Debit_Note"
        $name = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value);

        // Convert to uppercase
        $name = strtoupper($name);

        // Replace non-alphanumeric with underscore
        $name = preg_replace('/[^A-Z0-9_]/', '_', $name);

        // Remove multiple underscores
        $name = preg_replace('/_+/', '_', $name);

        // Trim underscores
        $name = trim($name, '_');

        // If starts with number, prefix with VALUE_
        if (preg_match('/^[0-9]/', $name)) {
            $name = 'VALUE_'.$name;
        }

        return $name;
    }

    /**
     * Clean OpenAPI description for PHP docblocks.
     */
    protected function cleanDescription(string $description): string
    {
        // Decode HTML entities
        $clean = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove breadcrumb navigation patterns (e.g., "The table > Field >")
        $clean = preg_replace('/^(The\s+\w+\s*>\s*)+/', '', $clean);

        // Wrap long lines for readability (80 chars)
        $wrapped = wordwrap($clean, 77, "\n");

        return $wrapped;
    }

    /**
     * Build fully-qualified namespace for enum class.
     */
    public function buildEnumFqn(string $enumClassName): string
    {
        $suffix = $this->config->enumNamespaceSuffix ?? 'Enums';

        return "{$this->config->namespace}\\{$suffix}\\{$enumClassName}";
    }

    /**
     * Check if an enum with these values already exists (for deduplication).
     */
    public function findExistingEnum(array $enumValues): ?array
    {
        $enumHash = md5(json_encode($enumValues));

        return $this->enumRegistry[$enumHash] ?? null;
    }
}
