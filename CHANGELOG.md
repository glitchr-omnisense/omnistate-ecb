# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

### Added
- The first release: the ECB Data Portal's SDMX web service as an Omnistate statistics source - a
  series by its dataflow and key (or EUR/USD, MRO), its metadata and observations, the dataflows
  searched; `policy.json` (free use, the ECB cited, limits unknown). Tests on the portal's
  answers recorded on 2026-11-10, the family's conformance suite.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnistate: ^1.0`: stable versions, so `composer require omnistate/ecb` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).

