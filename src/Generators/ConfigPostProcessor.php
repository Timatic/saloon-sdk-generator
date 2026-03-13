<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Nette\PhpGenerator\PhpFile;

class ConfigPostProcessor implements PostProcessor
{
    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        $configKey = $config->resolvedConfigKey();
        $envPrefix = strtoupper(str_replace(['-', '.'], '_', $configKey));

        $baseUrlValue = $config->baseUrl !== null
            ? "env('{$envPrefix}_BASE_URL', '{$config->baseUrl}')"
            : "env('{$envPrefix}_BASE_URL')";

        $stub = file_get_contents(__DIR__.'/../Stubs/config.php.stub');

        $content = str_replace(
            ['{{ connectorName }}', '{{ envPrefix }}', '{{ baseUrlValue }}'],
            [ucfirst($configKey), $envPrefix, $baseUrlValue],
            $stub
        );

        return [
            new TaggedOutputFile(
                tag: 'foundation',
                file: $content,
                path: "config/{$configKey}.php",
            ),
        ];
    }
}
