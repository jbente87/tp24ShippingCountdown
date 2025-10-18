<?php

declare(strict_types=1);

namespace Tp24ShippingCountdown\Struct;

use Shopware\Core\Framework\Struct\Struct;

class DeliveryTimerStruct extends Struct
{
    private int $days;

    private int $hours;

    private int $minutes;

    private bool $shipsToday;

    private string $formattedRemainingTime;

    private bool $shipsTomorrow;

    private string $shippingWeekday;

    private string $shippingDate;

    public function __construct(
        int $days,
        int $hours,
        int $minutes,
        bool $shipsToday,
        bool $shipsTomorrow,
        string $formattedRemainingTime,
        string $shippingWeekday,
        string $shippingDate
    )
    {
        $this->days = $days;
        $this->hours = $hours;
        $this->minutes = $minutes;
        $this->shipsToday = $shipsToday;
        $this->formattedRemainingTime = $formattedRemainingTime;
        $this->shipsTomorrow = $shipsTomorrow;
        $this->shippingWeekday = $shippingWeekday;
        $this->shippingDate = $shippingDate;
    }

    public function getDays(): int
    {
        return $this->days;
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

    public function shipsTomorrow(): bool
    {
        return $this->shipsTomorrow;
    }

    public function getFormattedRemainingTime(): string
    {
        return $this->formattedRemainingTime;
    }

    public function getShippingWeekday(): string
    {
        return $this->shippingWeekday;
    }

    public function getShippingDate(): string
    {
        return $this->shippingDate;
    }
}
