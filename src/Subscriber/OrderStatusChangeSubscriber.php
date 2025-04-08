<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber;

use ICTECHOdooShopwareConnector\RestApi\OdooClient;
use ICTECHOdooShopwareConnector\Service\OrderStatus;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderStatusChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly odooClient $odooClient,
        private readonly OrderStatus $odooStatus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order.state_changed' => 'changeOrderStatus',
            'state_machine.order_transaction.state_changed' => 'changeOrderTransactionStatus',
            'state_machine.order_delivery.state_changed' => 'onOrderDeliveryStateChangeEvent'
        ];
    }

    public function changeOrderStatus(StateMachineStateChangeEvent $event): void
    {
        $context = $event->getContext();
        $orderStatusData = $this->odooStatus->changeOrderStatus($event);
        if ($orderStatusData) {
            $this->odooClient->orderStatusChange($orderStatusData, $context);
        }
    }

    public function changeOrderTransactionStatus(StateMachineStateChangeEvent $event): void
    {
        $context = $event->getContext();
        $orderTransactionStatusData = $this->odooStatus->changeOrderTransactionStatus($event);
        if ($orderTransactionStatusData) {
            $this->odooClient->orderStatusChange($orderTransactionStatusData, $context);
        }
    }

    public function onOrderDeliveryStateChangeEvent(StateMachineStateChangeEvent $event) : void
    {
        $context = $event->getContext();
        $orderDeliveryStatusData = $this->odooStatus->changeOrderDeliveryStatus($event, $context);
        if ($orderDeliveryStatusData) {
            $this->odooClient->orderStatusChange($orderDeliveryStatusData, $context);
        }
    }
}
