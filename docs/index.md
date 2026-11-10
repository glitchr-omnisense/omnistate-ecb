---
title: omnistate/ecb
---

# omnistate/ecb

The ECB Data Portal's SDMX 2.1 web service as an Omnistate statistics source
(`Registry\StatisticsRegistryInterface`).

| Method | Request |
|---|---|
| `series($id, $from, $to, $last)` | `GET https://data-api.ecb.europa.eu/service/data/<flow>/<key>?format=jsondata&startPeriod=…&endPeriod=…&lastNObservations=…` |
| `datasets($query, $limit)` | `GET https://data-api.ecb.europa.eu/service/dataflow/ECB?detail=allstubs`, searched here |

## Installation

```sh
composer require omnisense/omnistate omnistate/ecb
```

## Credentials

None.

## Options

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `timeout` | float | `30` | seconds the portal has to answer |
| `userAgent` | `?string` | the package's | the User-Agent sent |
| `url` | string | `https://data-api.ecb.europa.eu/service` | the web service's base address |

## Ids

A dataflow and a series key, the dimensions' codes joined by dots, as the portal writes them
(each series' page shows its key): `EXR/D.USD.EUR.SP00.A`. One series per id: no wildcard (an
empty code) and no `+`. Names in `Ecb::SERIES`, checked on 2026-11-10: `EUR/USD`
(`EXR/D.USD.EUR.SP00.A`, the US dollar's daily reference rate) and `MRO`
(`FM/B.U2.EUR.4F.KR.MRR_FR.LEV`, the main refinancing operations' rate at each change).

## Behaviour

Read as SDMX-JSON (`format=jsondata`):

| Answer | Series / Observation |
|---|---|
| the series dimensions' codes | `dimensions`; `FREQ` is `frequency` (`B`, business days, kept) |
| `TITLE` | `title` |
| `UNIT`'s name | `unit` |
| `UNIT_MULT` | `unitMultiplier` |
| `UNIT_INDEX_BASE` | `base` |
| the answer's `Last-Modified` | `updated` |
| `TIME_PERIOD` | `period` |
| the value | `value` |
| `OBS_STATUS` (and `OBS_CONF` C) | `status`, `flags` |

The web service answers `includeHistory=true` in SDMX-JSON with an empty body (tried on
2026-11-10): `history: true` is a `NotSupportedException`. An answer holding several series is an
`OmnistateException`.

**Datasets.** The ECB's dataflows (105 on 2026-11-10), searched in their id and name:
`datasets('exchange rates')` gives `EXR` first. The full list (without `detail=allstubs`) made the
gateway answer 504 twice that day.

## Errors

| What happens | What it does |
|---|---|
| no series for the key (404) | `null` |
| an id it does not read | `NotSupportedException`, no request |
| 400 (a key the dataflow does not take) | `OmnistateException` |
| 5xx (the gateway's 504 too), 401, 403, no answer, a body that is not SDMX-JSON | `UnavailableException` |
| 429 | `UnavailableException` with its Retry-After, `status` 429 |

## Usage policy

`policy.json`, read on 2026-11-10 from the ECB's copyright and the portal's API pages: limits
unknown (none stated), free use, storage and redistribution, the ECB cited as the source ("Source:
ECB"; each series' `credit` names it), changes made by the user stated, information sold said to
be free on the ECB's website.

## Verified, and not

Recorded on 2026-11-10 (Tests/Fixtures): the US dollar's last three reference rates, the main
refinancing rate since June 2025, an unknown key (404), the dataflow list. Not verified: a 400, a
429, a provisional or confidential observation.

## Responsibility

An independent, unofficial client, not affiliated with the ECB. Whoever runs it complies with the
ECB's disclaimer and copyright: see the README's Responsibility section.
