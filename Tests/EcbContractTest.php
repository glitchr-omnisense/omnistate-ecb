<?php

namespace Omnistate\Ecb\Tests;

use Omnistate\Ecb\Ecb;
use Omnistate\Testing\RegistryContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnistate's conformance suite, on the ECB Data Portal's answers recorded on 2026-11-10 (Tests/Fixtures). */
final class EcbContractTest extends TestCase
{
    use RegistryContractTests;

    protected function registry(HttpClientInterface $http): object
    {
        return new Ecb($http);
    }

    protected function recordedAnswers(): array
    {
        $file = static fn (string $file, string $type) => static fn () => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file), ['response_headers' => ['content-type' => $type]]);

        return [
            'GET /service/data/EXR/D.USD.EUR.SP00.A' => $file('exr-usd.json', 'application/vnd.sdmx.data+json;version=1.0.0-wd'),
            'GET /service/dataflow/ECB' => $file('dataflows.xml', 'application/vnd.sdmx.structure+xml;version=2.1'),
        ];
    }

    protected function seriesIdentifier(): string
    {
        return 'EXR/D.USD.EUR.SP00.A';
    }

    protected function datasetQuery(): string
    {
        return 'exchange rates';
    }
}
