<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Trait;

use InvalidArgumentException;

trait ReturnsStrictTypedData
{
    /**
     * @param string $key
     * @param string|int $index
     * @return array|mixed
     */
    abstract public function getData($key = '', $index = null);

    /**
     * @param string $key
     * @return string
     * @throws InvalidArgumentException
     */
    public function getDataString(string $key): string
    {
        $value = $this->getData($key);

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Data for key %s is not a string', $key));
        }

        return $value;
    }

    /**
     * @param string $key
     * @return string|null
     */
    public function getDataStringOrNull(string $key): ?string
    {
        $value = $this->getData($key);
        return is_string($value) ? $value : null;
    }

    /**
     * @param string $key
     * @param bool $cast
     * @return int
     * @throws InvalidArgumentException
     */
    public function getDataInt(string $key, bool $cast = false): int
    {
        $value = $this->getData($key);
        $coerced = $cast ? $this->coerceInt($value) : (is_int($value) ? $value : null);

        if ($coerced === null) {
            throw new InvalidArgumentException(sprintf('Data for key %s is not an int', $key));
        }

        return $coerced;
    }

    /**
     * @param string $key
     * @param bool $cast See {@see self::getDataInt()} for the cast semantics.
     * @return int|null
     */
    public function getDataIntOrNull(string $key, bool $cast = false): ?int
    {
        $value = $this->getData($key);
        if ($cast) {
            return $this->coerceInt($value);
        }
        return is_int($value) ? $value : null;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function coerceInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) || is_float($value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if ($validated !== false) {
                return $validated;
            }
        }
        return null;
    }

    /**
     * @param string $key
     * @return array<mixed>
     */
    public function getDataArray(string $key): array
    {
        $value = $this->getData($key);

        if (!is_array($value)) {
            return [];
        }

        return $value;
    }

    /**
     * @template T
     * @param string $key
     * @param class-string<T> $type
     * @return array<T>
     * @throws InvalidArgumentException
     */
    public function getDataArrayOfType(string $key, string $type): array
    {
        $value = $this->getDataArray($key);

        foreach ($value as $item) {
            if (!($item instanceof $type)) {
                // @phpstan-ignore argument.type
                throw new InvalidArgumentException(sprintf('Item %s is not a %s', $item, $type));
            }
        }

        return $value;
    }

    /**
     * @template T
     * @param string $key
     * @param class-string<T> $type
     * @return array<T>|null
     * @throws InvalidArgumentException
     */
    public function getDataArrayOfTypeOrNull(string $key, string $type): ?array
    {
        $value = $this->getData($key);

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            if (!($item instanceof $type)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * @template T
     * @param string $key
     * @param class-string<T> $type
     * @return T
     * @throws InvalidArgumentException
     */
    public function getDataOfType(string $key, string $type)
    {
        $value = $this->getData($key);
        if (!($value instanceof $type)) {
            throw new InvalidArgumentException(sprintf('Data for key %s is not a %s', $key, $type));
        }
        return $value;
    }

    /**
     * @template T
     * @param string $key
     * @param class-string<T> $type
     * @return T|null
     */
    public function getDataOfTypeOrNull(string $key, string $type)
    {
        $value = $this->getData($key);
        if (!($value instanceof $type)) {
            return null;
        }
        return $value;
    }
}
