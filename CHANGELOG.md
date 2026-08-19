# Changelog

## [0.3.0](https://github.com/getmilpa/event-store/compare/v0.2.0...v0.3.0) (2026-08-19)


### ⚠ BREAKING CHANGES

* EventStoreInterface has a new method, so every implementor must provide replayAll(). The bundled FileEventStore and InMemoryEventStore do.

### Features

* replayAll() replays every stream in a single pass ([1d1dc6c](https://github.com/getmilpa/event-store/commit/1d1dc6cbcbe1c6458ac576b3d14f4d778f3ea6db))

## [0.2.0](https://github.com/getmilpa/event-store/compare/v0.1.0...v0.2.0) (2026-08-18)


### Features

* record event wall-clock observations ([#4](https://github.com/getmilpa/event-store/issues/4)) ([3034be9](https://github.com/getmilpa/event-store/commit/3034be939b9bce689e799c8505c2ffee15c4e090))

## 0.1.0 (2026-07-09)


### Features

* milpa/event-store initial public release ([5d06e7d](https://github.com/getmilpa/event-store/commit/5d06e7dec88d27f771286e14ee318de319335468))


### Miscellaneous Chores

* release 0.1.0 ([c0e7276](https://github.com/getmilpa/event-store/commit/c0e7276dda3af2cb08c18cdc9bb3a688157d3513))
