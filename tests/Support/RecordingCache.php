<?php

namespace Tests\Support;

use WooNinja\KajabiSaloon\Cache\ArrayCache;

/**
 * ArrayCache that records the TTL each key was stored with
 */
final class RecordingCache implements \Psr\SimpleCache\CacheInterface
{
    public array $ttls = [];
    private ArrayCache $inner;

    public function __construct()
    {
        $this->inner = new ArrayCache();
    }

    public function get($key, $default = null): mixed { return $this->inner->get($key, $default); }
    public function set($key, $value, $ttl = null): bool { $this->ttls[$key] = $ttl; return $this->inner->set($key, $value, $ttl); }
    public function delete($key): bool { return $this->inner->delete($key); }
    public function clear(): bool { return $this->inner->clear(); }
    public function getMultiple($keys, $default = null): iterable { return $this->inner->getMultiple($keys, $default); }
    public function setMultiple($values, $ttl = null): bool { return $this->inner->setMultiple($values, $ttl); }
    public function deleteMultiple($keys): bool { return $this->inner->deleteMultiple($keys); }
    public function has($key): bool { return $this->inner->has($key); }
}
