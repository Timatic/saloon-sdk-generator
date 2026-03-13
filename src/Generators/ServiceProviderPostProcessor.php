<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;

class ServiceProviderPostProcessor implements PostProcessor
{
    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        $configKey = $config->resolvedConfigKey();
        $appName = preg_replace('/Connector$/', '', $config->connectorName);

        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($config->namespace.'\\Providers');
        $namespace->addUse('Illuminate\\Support\\ServiceProvider');
        $namespace->addUse($config->namespace.'\\'.$config->connectorName);

        $class = $namespace->addClass($appName.'ServiceProvider');
        $class->setExtends('Illuminate\\Support\\ServiceProvider');

        $this->addRegisterMethod($class, $configKey, $appName);
        $this->addBootMethod($class, $configKey);

        return [
            new TaggedOutputFile(
                tag: 'foundation',
                file: $file,
                path: "src/Providers/{$appName}ServiceProvider.php",
            ),
        ];
    }

    private function addRegisterMethod(ClassType $class, string $configKey, string $appName): void
    {
        $method = $class->addMethod('register')
            ->setPublic()
            ->setReturnType('void');

        $method->setBody(<<<PHP
\$this->mergeConfigFrom(
    __DIR__.'/../../config/{$configKey}.php',
    '{$configKey}'
);

\$this->app->singleton({$appName}Connector::class, fn () => new {$appName}Connector(
    config('{$configKey}.api_token'),
));

\$this->app->alias({$appName}Connector::class, '{$configKey}');
PHP);
    }

    private function addBootMethod(ClassType $class, string $configKey): void
    {
        $method = $class->addMethod('boot')
            ->setPublic()
            ->setReturnType('void');

        $method->setBody(<<<PHP
\$this->publishes([
    __DIR__.'/../../config/{$configKey}.php' => config_path('{$configKey}.php'),
], '{$configKey}-config');
PHP);
    }
}
