<?php

namespace WooNinja\KajabiSaloon\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Per-process PSR-16 cache, the default until the host injects a shared one
 * with KajabiService::setCache(). Parameters are untyped so this satisfies
 * psr/simple-cache 1, 2 and 3.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{0: mixed, 1: ?int}> value, expiry (unix time) */
    private array $items = [];

    public function get($key, $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->items[$key][0];
    }

    public function set($key, $value, $ttl = null): bool
    {
        $seconds = $ttl instanceof \DateInterval
            ? (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time()
            : $ttl;

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $this->items[$key] = [$value, $seconds === null ? null : time() + $seconds];

        return true;
    }

    public function delete($key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key): bool
    {
        if (!array_key_exists($key, $this->items)) {
            return false;
        }

        $expiresAt = $this->items[$key][1];

        if ($expiresAt !== null && $expiresAt <= time()) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }
}
