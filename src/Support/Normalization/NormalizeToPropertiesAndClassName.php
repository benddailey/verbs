<?php

namespace Thunk\Verbs\Support\Normalization;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

trait NormalizeToPropertiesAndClassName
{
    public static function requiredDataForVerbsDeserialization(): array
    {
        return Collection::make((new ReflectionClass(static::class))->getProperties())
            ->reject(fn (ReflectionProperty $property) => $property->hasDefaultValue() || $property->getType()?->allowsNull())
            ->map(fn (ReflectionProperty $property) => $property->getName())
            ->values()
            ->all();
    }

    public static function deserializeForVerbs(mixed $data): static
    {
        $required = self::requiredDataForVerbsDeserialization();

        if (! Arr::has($data, $required)) {
            throw new InvalidArgumentException(sprintf(
                'The following data is required to deserialize to "%s": %s',
                class_basename(static::class),
                implode(', ', $required)
            ));
        }

        $reflect = new ReflectionClass(data_get($data, 'fqcn', static::class));

        if ($reflect->isAbstract()) {
            throw new InvalidArgumentException('Cannot deserialize into an abstract class.');
        }

        if ($reflect->getName() !== static::class && ! $reflect->isSubclassOf(static::class)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot deserialize "%s" data into a "%s"',
                class_basename($reflect->getName()),
                class_basename(static::class)
            ));
        }

        $instance = $reflect->newInstanceWithoutConstructor();

        foreach (Arr::except($data, ['fqcn']) as $key => $value) {
            $reflect->getProperty($key)->setValue($instance, $value);
        }

        return $instance;
    }

    public function serializeForVerbs(): string|array
    {
        $properties = Collection::make((new ReflectionClass($this))->getProperties())
            ->reject(fn (ReflectionProperty $property) => $property->isStatic())
            ->mapWithKeys(fn (ReflectionProperty $property) => [$property->getName() => $property->getValue($this)]);

        if ($properties->has('fqcn')) {
            throw new RuntimeException('NormalizeToPropertiesAndClass cannot serialize objects with a "fqcn" property.');
        }

        return array_merge(['fqcn' => static::class], $properties->all());
    }
}
