<?php

declare(strict_types=1);

namespace Tp24ShippingCountdown\Struct;

use Shopware\Core\Framework\Struct\Struct;

class DeliveryTimerStruct extends Struct
{
    private int $hours;

    private int $minutes;

    private bool $shipsToday;

    private string $formattedRemainingTime;

    public function __construct(int $hours, int $minutes, bool $shipsToday, string $formattedRemainingTime)
    {
        $this->hours = $hours;
        $this->minutes = $minutes;
        $this->shipsToday = $shipsToday;
        $this->formattedRemainingTime = $formattedRemainingTime;
    }

    public function getHours(): int
    {
        return $this->hours;
    }

    public function getMinutes(): int
    {
        return $this->minutes;
    }

    public function shipsToday(): bool
    {
        return $this->shipsToday;
    }

    public function getFormattedRemainingTime(): string
    {
        return $this->formattedRemainingTime;
    }
}
