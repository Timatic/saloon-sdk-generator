<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

use Illuminate\Support\Str;

class Config
{
    public function __construct(
        public readonly ?string $connectorName,
        public readonly ?string $namespace,
        public readonly ?string $resourceNamespaceSuffix = 'Resource',
        public readonly ?string $requestNamespaceSuffix = 'Requests',
        public readonly ?string $dtoNamespaceSuffix = 'Dto',
        public readonly ?string $factoryNamespaceSuffix = 'Factories',
        public readonly ?string $enumNamespaceSuffix = 'Enums',
        public readonly ?string $fallbackResourceName = 'Misc',
        public readonly bool $suffixRequestClasses = false,
        public readonly bool $generateEnums = true,
        public readonly array $ignoredQueryParams = [],
        public readonly array $ignoredBodyParams = [],
        public readonly array $ignoredHeaderParams = ['Authorization', 'Content-Type', 'Accept', 'Accept-Language', 'User-Agent'],
        public readonly array $extra = [],
        public readonly ?string $configKey = null,
        public readonly ?string $baseUrl = null,
    ) {}

    public function resolvedConfigKey(): string
    {
        return $this->configKey ?? self::deriveConfigKey($this->connectorName);
    }

    public static function deriveConfigKey(string $connectorName): string
    {
        return Str::lower(preg_replace('/Connector$/', '', $connectorName));
    }
}
