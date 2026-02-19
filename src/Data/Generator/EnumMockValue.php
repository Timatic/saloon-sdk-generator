<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

class EnumMockValue
{
    public function __construct(
        public readonly string $fqn,
        public readonly string $shortName,
        public readonly string $caseName,
        public readonly string $caseValue,
    ) {}
}
