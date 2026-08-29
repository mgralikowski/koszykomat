<?php

namespace App\Pricing;

enum VerdictType: string
{
    case Winner = 'winner';
    case Tie = 'tie';
    case NoData = 'no_data';

    /**
     * Polish label for the report (user-facing string).
     */
    public function label(): string
    {
        return match ($this) {
            self::Winner => 'taniej w',
            self::Tie => 'remis',
            self::NoData => 'brak danych',
        };
    }
}
