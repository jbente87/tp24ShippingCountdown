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
use Symfony\Contracts\Translation\TranslatorInterface;
use function preg_match;
use function preg_split;

class ProductPageSubscriber implements EventSubscriberInterface
{
    private const CONFIG_KEY = 'tp24ShippingCountdown.config.shippingTime';

    private SystemConfigService $systemConfigService;

    private TranslatorInterface $translator;

    public function __construct(SystemConfigService $systemConfigService, TranslatorInterface $translator)
    {
        $this->systemConfigService = $systemConfigService;
        $this->translator = $translator;
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
        $days = (int) $diff->format('%a');
        $hours = (int) $diff->format('%h');
        $minutes = (int) $diff->format('%i');
        $shippingWeekday = strtolower($shippingDateTime->format('l'));
        $shippingDate = $shippingDateTime->format('Y-m-d');

        $event->getPage()->addExtension(
            'tp24ShippingCountdown',
            new DeliveryTimerStruct(
                $days,
                $hours,
                $minutes,
                $shipsToday,
                $shipsTomorrow,
                $this->formatRemainingTime($days, $hours, $minutes),
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

    private function formatRemainingTime(int $days, int $hours, int $minutes): string
    {
        $parts = [];

        if ($days > 0) {
            $parts[] = $this->translator->trans(
                'tp24ShippingCountdown.productDetail.time.days',
                ['%count%' => $days, 'count' => $days]
            );
        }

        if ($hours > 0) {
            $parts[] = $this->translator->trans(
                'tp24ShippingCountdown.productDetail.time.hours',
                ['%count%' => $hours, 'count' => $hours]
            );
        }

        if ($minutes > 0) {
            $parts[] = $this->translator->trans(
                'tp24ShippingCountdown.productDetail.time.minutes',
                ['%count%' => $minutes, 'count' => $minutes]
            );
        }

        if ($minutes === 0 && $days === 0 && $hours === 0) {
            $parts[] = $this->translator->trans(
                'tp24ShippingCountdown.productDetail.time.minutes',
                ['%count%' => 0, 'count' => 0]
            );
        }

        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $connector = $this->translator->trans('tp24ShippingCountdown.productDetail.time.connector');
        $last = array_pop($parts);

        return implode(' ', $parts) . ' ' . $connector . ' ' . $last;
    }
}
