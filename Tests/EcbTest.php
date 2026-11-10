<?php

namespace Omnistate\Ecb\Tests;

use Omnisense\Contracts\Testing\MockHttp;
use Omnistate\Ecb\Ecb;
use Omnistate\Exception\NotSupportedException;
use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Model\Frequency;
use Omnistate\Model\ObservationStatus;
use Omnistate\Model\TimePeriod;
use Omnistate\Omnistate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** The ECB Data Portal, on its answers recorded on 2026-11-10 (Tests/Fixtures). */
final class EcbTest extends TestCase
{
    public function testTheDollarsReferenceRate(): void
    {
        $http = $this->http('exr-usd.json');
        $rate = (new Ecb($http->client))->series('EUR/USD', last: 3);

        self::assertNotNull($rate);
        self::assertSame('https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?format=jsondata&lastNObservations=3', $http->request()['url']);
        self::assertStringStartsWith('omnistate-ecb', (string) $http->header('user-agent'));
        self::assertSame(['ecb', 'EXR/D.USD.EUR.SP00.A', 'EXR'], [$rate->source, $rate->id, $rate->dataset]);
        self::assertSame('US dollar/Euro ECB reference exchange rate', $rate->title);
        self::assertSame(Frequency::DAILY, $rate->frequency);
        self::assertSame('US dollar', $rate->unit);
        self::assertSame(0, $rate->unitMultiplier);
        self::assertSame('99Q1=100', $rate->base);
        self::assertSame(['FREQ' => 'D', 'CURRENCY' => 'USD', 'CURRENCY_DENOM' => 'EUR', 'EXR_TYPE' => 'SP00', 'EXR_SUFFIX' => 'A'], $rate->dimensions);
        self::assertSame('2026-11-09', $rate->updated?->format('Y-m-d'), 'the answer\'s Last-Modified');
        self::assertSame('Source: ECB, EXR.D.USD.EUR.SP00.A', $rate->credit);
        self::assertSame('https://data.ecb.europa.eu/data/datasets/EXR/EXR.D.USD.EUR.SP00.A', $rate->url);
        self::assertSame(['2026-11-05', '2026-11-06', '2026-11-09'], array_map(static fn ($o) => (string) $o->period, $rate->observations));
        self::assertSame(1.1193, $rate->latest()?->value);
        self::assertSame(ObservationStatus::NORMAL, $rate->latest()->status);
        self::assertSame(['A'], $rate->latest()->flags);
    }

    public function testAKeyInterestRateAtEachChange(): void
    {
        $http = $this->http('mro.json');
        $mro = (new Ecb($http->client))->series('MRO', TimePeriod::day('2025-06-01'));

        self::assertStringContainsString('/data/FM/B.U2.EUR.4F.KR.MRR_FR.LEV?format=jsondata&startPeriod=2025-06-01', $http->request()['url']);
        self::assertSame(Frequency::BUSINESS, $mro?->frequency);
        self::assertSame('Percent per annum', $mro->unit);
        self::assertSame([2.15, 2.4, 2.65], array_map(static fn ($o) => $o->value, $mro->observations));
        self::assertSame(Frequency::BUSINESS, $mro->observations[0]->period->frequency);
    }

    public function testAnUnknownSeriesIsNull(): void
    {
        self::assertNull((new Ecb(MockHttp::routes(['~^GET ~' => [404, '@'.__DIR__.'/Fixtures/not-found.json', ['content-type' => 'application/problem+json']]])->client))->series('EXR/D.XXX.EUR.SP00.A'));
    }

    public function testOneSeriesAtATime(): void
    {
        $ecb = new Ecb(new MockHttpClient());

        self::assertFalse($ecb->supports('EXR/D.USD+JPY.EUR.SP00.A'), 'no "+"');
        self::assertFalse($ecb->supports('EXR/D..EUR.SP00.A'), 'no wildcard');
        self::assertFalse($ecb->supports('the euro'));
        self::assertTrue($ecb->supports('exr/D.USD.EUR.SP00.A'));
        $this->expectException(NotSupportedException::class);
        $ecb->series('EXR/D.USD.EUR.SP00.A', history: true);
    }

    public function testSeveralSeriesInAnAnswerAreRefused(): void
    {
        /** @var array{dataSets: array{0: array{series: array<string, mixed>}}} $two */
        $two = json_decode((string) file_get_contents(__DIR__.'/Fixtures/exr-usd.json'), true);
        $two['dataSets'][0]['series']['0:1:0:0:0'] = $two['dataSets'][0]['series']['0:0:0:0:0'];

        $this->expectException(OmnistateException::class);
        (new Ecb(MockHttp::routes(['~^GET ~' => [200, (string) json_encode($two)]])->client))->series('EXR/D.USD.EUR.SP00.A');
    }

    public function testTheGatewaysTimeoutIsUnavailable(): void
    {
        $this->expectException(UnavailableException::class);
        (new Ecb(MockHttp::routes(['~^GET ~' => [504, '<html><body>Gateway Timeout</body></html>', ['content-type' => 'text/html']]])->client))->series('EUR/USD');
    }

    public function testTheDataflowsAreSearched(): void
    {
        $http = $this->http('dataflows.xml');
        $found = (new Ecb($http->client))->datasets('exchange rates');

        self::assertSame('https://data-api.ecb.europa.eu/service/dataflow/ECB?detail=allstubs', $http->request()['url']);
        self::assertSame('EXR', $found[0]->id);
        self::assertSame('Exchange Rates', $found[0]->title);
        self::assertSame('https://data.ecb.europa.eu/data/datasets/EXR', $found[0]->url);
    }

    public function testTheFacadeFindsItByItsRefOrAlone(): void
    {
        $omnistate = new Omnistate(statistics: [new Ecb($this->http('exr-usd.json')->client)]);

        self::assertSame(1.1193, $omnistate->series('ecb:EUR/USD')?->latest()?->value);
        self::assertSame(1.1193, $omnistate->series('EXR/D.USD.EUR.SP00.A')?->latest()?->value, 'an id alone: the first source that reads it');
        self::assertSame('Source: ECB', $omnistate->policy('ecb')->rights->attribution->text);
    }

    private function http(string $fixture): MockHttp
    {
        return MockHttp::routes(['~^GET https://data-api\.ecb\.europa\.eu/service/~' => [200, '@'.__DIR__.'/Fixtures/'.$fixture, ['content-type' => str_ends_with($fixture, '.xml') ? 'application/vnd.sdmx.structure+xml;version=2.1' : 'application/vnd.sdmx.data+json;version=1.0.0-wd', 'last-modified' => 'Mon, 09 Nov 2026 14:57:03 GMT']]]);
    }
}
