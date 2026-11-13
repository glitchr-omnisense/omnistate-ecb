# omnistate/ecb

The European Central Bank's statistics from its [Data Portal](https://data.ecb.europa.eu)'s SDMX
2.1 web service, for [omnisense/omnistate](../core): the euro foreign exchange reference rates, the
key interest rates, every series by its dataflow and key. Open, no key.

```php
$ecb = new Ecb($httpClient);
$ecb->series('EUR/USD', last: 1)->latest()->value;            // 1.1193 (2026-11-09)
$ecb->series('FM/B.U2.EUR.4F.KR.MRR_FR.LEV')->latest()->value; // the main refinancing rate
```

No framework needed: the package requires `omnisense/omnistate`, `omnisense/contracts`,
`symfony/http-client` and PHP's SimpleXML (the dataflow list).

## Install

```sh
composer require omnistate/ecb
```

It brings the family's core, `omnisense/omnistate`, and `omnisense/contracts` with it. No account and no key: the ECB's web service is open ([`policy.json`](policy.json)).

## Usage policy

`policy.json`, read on 2026-11-10: no limit stated (unknown), free use with the ECB cited as the
source, changes made by the user stated.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the European Central Bank. It reaches its service on behalf of whoever runs it, and
it does not redistribute its data beyond the short excerpts recorded as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the [ECB's disclaimer
and copyright](https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html),
for citing the source, and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

## Documentation

[docs/](docs/index.md): the requests, the ids, the mapping, errors, verified and not.

## License

MIT.
