<?php

namespace Crescat\SaloonSdkGenerator;

use Crescat\SaloonSdkGenerator\Contracts\Generator as GeneratorContract;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;

abstract class Generator implements GeneratorContract
{
    protected ?DtoResolver $dtoResolver = null;

    public function __construct(protected ?Config $config = null)
    {
        if ($config) {
            $this->dtoResolver = new DtoResolver($config);
        }
    }

    public function setConfig(?Config $config): static
    {
        $this->config = $config;

        if ($config) {
            $this->dtoResolver = new DtoResolver($config);
        }

        return $this;
    }
}
