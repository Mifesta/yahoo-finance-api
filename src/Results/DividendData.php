<?php

declare(strict_types=1);

namespace Scheb\YahooFinanceApi\Results;

use DateTime;
use DateTimeInterface;
use JsonSerializable;

class DividendData implements JsonSerializable
{
    private $date;
    private $dividends;

    public function __construct(DateTime $date, ?float $dividends)
    {
        $this->date = $date;
        $this->dividends = $dividends;
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    public function getDate(): DateTimeInterface
    {
        return $this->date;
    }

    public function getDividends(): ?float
    {
        return $this->dividends;
    }
}
