<?php

declare(strict_types=1);

namespace Scheb\YahooFinanceApi;

use BadMethodCallException;
use DateTimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use InvalidArgumentException;
use Scheb\YahooFinanceApi\Exception\ApiException;
use Scheb\YahooFinanceApi\Results\DividendData;
use Scheb\YahooFinanceApi\Results\HistoricalData;
use Scheb\YahooFinanceApi\Results\Quote;
use Scheb\YahooFinanceApi\Results\SearchResult;
use Scheb\YahooFinanceApi\Results\SplitData;
use UnexpectedValueException;
use function in_array;
use const E_USER_DEPRECATED;

class ApiClient
{
    public const CURRENCY_SYMBOL_SUFFIX = '=X';
    protected const FILTER_DIVIDENDS = 'div';
    protected const FILTER_EARN = 'earn';
    protected const FILTER_HISTORICAL = 'history';
    protected const FILTER_SPLITS = 'split';
    public const INTERVAL_15_MIN = '15m';
    public const INTERVAL_1_DAY = '1d';
    public const INTERVAL_1_HOUR = '1h';
    public const INTERVAL_1_MIN = '1m';
    public const INTERVAL_1_MONTH = '1mo';
    public const INTERVAL_1_WEEK = '1wk';
    public const INTERVAL_2_MIN = '2m';
    public const INTERVAL_30_MIN = '30m';
    public const INTERVAL_5_MIN = '5m';
    public const INTERVAL_6_MONTH = '6mo';
    public const INTERVAL_90_MIN = '90m';
    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var ResultDecoder
     */
    protected $resultDecoder;

    public function __construct(ClientInterface $guzzleClient, ResultDecoder $resultDecoder)
    {
        $this->client = $guzzleClient;
        $this->resultDecoder = $resultDecoder;
    }

    /**
     * Search for stocks.
     *
     * @param string $searchTerm
     *
     * @return SearchResult[]
     *
     * @throws ApiException
     */
    public function search(string $searchTerm): array
    {
        $url = 'https://finance.yahoo.com/_finance_doubledown/api/resource/searchassist;gossipConfig=%7B%22queryKey%22:%22query%22,%22resultAccessor%22:%22ResultSet.Result%22,%22suggestionTitleAccessor%22:%22symbol%22,%22suggestionMeta%22:[%22symbol%22],%22url%22:%7B%22query%22:%7B%22region%22:%22US%22,%22lang%22:%22en-US%22%7D%7D%7D;searchTerm='
            . urlencode($searchTerm)
            . '?bkt=[%22findd-ctrl%22,%22fin-strm-test1%22,%22fndmtest%22,%22finnossl%22]&device=desktop&feature=canvassOffnet,finGrayNav,newContentAttribution,relatedVideoFeature,videoNativePlaylist,livecoverage&intl=us&lang=en-US&partner=none&prid=eo2okrhcni00f&region=US&site=finance&tz=UTC&ver=0.102.432&returnMeta=true';
        $responseBody = (string)$this->client->request('GET', $url)->getBody();

        return $this->resultDecoder->transformSearchResult($responseBody);
    }

    /**
     * Get historical data for a symbol (deprecated).
     *
     * @param string $symbol
     * @param string $interval
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     *
     * @return HistoricalData[]
     * @deprecated In future versions, this function will be removed. Please consider using getHistoricalQuoteData() instead.
     *
     */
    public function getHistoricalData(string $symbol, string $interval, DateTimeInterface $startDate, DateTimeInterface $endDate): array
    {
        @trigger_error('[scheb/yahoo-finance-api] getHistoricalData() is deprecated and will be removed in a future release', E_USER_DEPRECATED);

        return $this->getHistoricalQuoteData($symbol, $interval, $startDate, $endDate);
    }

    /**
     * Get historical data for a symbol.
     *
     * @param string $symbol
     * @param string $interval
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     *
     * @return HistoricalData[]
     */
    public function getHistoricalQuoteData(string $symbol, string $interval, DateTimeInterface $startDate, DateTimeInterface $endDate): array
    {
        $this->validateDates($startDate, $endDate);

        try {
            $this->validateChartIntervals($interval);
            return $this->getChartData($symbol, $interval, $startDate, $endDate);
        } catch (InvalidArgumentException $e) {
            $this->validateIntervals($interval);
        }

        $responseBody = $this->getHistoricalDataResponseBody($symbol, $interval, $startDate, $endDate, self::FILTER_HISTORICAL);

        return $this->resultDecoder->transformHistoricalDataResult($responseBody);
    }

    /**
     * Get dividend data for a symbol.
     *
     * @param string $symbol
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     *
     * @return DividendData[]
     */
    public function getHistoricalDividendData(string $symbol, DateTimeInterface $startDate, DateTimeInterface $endDate): array
    {
        $this->validateDates($startDate, $endDate);

        $responseBody = $this->getHistoricalDataResponseBody($symbol, self::INTERVAL_1_MONTH, $startDate, $endDate, self::FILTER_DIVIDENDS);

        $historicData = $this->resultDecoder->transformDividendDataResult($responseBody);
        usort($historicData, function (DividendData $a, DividendData $b): int {
            // Data is not necessary in order, so ensure ascending order by date
            return $a->getDate() <=> $b->getDate();
        });

        return $historicData;
    }

    /**
     * Get stock split data for a symbol.
     *
     * @param string $symbol
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     *
     * @return SplitData[]
     */
    public function getHistoricalSplitData(string $symbol, DateTimeInterface $startDate, DateTimeInterface $endDate): array
    {
        $this->validateDates($startDate, $endDate);

        $responseBody = $this->getHistoricalDataResponseBody($symbol, self::INTERVAL_1_MONTH, $startDate, $endDate, self::FILTER_SPLITS);

        $historicData = $this->resultDecoder->transformSplitDataResult($responseBody);
        usort($historicData, function (SplitData $a, SplitData $b): int {
            // Data is not necessary in order, so ensure ascending order by date
            return $a->getDate() <=> $b->getDate();
        });

        return $historicData;
    }

    /**
     * Get quote for a single symbol.
     *
     * @param string $symbol
     *
     * @return Quote|null
     *
     * @throws ApiException
     */
    public function getQuote(string $symbol): ?Quote
    {
        $list = $this->fetchQuotes([$symbol]);

        return isset($list[0]) ? $list[0] : null;
    }

    /**
     * Get quotes for one or multiple symbols.
     *
     * @param array $symbols
     *
     * @return Quote[]
     *
     * @throws ApiException
     */
    public function getQuotes(array $symbols): array
    {
        return $this->fetchQuotes($symbols);
    }

    /**
     * Get exchange rate for two currencies. Accepts concatenated ISO 4217 currency codes such as "GBP" or "USD".
     *
     * @param string $currency1
     * @param string $currency2
     *
     * @return Quote|null
     *
     * @throws ApiException
     */
    public function getExchangeRate(string $currency1, string $currency2): ?Quote
    {
        $list = $this->getExchangeRates([[$currency1, $currency2]]);

        return isset($list[0]) ? $list[0] : null;
    }

    /**
     * Retrieves currency exchange rates. Accepts concatenated ISO 4217 currency codes such as "GBP" or "USD".
     *
     * @param string[][] $currencyPairs List of pairs of currencies, e.g. [["USD", "GBP"]]
     *
     * @return Quote[]
     *
     * @throws ApiException
     */
    public function getExchangeRates(array $currencyPairs): array
    {
        $currencySymbols = array_map(function (array $currencies) {
            return implode($currencies) . self::CURRENCY_SYMBOL_SUFFIX; // Currency pairs are suffixed with "=X"
        }, $currencyPairs);

        return $this->fetchQuotes($currencySymbols);
    }

    /**
     * Get candles for a symbol.
     *
     * @param string $symbol
     * @param string $interval
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     *
     * @return HistoricalData[]
     *
     * @throws ApiException
     */
    protected function getChartData(string $symbol, string $interval, DateTimeInterface $startDate, DateTimeInterface $endDate): array
    {
        $responseBody = $this->getChartDataResponseBody($symbol, $interval, $startDate, $endDate, [self::FILTER_DIVIDENDS, self::FILTER_SPLITS, self::FILTER_EARN]);

        if ($response = json_decode($responseBody, true)) {
            if (!empty($response['chart']['error'])) {
                throw new BadMethodCallException($response['chart']['error']);
            }
            if (!isset($response['chart']['result'])) {
                throw new UnexpectedValueException('Missing parameter `chart->result`');
            }
            $return = [];
            foreach ($response['chart']['result'] as $result) {
                $count = count($result['timestamp'] ?? []);
                foreach ($result['indicators']['quote'] as $index1 => $quote) {
                    for ($index2 = 0; $index2 < $count; ++$index2) {
                        $res = [
                            $result['timestamp'][$index2],
                            $quote['open'][$index2],
                            $quote['high'][$index2],
                            $quote['low'][$index2],
                            $quote['close'][$index2],
                            $result['indicators']['adjclose'][$index1]['adjclose'][$index2] ?? $quote['close'][$index2],
                            $quote['volume'][$index2],
                        ];
                        if (!array_filter($res, function ($item) {
                            return $item === null;
                        })) {
                            $return[] = $this->resultDecoder->createHistoricalData($res);
                        }
                    }
                }
            }
            return $return;
        }
        throw new UnexpectedValueException('Unexpected value');
    }

    /**
     * Fetch quote data from API.
     *
     * @param array $symbols
     *
     * @return Quote[]
     *
     * @throws ApiException
     */
    protected function fetchQuotes(array $symbols): array
    {
        $url = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode(implode(',', $symbols));
        $responseBody = (string)$this->client->request('GET', $url)->getBody();

        return $this->resultDecoder->transformQuotes($responseBody);
    }

    /**
     * @param string $symbol
     * @param string $interval
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     * @param array|string $filter
     * @return string
     * @throws ApiException
     */
    protected function getChartDataResponseBody(string $symbol, string $interval, DateTimeInterface $startDate, DateTimeInterface $endDate, $filter): string
    {
        $cookieJar = new CookieJar();

        $initialUrl = 'https://finance.yahoo.com/quote/' . urlencode($symbol) . '/chart?p=' . urlencode($symbol);
        $responseBody = (string)$this->client->request('GET', $initialUrl, ['cookies' => $cookieJar])->getBody();
        $crumb = $this->resultDecoder->extractCrumb($responseBody);
        if (is_array($filter)) {
            $filter = implode('|', $filter);
        } else {
            $filter = (string)$filter;
        }
        $dataUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/' . urlencode($symbol) . '?symbol=' . urlencode($symbol) . '&period1=' . $startDate->getTimestamp() . '&period2=' . $endDate->getTimestamp() . '&interval=' . $interval . '&events=' . urlencode($filter) . '&crumb=' . urlencode($crumb);

        return (string)$this->client->request('GET', $dataUrl, ['cookies' => $cookieJar])->getBody();
    }

    /**
     * @param string $symbol
     * @param string $interval
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     * @param array|string $filter
     * @return string
     * @throws ApiException
     */
    protected function getHistoricalDataResponseBody(string $symbol, string $interval, DateTimeInterface $startDate, DateTimeInterface $endDate, $filter): string
    {
        $cookieJar = new CookieJar();

        $initialUrl = 'https://finance.yahoo.com/quote/' . urlencode($symbol) . '/history?p=' . urlencode($symbol);
        $responseBody = (string)$this->client->request('GET', $initialUrl, ['cookies' => $cookieJar])->getBody();
        $crumb = $this->resultDecoder->extractCrumb($responseBody);
        if (is_array($filter)) {
            $filter = implode('|', $filter);
        } else {
            $filter = (string)$filter;
        }
        $dataUrl = 'https://query1.finance.yahoo.com/v7/finance/download/' . urlencode($symbol) . '?period1=' . $startDate->getTimestamp() . '&period2=' . $endDate->getTimestamp() . '&interval=' . $interval . '&events=' . $filter . '&crumb=' . urlencode($crumb);

        return (string)$this->client->request('GET', $dataUrl, ['cookies' => $cookieJar])->getBody();
    }

    protected function validateChartIntervals(string $interval): void
    {
        $allowedIntervals = [self::INTERVAL_1_MIN, self::INTERVAL_2_MIN, self::INTERVAL_5_MIN, self::INTERVAL_15_MIN, self::INTERVAL_30_MIN, self::INTERVAL_90_MIN, self::INTERVAL_1_HOUR, self::INTERVAL_6_MONTH];
        if (!in_array($interval, $allowedIntervals)) {
            throw new InvalidArgumentException(sprintf('Interval must be one of: %s', implode(', ', $allowedIntervals)));
        }
    }

    protected function validateIntervals(string $interval): void
    {
        $allowedIntervals = [self::INTERVAL_1_DAY, self::INTERVAL_1_WEEK, self::INTERVAL_1_MONTH];
        if (!in_array($interval, $allowedIntervals)) {
            throw new InvalidArgumentException(sprintf('Interval must be one of: %s', implode(', ', $allowedIntervals)));
        }
    }

    protected function validateDates(DateTimeInterface $startDate, DateTimeInterface $endDate): void
    {
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('Start date must be before end date');
        }
    }
}
