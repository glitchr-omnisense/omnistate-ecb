<?php

namespace Omnistate\Ecb;

use Omnisense\Contracts\Http\Answer;
use Omnisense\Contracts\Http\Data;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnistate\Exception\NotSupportedException;
use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Http\JsonClient;
use Omnistate\Model\Frequency;
use Omnistate\Model\Observation;
use Omnistate\Model\Series;
use Omnistate\Model\TimePeriod;
use Omnistate\Registry\StatisticsRegistryInterface;
use Omnistate\Sdmx\Datasets;
use Omnistate\Sdmx\ObservationFlags;
use Omnistate\Sdmx\Structures;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The European Central Bank's statistics, through the ECB Data Portal's
 * SDMX 2.1 web service (data-api.ecb.europa.eu): open, no key. A series is
 * its dataflow and its key, the dimensions' codes joined by dots, as the
 * portal writes it:
 *
 *     $ecb->series('EXR/D.USD.EUR.SP00.A', last: 5);        // the US dollar's euro reference rate
 *     $ecb->series('FM/B.U2.EUR.4F.KR.MRR_FR.LEV');          // the main refinancing operations' rate
 *
 * One series per id: no wildcard, no "+". Read as SDMX-JSON (format=jsondata).
 * The web service's jsondata keeps no history of revised values (it
 * answers includeHistory=true with an empty body): history is not supported.
 */
final class Ecb implements StatisticsRegistryInterface, DeclaresUsagePolicyInterface
{
    public const URL = 'https://data-api.ecb.europa.eu/service';

    /** Some series by the name everyone calls them (checked against the portal on 2026-11-10). */
    public const SERIES = [
        // The euro foreign exchange reference rate of the US dollar, daily (2.15 pm CET).
        'EUR/USD' => 'EXR/D.USD.EUR.SP00.A',
        // The main refinancing operations' fixed rate, at each change.
        'MRO' => 'FM/B.U2.EUR.4F.KR.MRR_FR.LEV',
    ];

    private readonly JsonClient $client;

    /** @param string|null $userAgent sent with every call; the package's own when null */
    public function __construct(
        HttpClientInterface $http,
        private readonly float $timeout = 30,
        ?string $userAgent = null,
        private readonly string $url = self::URL,
    ) {
        $this->client = new JsonClient($http, 'ECB Data Portal', ['User-Agent' => $userAgent ?? 'omnistate-ecb (+https://github.com/glitchr-omnisense/omnistate-ecb)']);
    }

    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    public function name(): string
    {
        return 'ecb';
    }

    public function supports(string $series): bool
    {
        return null !== self::parse($series);
    }

    public function series(string $series, ?TimePeriod $from = null, ?TimePeriod $to = null, ?int $last = null, bool $history = false): ?Series
    {
        [$flow, $key] = self::parse($series) ?? throw new NotSupportedException(\sprintf('"%s" is not an ECB series: its dataflow and key ("EXR/D.USD.EUR.SP00.A"), or one of %s.', $series, implode(', ', array_keys(self::SERIES))));
        if ($history) {
            throw new NotSupportedException('The ECB Data Portal\'s SDMX-JSON keeps no history of revised values.');
        }
        $query = array_filter([
            'format' => 'jsondata',
            'startPeriod' => null === $from ? null : (string) $from,
            'endPeriod' => null === $to ? null : (string) $to,
            'lastNObservations' => null === $last ? null : max(1, $last),
        ], static fn ($value) => null !== $value);
        $answer = $this->client->answer('GET', rtrim($this->url, '/').'/data/'.$flow.'/'.$key, ['query' => $query, 'timeout' => $this->timeout], ['Accept' => 'application/json']);

        return null === $answer ? null : $this->toSeries($answer, $flow, $key);
    }

    public function datasets(string $query, int $limit = 10): array
    {
        $answer = $this->client->answer('GET', rtrim($this->url, '/').'/dataflow/ECB', ['query' => ['detail' => 'allstubs'], 'timeout' => $this->timeout], ['Accept' => 'application/xml']);

        return null === $answer ? [] : Datasets::search(Structures::dataflows($answer->body, $this->name(), 'en', static fn (string $id): string => 'https://data.ecb.europa.eu/data/datasets/'.$id), $query, $limit);
    }

    /** @return array{string, string}|null the dataflow and the key */
    private static function parse(string $series): ?array
    {
        $series = trim($series);
        $series = self::SERIES[strtoupper($series)] ?? $series;
        if (1 !== preg_match('~^([A-Za-z][A-Za-z0-9_]*)/([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)$~', $series, $match)) {
            return null;
        }

        return [strtoupper($match[1]), $match[2]];
    }

    private function toSeries(Answer $answer, string $flow, string $key): ?Series
    {
        $data = $answer->json();
        if (!$data->has('structure') || !$data->has('dataSets')) {
            throw new UnavailableException('The ECB Data Portal answered something that is not SDMX-JSON data.');
        }
        $structure = $data->data('structure');
        $all = $data->data('dataSets')->data(0)->data('series');
        if ([] === $all->keys()) {
            return null;
        }
        if (\count($all->keys()) > 1) {
            throw new OmnistateException(\sprintf('"%s/%s" selects %d ECB series: one is asked at a time.', $flow, $key, \count($all->keys())));
        }
        $position = (string) $all->keys()[0];
        $one = $all->data($position);
        $dimensions = [];
        $seriesDimensions = $structure->data('dimensions')->list('series');
        foreach (explode(':', $position) as $index => $value) {
            $dimension = $seriesDimensions[$index] ?? null;
            if (null !== $dimension && null !== $id = $dimension->string('id')) {
                $dimensions[$id] = (string) ($dimension->list('values')[(int) $value] ?? null)?->string('id');
            }
        }
        $attributes = self::values($structure->data('attributes')->list('series'), $one->data('attributes')->toArray());
        $times = ($structure->data('dimensions')->list('observation')[0] ?? null)?->list('values') ?? [];
        $observationAttributes = $structure->data('attributes')->list('observation');
        $frequency = Frequency::fromCode($dimensions['FREQ'] ?? null);
        $observations = [];
        $values = $one->data('observations');
        foreach ($values->keys() as $index) {
            $observation = array_values($values->data($index)->toArray());
            $period = ($times[(int) $index] ?? null)?->string('id');
            if (null === $period) {
                continue;
            }
            $flags = self::values($observationAttributes, \array_slice($observation, 1));
            $codes = array_values(array_filter([($flags['OBS_STATUS'] ?? null)?->string('id'), 'C' === ($flags['OBS_CONF'] ?? null)?->string('id') ? 'C' : null], static fn (?string $code): bool => null !== $code));
            $value = $observation[0] ?? null;
            $observations[] = new Observation(
                period: TimePeriod::parse($period, $frequency),
                value: is_numeric($value) ? (float) $value : null,
                status: ObservationFlags::status(null === $value && [] === $codes ? ['M'] : $codes),
                flags: $codes,
            );
        }
        usort($observations, static fn (Observation $a, Observation $b): int => $a->period->compare($b->period));
        $title = ($attributes['TITLE'] ?? null)?->string('name');
        $modified = $answer->header('last-modified');
        $updated = null !== $modified ? \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $modified) : false;

        return new Series(
            source: $this->name(),
            id: $flow.'/'.$key,
            title: $title ?? $flow.'.'.$key,
            frequency: $frequency,
            unit: ($attributes['UNIT'] ?? null)?->string('name'),
            unitMultiplier: ($attributes['UNIT_MULT'] ?? null)?->int('id') ?? 0,
            base: ($attributes['UNIT_INDEX_BASE'] ?? null)?->string('name'),
            dataset: $flow,
            updated: false === $updated ? null : $updated,
            credit: \sprintf('Source: ECB, %s.%s', $flow, $key),
            licence: 'ECB terms of use',
            url: 'https://data.ecb.europa.eu/data/datasets/'.$flow.'/'.$flow.'.'.$key,
            titles: null === $title ? [] : ['en' => $title],
            dimensions: $dimensions,
            observations: $observations,
        );
    }

    /**
     * Attributes by id: the structure's list, each value's position in it given.
     *
     * @param list<Data>   $definitions
     * @param array<mixed> $positions
     *
     * @return array<string, Data>
     */
    private static function values(array $definitions, array $positions): array
    {
        $values = [];
        $positions = array_values($positions);
        foreach ($definitions as $index => $definition) {
            $position = $positions[$index] ?? null;
            $id = $definition->string('id');
            $value = \is_int($position) ? ($definition->list('values')[$position] ?? null) : null;
            if (null !== $id && null !== $value) {
                $values[$id] = $value;
            }
        }

        return $values;
    }
}
