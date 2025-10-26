<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use ReflectionFunction;
use RuntimeException;
use SConcur\Entities\Context;
use SConcur\Contracts\ParametersResolverInterface;

class TestParametersResolver implements ParametersResolverInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws NotFoundExceptionInterface
     */
    public function make(Context $context, Closure $callback): array
    {
        $reflection = new ReflectionFunction($callback);

        $reflectionParameters = $reflection->getParameters();

        if (!count($reflectionParameters)) {
            return [];
        }

        $callbackParameters = [];

        foreach ($reflectionParameters as $reflectionParameter) {
            $name = $reflectionParameter->getName();

            /** @phpstan-ignore method.notFound */
            $type = $reflectionParameter->getType()?->getName();

            if (is_null($type)) {
                throw new RuntimeException(
                    message: "Callback parameter [$name] type is not provided.",
                );
            }

            if ($type === Context::class) {
                $callbackParameters[$name] = $context;

                continue;
            }

            $callbackParameters[$name] = $this->container->get($type);
        }

        return $callbackParameters;
    }
}
