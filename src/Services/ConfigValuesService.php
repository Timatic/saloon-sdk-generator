<?php

namespace Crescat\SaloonSdkGenerator\Services;

class ConfigValuesService
{
    /**
     * Read connector name and base URL from an existing config file,
     * with optional overrides from CLI options.
     *
     * @return array{connectorName: string, baseUrl: ?string}|null
     */
    public function resolveFromExisting(
        string $outputDir,
        ?string $connectorName = null,
        ?string $baseUrl = null,
    ): ?array {
        $existing = $this->readExisting($outputDir);

        if ($existing === null) {
            return null;
        }

        if ($connectorName !== null) {
            $existing['connectorName'] = $connectorName;
        }
        if ($baseUrl !== null) {
            $existing['baseUrl'] = $baseUrl;
        }

        return $existing;
    }

    /**
     * @return array{connectorName: string, baseUrl: ?string}|null
     */
    private function readExisting(string $outputDir): ?array
    {
        $configDir = $outputDir.'/config';
        if (! is_dir($configDir)) {
            return null;
        }

        $files = glob($configDir.'/*.php');
        if (empty($files)) {
            return null;
        }

        $configFile = $files[0];
        $configKey = basename($configFile, '.php');

        $content = file_get_contents($configFile);
        preg_match("/env\('[A-Z_]+_BASE_URL',\s*'([^']+)'\)/", $content, $matches);

        return [
            'connectorName' => ucfirst($configKey).'Connector',
            'baseUrl' => $matches[1] ?? null,
        ];
    }
}
