<?php

namespace Crescat\SaloonSdkGenerator\Support;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * @template TModel of Data
 */
abstract class Factory
{
    protected Generator $faker;

    protected int $count = 1;

    protected array $states = [];

    public function __construct()
    {
        $this->faker = FakerFactory::create();
    }

    public static function new(): static
    {
        /** @phpstan-ignore-next-line new.static */
        return new static;
    }

    /**
     * Set the number of models to generate.
     */
    public function count(int $count): static
    {
        $this->count = $count;

        return $this;
    }

    /**
     * Set custom attribute overrides.
     */
    public function state(array $attributes): static
    {
        $this->states = array_merge($this->states, $attributes);

        return $this;
    }

    /**
     * Generate one or more model instances.
     *
     * @return TModel|Collection<int, TModel>
     */
    public function make(): Data|Collection
    {
        if ($this->count === 1) {
            return $this->makeOne();
        }

        return Collection::times($this->count, fn () => $this->makeOne());
    }

    /**
     * Alias for make() for API consistency with Laravel factories.
     *
     * @return TModel|Collection<int, TModel>
     */
    public function create(): Data|Collection
    {
        return $this->make();
    }

    /**
     * Generate a single model instance.
     *
     * @return TModel
     */
    protected function makeOne(): Data
    {
        $modelClass = $this->modelClass();
        /** @var TModel $model */
        $model = new $modelClass;

        $attributes = array_merge($this->definition(), $this->states);

        foreach ($attributes as $key => $value) {
            $model->{$key} = $value;
        }

        return $model;
    }

    /**
     * Define the default attributes for the model.
     */
    abstract protected function definition(): array;

    /**
     * Get the model class name.
     */
    abstract protected function modelClass(): string;
}
