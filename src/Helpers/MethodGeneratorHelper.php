<?php

namespace Crescat\SaloonSdkGenerator\Helpers;

namespace Crescat\SaloonSdkGenerator\Helpers;

use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Dumper;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use SensitiveParameter;

class MethodGeneratorHelper
{
    /**
     * Adds a promoted property to a method based on a given parameter.
     *
     * @param  Method  $method  The method to which the promoted property is added.
     * @param  Parameter  $parameter  The parameter based on which the promoted property is added.
     * @return Method The updated method with the promoted property.
     */
    public static function addParameterAsPromotedProperty(
        Method $method,
        Parameter $parameter,
        mixed $defaultValue = null,
        bool $sensitive = false
    ): Method {
        // TODO: validate that this is a constructor, promported properties are only supported on constructors.

        $name = NameHelper::safeVariableName($parameter->name);

        $docType = self::toAbsoluteDocType($parameter->type);

        $property = $method
            ->addComment(
                trim(sprintf(
                    '@param %s $%s %s',
                    $parameter->nullable ? "null|{$docType}" : $docType,
                    $name,
                    $parameter->description
                ))
            )
            ->addPromotedParameter($name);

        $property
            ->setType($parameter->type)
            ->setNullable($parameter->nullable)
            ->setProtected();

        if ($defaultValue !== null) {
            $property->setDefaultValue($defaultValue);
        } elseif ($parameter->nullable) {
            $property->setDefaultValue(null);
        }

        if ($sensitive) {
            $property->addAttribute(SensitiveParameter::class);
        }

        return $method;
    }

    /**
     * Prefix non-primitive type parts with \ so docblock types are absolute FQNs
     * and static analysers resolve them correctly regardless of current namespace.
     */
    private static function toAbsoluteDocType(string $type): string
    {
        $primitives = ['null', 'bool', 'int', 'float', 'string', 'array', 'object', 'mixed', 'void', 'never', 'static', 'self', 'parent', 'true', 'false'];

        return implode('|', array_map(function (string $part) use ($primitives): string {
            $part = trim($part);
            if (in_array(strtolower($part), $primitives, true) || str_starts_with($part, '\\')) {
                return $part;
            }

            return '\\'.$part;
        }, explode('|', $type)));
    }

    /**
     * Generates a method that returns parameters as an array.
     */
    public static function generateArrayReturnMethod(ClassType $classType, string $name, array $parameters, bool $withArrayFilterWrapper = false): Method
    {
        $paramArray = self::buildParameterArray($parameters);

        $body = $withArrayFilterWrapper
            ? sprintf('return array_filter(%s);', (new Dumper)->dump($paramArray))
            : sprintf('return %s;', (new Dumper)->dump($paramArray));

        return $classType
            ->addMethod($name)
            ->setReturnType('array')
            ->addBody($body);
    }

    /**
     * Builds an array of parameters with their corresponding values.
     */
    protected static function buildParameterArray(array $parameters): array
    {
        return collect($parameters)
            ->mapWithKeys(function (Parameter $parameter) {
                return [
                    $parameter->name => new Literal(
                        sprintf('$this->%s', NameHelper::safeVariableName($parameter->name))
                    ),
                ];
            })
            ->toArray();
    }
}
