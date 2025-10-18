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

        $now = $this->createNow($event->getSalesChannelContext()->getContext());
        $shippingDateTime = $this->createShippingDateTime($now, $shippingTime);

        $shipsToday = $now <= $shippingDateTime;
        if (!$shipsToday) {
            $shippingDateTime = $shippingDateTime->modify('+1 day');
        }

        $diff = $now->diff($shippingDateTime);
        $hours = (int) $diff->format('%a') * 24 + (int) $diff->format('%h');
        $minutes = (int) $diff->format('%i');
        
        $event->getPage()->addExtension(
            'tp24ShippingCountdown',
            new DeliveryTimerStruct($hours, $minutes, $shipsToday, $this->formatRemainingTime($hours, $minutes))
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

    private function createShippingDateTime(DateTimeImmutable $now, string $shippingTime): DateTimeImmutable
    {
        [$hour, $minute] = array_map(static fn (string $part): int => (int) $part, explode(':', $shippingTime));

        return $now->setTime($hour, $minute, 0);
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
