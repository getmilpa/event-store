# Changelog

## [0.3.2](https://github.com/getmilpa/event-store/compare/v0.3.1...v0.3.2) (2026-09-29)


### Performance Improvements

* **store:** read the log once and share it — later calls read only what was appended ([#11](https://github.com/getmilpa/event-store/issues/11)) ([680b283](https://github.com/getmilpa/event-store/commit/680b283a92d8c29ce2b5a285068c38814e6029e6))

## [0.3.1](https://github.com/getmilpa/event-store/compare/v0.3.0...v0.3.1) (2026-09-29)


### Performance Improvements

* **store:** number and replay by reading the log a line at a time ([#9](https://github.com/getmilpa/event-store/issues/9)) ([693e935](https://github.com/getmilpa/event-store/commit/693e935d1c5a35a776976fbda794317635f68019))

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
