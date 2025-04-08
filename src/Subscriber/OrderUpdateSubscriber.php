<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber;

use ICTECHOdooShopwareConnector\Controller\ApiController;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderUpdateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly ApiController $apiController,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_LINE_ITEM_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        $updatedOrderData = [];
        $updatedData = $event->getWriteResults();
        foreach ($updatedData as $data) {
            $orderPayload = $data->getPayload();
            $orderId = $orderPayload['orderId'];
            $orderData = $this->findOrderNumberById($orderId, $event->getContext());
            $updatedOrderData = [
                'orderId' => $orderId,
                'quantity' => $orderPayload['quantity'],
                'productName' => $orderPayload['label'],
                'productPrice' => $orderPayload['price']->getUnitPrice(),
                'orderNumber' => $orderData->getOrderNumber(),
            ];
        }
        $this->apiController->updateOrder($updatedOrderData);
    }

    public function findOrderNumberById(string $orderId, Context $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $orderId));
        return $this->orderRepository->search($criteria, $context)->first();
    }
}
