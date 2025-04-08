<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber;

use ICTECHOdooShopwareConnector\Service\OrderCreate;
use ICTECHOdooShopwareConnector\RestApi\OdooClient;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderCreateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly orderCreate $orderCreate,
        private readonly OdooClient $odooClient
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onCreateOrder',
        ];
    }

    public function onCreateOrder(CheckoutOrderPlacedEvent $event): void
    {
        $orderData = $event->getOrder();
        $context = $event->getContext();
        $this->orderCreate->createOrderSyncStatus($orderData, $context);
        $orderPayload = $this->orderCreate->createOrder($orderData, $context);
        $this->odooClient->importOrder($orderPayload, $context);
    }
}
