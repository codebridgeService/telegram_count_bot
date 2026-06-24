<?php

declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;

class KhmerDateFormatter
{
    private const MONTHS = [
        1  => 'មករា',
        2  => 'កុម្ភៈ',
        3  => 'មីនា',
        4  => 'មេសា',
        5  => 'ឧសភា',
        6  => 'មិថុនា',
        7  => 'កក្កដា',
        8  => 'សីហា',
        9  => 'កញ្ញា',
        10 => 'តុលា',
        11 => 'វិច្ឆិកា',
        12 => 'ធ្នូ',
    ];

    private const DIGITS = ['០','១','២','៣','៤','៥','៦','៧','៨','៩'];

    // ── Convert any number string to Khmer digits ─────────────────────────────
    public static function toKhmerNum(int|string $number): string
    {
        return str_replace(range(0, 9), self::DIGITS, (string) $number);
    }

    // ── Full date: ២៣ មិថុនា ២០២៥ ───────────────────────────────────────────
    public static function date(Carbon $date): string
    {
        $day   = self::toKhmerNum($date->day);
        $month = self::MONTHS[$date->month];
        $year  = self::toKhmerNum($date->year);

        return "{$day} {$month} {$year}";
    }

    // ── Full date + time: ២៣ មិថុនា ២០២៥ ម៉ោង ១១:៣៩ ព្រឹក ────────────────
    public static function dateTime(Carbon $date): string
    {
        $day       = self::toKhmerNum($date->day);
        $month     = self::MONTHS[$date->month];
        $year      = self::toKhmerNum($date->year);
        $hour      = self::toKhmerNum((int) $date->format('g'));
        $minuteRaw = str_pad((string) $date->minute, 2, '0', STR_PAD_LEFT);
        $minute    = self::toKhmerNum($minuteRaw[0]) . self::toKhmerNum($minuteRaw[1]);
        $period    = $date->hour < 12 ? 'ព្រឹក' : 'រសៀល';

        return "{$day} {$month} {$year} ម៉ោង {$hour}:{$minute} {$period}";
    }

    // ── Month + Year only: មិថុនា ២០២៥ ──────────────────────────────────────
    public static function monthYear(Carbon $date): string
    {
        $month = self::MONTHS[$date->month];
        $year  = self::toKhmerNum($date->year);

        return "{$month} {$year}";
    }

    // ── Month name only: មិថុនា ─────────────────────────────────────────────
    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? '';
    }
}