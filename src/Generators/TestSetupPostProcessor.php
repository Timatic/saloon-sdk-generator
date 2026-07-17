<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;

class TestSetupPostProcessor implements PostProcessor
{
    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        return [
            $this->generatePestFile($config),
            $this->generateTestCaseFile($config),
        ];
    }

    private function generatePestFile(Config $config): TaggedOutputFile
    {
        $content = <<<PHP
<?php

use {$config->namespace}\\Tests\\TestCase;

uses(TestCase::class)->in(__DIR__);

PHP;

        return new TaggedOutputFile(
            tag: 'foundation',
            file: $content,
            path: 'tests/Pest.php',
        );
    }

    private function generateTestCaseFile(Config $config): TaggedOutputFile
    {
        $configKey = $config->resolvedConfigKey();
        $appName = preg_replace('/Connector$/', '', $config->connectorName);
        $envVarPrefix = strtoupper(str_replace(['-', '.'], '_', $configKey));

        $baseUrlEnvCall = $config->baseUrl !== null
            ? "env('{$envVarPrefix}_BASE_URL', '{$config->baseUrl}')"
            : "env('{$envVarPrefix}_BASE_URL')";

        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($config->namespace.'\\Tests');
        $namespace->addUse('Dotenv\\Dotenv');
        $namespace->addUse('Orchestra\\Testbench\\TestCase', 'Orchestra');
        $namespace->addUse('Saloon\\Laravel\\SaloonServiceProvider');
        $namespace->addUse('Spatie\\LaravelData\\LaravelDataServiceProvider');
        $namespace->addUse($config->namespace.'\\Providers\\'.$appName.'ServiceProvider');

        $class = $namespace->addClass('TestCase');
        $class->setExtends('Orchestra\\Testbench\\TestCase');

        $this->addGetPackageProvidersMethod($class, $appName);
        $this->addGetEnvironmentSetUpMethod($class, $configKey, $envVarPrefix, $baseUrlEnvCall);

        return new TaggedOutputFile(
            tag: 'foundation',
            file: $file,
            path: 'tests/TestCase.php',
        );
    }

    private function addGetPackageProvidersMethod(ClassType $class, string $appName): void
    {
        $method = $class->addMethod('getPackageProviders')
            ->setProtected()
            ->setReturnType('array');

        $method->addParameter('app');

        $method->setBody(<<<PHP
return [
    SaloonServiceProvider::class,
    LaravelDataServiceProvider::class,
    {$appName}ServiceProvider::class,
];
PHP);
    }

    private function addGetEnvironmentSetUpMethod(
        ClassType $class,
        string $configKey,
        string $envVarPrefix,
        string $baseUrlEnvCall,
    ): void {
        $method = $class->addMethod('getEnvironmentSetUp')
            ->setProtected()
            ->setReturnType('void');

        $method->addParameter('app');

        $method->setBody(<<<PHP
if (file_exists(dirname(__DIR__).'/.env')) {
    (Dotenv::createImmutable(dirname(__DIR__), '.env'))->load();
}

\$app['config']->set('{$configKey}.base_url', {$baseUrlEnvCall});
\$app['config']->set('{$configKey}.api_token', env('{$envVarPrefix}_API_TOKEN'));
PHP);
    }
}
