<?php

declare(strict_types=1);

namespace SConcur\Entities;

use SConcur\Contracts\ContextCheckerInterface;
use SConcur\Exceptions\ContextCheckerException;
use Throwable;

class Context
{
    /**
     * @var array<string, mixed>
     */
    protected array $values = [];

    /**
     * @var array<class-string<ContextCheckerInterface>, ContextCheckerInterface>
     */
    protected array $checkers = [];

    public function addValue(string $key, mixed $value): static
    {
        $this->values[$key] = $value;

        return $this;
    }

    public function getValue(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            return null;
        }

        $value = $this->values[$key];

        if (is_null($value)) {
            return null;
        }

        if (is_callable($value)) {
            return $value();
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        $context = [];

        foreach ($this->values as $key => $serializedValue) {
            $context[$key] = $this->getValue($key);
        }

        return $context;
    }

    public function hasValue(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function deleteValue(string $key): void
    {
        unset($this->values[$key]);
    }

    public function clearValues(): void
    {
        $this->values = [];
    }

    public function setChecker(ContextCheckerInterface $checker): static
    {
        $this->checkers[$checker::class] = $checker;

        return $this;
    }

    /**
     * @param class-string<ContextCheckerInterface> $checkerClass
     */
    public function hasChecker(string $checkerClass): bool
    {
        return array_key_exists($checkerClass, $this->checkers);
    }

    /**
     * @throws ContextCheckerException
     */
    public function check(): void
    {
        foreach ($this->checkers as $checker) {
            try {
                $checker->check();
            } catch (Throwable $exception) {
                throw new ContextCheckerException(
                    checker: $checker,
                    exception: $exception
                );
            }
        }
    }

    public function clearCheckers(): void
    {
        $this->checkers = [];
    }

    public function clear(): void
    {
        $this->clearValues();
        $this->clearCheckers();
    }
}
