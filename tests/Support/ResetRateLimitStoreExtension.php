<?php

namespace Tests\Support;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Saloon\RateLimitPlugin\Stores\MemoryStore;

/**
 * Saloon's MemoryStore is static, so rate-limit hits from every test would
 * pile up in one bucket and trip the limit part-way through the suite.
 * Start each test with an empty store.
 */
final class ResetRateLimitStoreExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparedSubscriber {
            public function notify(Prepared $event): void
            {
                MemoryStore::clear();
            }
        });
    }
}
