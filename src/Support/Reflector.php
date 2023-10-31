<?php

namespace Thunk\Verbs\Support;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Reflector as BaseReflector;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Thunk\Verbs\Attributes\Autodiscovery\DependsOnDiscoveredState;
use Thunk\Verbs\Attributes\Autodiscovery\ReflectsProperty;
use Thunk\Verbs\Attributes\Autodiscovery\StateDiscoveryAttribute;
use Thunk\Verbs\Attributes\Hooks\HookAttribute;
use Thunk\Verbs\Event;
use Thunk\Verbs\Lifecycle\Hook;
use Thunk\Verbs\State;

class Reflector extends BaseReflector
{
    /** @return Collection<int, Hook> */
    public static function getHooks(object $target): Collection
    {
        if ($target instanceof Closure) {
            return collect([Hook::fromClosure($target)]);
        }

        $reflect = new ReflectionClass($target);

        return collect($reflect->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method) => $method->getNumberOfParameters() > 0)
            ->map(fn (ReflectionMethod $method) => Hook::fromClassMethod($target, $method));
    }

    public static function getPublicStateProperties(Event $event)
    {
        $reflect = new ReflectionClass($event);

        return collect($reflect->getProperties(ReflectionMethod::IS_PUBLIC))
            ->filter(function (ReflectionProperty $prop) {
                $type = $prop->getType();

                return $type instanceof ReflectionNamedType
                    && ! $type->isBuiltin()
                    && is_a($type->getName(), State::class, true);
            })
            ->mapWithKeys(fn (ReflectionProperty $prop) => [
                $prop->getName() => $prop->getValue($event),
            ]);
    }

    public static function getNonStatePublicPropertiesAndValues(Event $event)
    {
        $properties = get_object_vars($event);
        $reflect = new ReflectionClass($event);

        return collect($reflect->getProperties(ReflectionMethod::IS_PUBLIC))
            ->filter(function (ReflectionProperty $prop) {
                $type = $prop->getType();

                return $type instanceof ReflectionNamedType &&
                    ! is_a($type->getName(), State::class, true);
            })
            ->mapWithKeys(fn (ReflectionProperty $prop) => [
                $prop->getName() => $properties[$prop->getName()] ?? null,
            ]);
    }

    public static function getEventParameters(ReflectionFunctionAbstract|Closure $method): array
    {
        return static::getParametersOfType(Event::class, $method);
    }

    public static function getStateParameters(ReflectionFunctionAbstract|Closure $method): array
    {
        return static::getParametersOfType(State::class, $method);
    }

    public static function applyAttributes(ReflectionFunctionAbstract|Closure $method, Hook $hook): Hook
    {
        $method = static::reflectFunction($method);

        foreach ($method->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if ($instance instanceof HookAttribute) {
                $instance->applyToHook($hook);
            }
        }

        return $hook;
    }

    public static function getParametersOfType(string $type, ReflectionFunctionAbstract|Closure $method): array
    {
        $method = static::reflectFunction($method);

        if (empty($parameters = $method->getParameters())) {
            return [];
        }

        return array_filter(
            array: static::getParameterClassNames($parameters[0]),
            callback: fn (string $class_name) => is_a($class_name, $type, true)
        );
    }

    protected static function reflectFunction(ReflectionFunctionAbstract|Closure $function): ReflectionFunctionAbstract
    {
        if ($function instanceof Closure) {
            return new ReflectionFunction($function);
        }

        return $function;
    }
}
