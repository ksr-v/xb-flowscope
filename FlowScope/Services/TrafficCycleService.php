<?php

namespace Plugin\Flowscope\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class TrafficCycleService
{
    /**
     * Each boundary is clamped independently: day 31 becomes February's last
     * day, then returns to March 31. All queries use [start, end_exclusive).
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, end_exclusive: DateTimeImmutable, timezone: string}
     */
    public function calculate(int $day, ?DateTimeInterface $now = null, ?string $timezone = null): array
    {
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException('Cycle start day must be between 1 and 31.');
        }

        $timezone ??= (string) config('app.timezone', date_default_timezone_get());
        $zone = new DateTimeZone($timezone);
        $localNow = $now
            ? DateTimeImmutable::createFromInterface($now)->setTimezone($zone)
            : new DateTimeImmutable('now', $zone);
        $month = $localNow->modify('first day of this month')->setTime(0, 0);
        $boundary = $this->boundary($month, $day);

        if ($localNow < $boundary) {
            $start = $this->boundary($month->modify('-1 month'), $day);
            $next = $boundary;
        } else {
            $start = $boundary;
            $next = $this->boundary($month->modify('+1 month'), $day);
        }

        return [
            'start' => $start,
            'end' => $next->modify('-1 second'),
            'end_exclusive' => $next,
            'timezone' => $zone->getName(),
        ];
    }

    private function boundary(DateTimeImmutable $month, int $day): DateTimeImmutable
    {
        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min($day, (int) $month->format('t')))
            ->setTime(0, 0);
    }
}
