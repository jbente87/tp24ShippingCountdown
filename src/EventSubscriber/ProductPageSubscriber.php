<?php

declare(strict_types=1);

namespace Tp24ShippingCountdown\EventSubscriber;

use DateTimeImmutable;
use DateTimeZone;
use Tp24ShippingCountdown\Struct\DeliveryTimerStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use function preg_match;
use function preg_split;
use function sprintf;

class ProductPageSubscriber implements EventSubscriberInterface
{
    private const CONFIG_KEY = 'tp24ShippingCountdown.config.shippingTime';

    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'addDeliveryTimer',
        ];
    }

    public function addDeliveryTimer(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();
        if ($product === null) {
            return;
        }

        $customFields = $product->getCustomFields() ?? [];
        $localStock = $customFields['google_export_local_stock'] ?? null;
        if (!is_numeric($localStock) || (float) $localStock <= 0) {
            return;
        }

        $shippingTime = $this->getShippingTime($event);
        [$shippingHour, $shippingMinute] = $this->parseShippingTime($shippingTime);

        $now = $this->createNow($event->getSalesChannelContext()->getContext());
        $holidays = $this->getShippingHolidays($event);
        $shippingDateTime = $this->resolveNextShippingDateTime($now, $shippingHour, $shippingMinute, $holidays);

        $shipsToday = $this->isSameDay($now, $shippingDateTime);
        $shipsTomorrow = $this->isSameDay($now->modify('+1 day'), $shippingDateTime);

        $diff = $now->diff($shippingDateTime);
        $hours = (int) $diff->format('%a') * 24 + (int) $diff->format('%h');
        $minutes = (int) $diff->format('%i');
        $shippingWeekday = strtolower($shippingDateTime->format('l'));
        $shippingDate = $shippingDateTime->format('Y-m-d');

        $event->getPage()->addExtension(
            'tp24ShippingCountdown',
            new DeliveryTimerStruct(
                $hours,
                $minutes,
                $shipsToday,
                $shipsTomorrow,
                $this->formatRemainingTime($hours, $minutes),
                $shippingWeekday,
                $shippingDate
            )
        );
    }

    private function getShippingTime(ProductPageLoadedEvent $event): string
    {
        $configured = $this->systemConfigService->get(self::CONFIG_KEY, $event->getSalesChannelContext()->getSalesChannelId());
        if (is_string($configured) && preg_match('/^\d{1,2}:\d{2}$/', $configured)) {
            return $configured;
        }

        return '14:00';
    }

    private function createNow(Context $context): DateTimeImmutable
    {
        $timezone = $this->resolveTimezone($context);

        return new DateTimeImmutable('now', $timezone);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseShippingTime(string $shippingTime): array
    {
        [$hour, $minute] = array_map(static fn (string $part): int => (int) $part, explode(':', $shippingTime));

        return [$hour, $minute];
    }

    private function resolveTimezone(Context $context): DateTimeZone
    {
        $timezoneName = null;
        if (method_exists($context, 'getTimezone')) {
            $timezoneName = $context->getTimezone();
        }

        if (!is_string($timezoneName) || $timezoneName === '') {
            $timezoneName = date_default_timezone_get();
        }

        return new DateTimeZone($timezoneName);
    }

    /**
     * @param array<string, bool> $holidays
     */
    private function resolveNextShippingDateTime(DateTimeImmutable $now, int $hour, int $minute, array $holidays): DateTimeImmutable
    {
        $candidate = $now->setTime($hour, $minute, 0);

        if ($now > $candidate || !$this->isShippingDay($candidate, $holidays)) {
            $candidate = $now->modify('+1 day')->setTime($hour, $minute, 0);
        }

        while (!$this->isShippingDay($candidate, $holidays)) {
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute, 0);
        }

        return $candidate;
    }

    /**
     * @param array<string, bool> $holidays
     */
    private function isShippingDay(DateTimeImmutable $dateTime, array $holidays): bool
    {
        $weekday = (int) $dateTime->format('N');
        if ($weekday >= 6) {
            return false;
        }

        $dateKey = $dateTime->format('Y-m-d');

        return !isset($holidays[$dateKey]);
    }

    private function isSameDay(DateTimeImmutable $first, DateTimeImmutable $second): bool
    {
        return $first->format('Y-m-d') === $second->format('Y-m-d');
    }

    /**
     * @return array<string, bool>
     */
    private function getShippingHolidays(ProductPageLoadedEvent $event): array
    {
        $configured = $this->systemConfigService->get(
            'tp24ShippingCountdown.config.shippingHolidays',
            $event->getSalesChannelContext()->getSalesChannelId()
        );

        if (!is_string($configured) || trim($configured) === '') {
            return [];
        }

        $holidays = [];
        $parts = preg_split('/[\s,;]+/', $configured) ?: [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('Y-m-d', $part);
            if ($date === false) {
                continue;
            }

            $holidays[$date->format('Y-m-d')] = true;
        }

        return $holidays;
    }

    private function formatRemainingTime(int $hours, int $minutes): string
    {
        $hourLabel = $hours === 1 ? '1 Stunde' : sprintf('%d Stunden', $hours);
        $minuteLabel = $minutes === 1 ? '1 Minute' : sprintf('%d Minuten', $minutes);

        if ($hours === 0) {
            $hourLabel = '';
        }

        if ($minutes === 0) {
            $minuteLabel = '';
        }

        if ($hourLabel !== '' && $minuteLabel !== '') {
            return sprintf('%s und %s', $hourLabel, $minuteLabel);
        }

        $label = $hourLabel !== '' ? $hourLabel : $minuteLabel;

        return $label !== '' ? $label : '0 Minuten';
    }
}
