<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Event Store

> A tiny **append-only event log** for the Milpa PHP framework, with **zero package dependencies**. Append events, replay a stream, project state from the fold. Two interchangeable stores — **file** (JSONL) and **in-memory** — behind one `EventStoreInterface`. The persistence primitive under Milpa's event-sourced process engine.

[![CI](https://github.com/getmilpa/event-store/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/event-store/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/event-store.svg)](https://packagist.org/packages/milpa/event-store)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/event-store/)

`milpa/event-store` is the smallest possible seam onto an append-only log: an `Event` is an
immutable fact — `streamId`, `type`, `payload`, `seq` — and a store's only two jobs are
"append durably" and "read a stream back in order". **No ORM, no serializer, no framework
coupling** — construct a store with a path (or nothing at all) and call `append()`.

## Install

```bash
composer require milpa/event-store
```

## Quick example

```php
use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;

$store = new FileEventStore('/var/data/orders.jsonl');

// Append: nextSeq() hands out the store-wide monotonic counter, one call per event.
$store->append(new Event('order-42', 'OrderPlaced', ['total' => 19.99], $store->nextSeq()));
$store->append(new Event('order-42', 'OrderShipped', ['carrier' => 'DHL'], $store->nextSeq()));

// Replay: every event for one stream, in ascending seq order — never other streams' events.
foreach ($store->replay('order-42') as $event) {
    printf("#%d %s %s\n", $event->seq, $event->type, json_encode($event->payload));
}
// #1 OrderPlaced {"total":19.99}
// #2 OrderShipped {"carrier":"DHL"}

$store->streams();  // ["order-42"]
$store->nextSeq();  // 3 — one past the highest seq in the store, across every stream
```

Project current state by folding the replayed events yourself — the store never stores
state, only the facts it was told:

```php
$state = array_reduce(
    $store->replay('order-42'),
    static fn (array $state, Event $event): array => match ($event->type) {
        'OrderPlaced' => [...$state, 'status' => 'placed', 'total' => $event->payload['total']],
        'OrderShipped' => [...$state, 'status' => 'shipped', 'carrier' => $event->payload['carrier']],
        default => $state,
    },
    [],
);
// ['status' => 'shipped', 'total' => 19.99, 'carrier' => 'DHL']
```

## Two stores, one interface

| Store | Durability | Use it for |
|-------|-----------|------------|
| `FileEventStore` | Appends one JSON line per event to a flat file, under an exclusive `flock()` so concurrent appenders never interleave partial lines. `nextSeq()` and `replay()` both re-derive their answer from the file itself — a fresh instance pointed at the same path, in a different process or a different request, agrees with every other instance about both "what happened" and "what comes next". | Real persistence — the process engine's durable log. |
| `InMemoryEventStore` | An in-process array. Nothing is written to disk; nothing survives past the instance's lifetime. | Tests, and zero-file consumers that don't need durability. |

Both implement the same four-method `EventStoreInterface` (`append()`, `replay()`,
`nextSeq()`, `streams()`), verified by one shared contract test suite
(`EventStoreContractTestCase`) so behavior — sequencing, stream isolation, replay order —
never drifts between the two.

## Requirements

- PHP **≥ 8.3**
- Nothing else — `milpa/event-store` has no package dependencies, Milpa or otherwise

## Documentation

**Full API reference: [getmilpa.github.io/event-store](https://getmilpa.github.io/event-store/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=event-store)**.
