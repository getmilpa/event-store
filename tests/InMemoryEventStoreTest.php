<?php

declare(strict_types=1);

namespace Milpa\EventStore\Tests;

use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;

final class InMemoryEventStoreTest extends EventStoreContractTestCase
{
    protected function createStore(): EventStoreInterface
    {
        return new InMemoryEventStore();
    }
}
