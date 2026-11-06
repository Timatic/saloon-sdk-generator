<?php

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Generators\TestSetupPostProcessor;

test('it generates the test and analysis setup files', function () {
    // Given
    $config = new Config(connectorName: 'MyConnector', namespace: 'VendorName');
    $specification = new ApiSpecification(name: 'TestAPI', description: null, baseUrl: null, securityRequirements: [], components: null, endpoints: []);

    // When
    $files = (new TestSetupPostProcessor)->process($config, $specification, new GeneratedCode);

    // Then
    expect(array_map(fn (TaggedOutputFile $file) => $file->path, $files))
        ->toBe(['tests/Pest.php', 'tests/TestCase.php', 'phpunit.xml', 'phpstan.neon']);
});
