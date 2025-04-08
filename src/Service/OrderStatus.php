<?php

namespace ICTECHOdooShopwareConnector\Service;

use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use ICTECHOdooShopwareConnector\RestApi\OdooClient;
use Shopware\Core\Checkout\Order\Event\OrderStateChangeCriteriaEvent;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderStatus
{
    public function __construct(
        private readonly EntityRepository $stateMachineRepository,
        private readonly EntityRepository $orderRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityRepository $transactionRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly odooClient $odooClient,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly PluginConfig $pluginConfig,
    ) {
    }

    public function getAllStatus($context): array
    {
        $orderStatus = $this->getOrderStatus($context);
        $deliveryStatus = $this->getDeliveryStatus($context);
        $transactionStatus = $this->getTransactionStatus($context);
        return [
            'orderStatus' => $orderStatus,
            'deliveryStatus' => $deliveryStatus,
            'transactionStatus' => $transactionStatus
        ];
    }

    public function getOrderStatus($context): array
    {
        $orderStatus = [];
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', 'order.state'));
        $criteria->addAssociation('stateMachine');
        $orderStatusElements = $this->stateMachineRepository->search($criteria, $context)->getElements();
        foreach ($orderStatusElements as $orderStatusElement) {
            $orderStatus[$orderStatusElement->getTechnicalName()] = $orderStatusElement->getName();
        }
        return $orderStatus;
    }

    public function getDeliveryStatus($context): array
    {
        $deliveryStatus = [];
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', 'order_delivery.state'));
        $criteria->addAssociation('stateMachine');
        $deliveryStatusElements = $this->stateMachineRepository->search($criteria, $context)->getElements();
        foreach ($deliveryStatusElements as $deliveryStatusElement) {
            $deliveryStatus[$deliveryStatusElement->getTechnicalName()] = $deliveryStatusElement->getName();
        }
        return $deliveryStatus;
    }

    public function getTransactionStatus($context): array
    {
        $transactionStatus = [];
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', 'order_transaction.state'));
        $criteria->addAssociation('stateMachine');
        $transactionStatusElements = $this->stateMachineRepository->search($criteria, $context)->getElements();
        foreach ($transactionStatusElements as $transactionStatusElement) {
            $transactionStatus[$transactionStatusElement->getTechnicalName()] = $transactionStatusElement->getName();
        }
        return $transactionStatus;
    }

    public function changeOrderStatus($order): array
    {
        $orderId = $order->getTransition()->getEntityId();
        $context = $this->getContext($orderId, $order->getContext());
        $order = $this->getOrder($orderId, $context);
        $orderNumber = $order->getOrderNumber();
        $orderId = $order->getId();
        $orderStatus = $order->getStateMachineState()->getName();
        $status = $order->getStateMachineState()->getTechnicalName();
        $customField = $order->getCustomFields();
        if (array_key_exists('shopware_odoo_order_status', $customField) && $customField['shopware_odoo_order_status'] === $status) {
            return [];
        }
        $key = 'order';
        $this->insertCustomFiledData($key, $orderId, $status, $context);
        return [
            'orderNumber' => $orderNumber,
            'orderId' => $orderId,
            'orderStatus' => $orderStatus,
            'status' => $status,
            'type' => 'order'
        ];
    }

    public function getContext(string $orderId, Context $context): Context
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();
        if (!$order instanceof OrderEntity) {
            throw OrderException::orderNotFound($orderId);
        }

        /** @var CashRoundingConfig $itemRounding */
        $itemRounding = $order->getItemRounding();
        $orderContext = new Context(
            $context->getSource(),
            $order->getRuleIds() ?? [],
            $order->getCurrencyId(),
            array_values(array_unique(array_merge([$order->getLanguageId()], $context->getLanguageIdChain()))),
            $context->getVersionId(),
            $order->getCurrencyFactor(),
            true,
            $order->getTaxStatus(),
            $itemRounding
        );

        $orderContext->addState(...$context->getStates());
        $orderContext->addExtensions($context->getExtensions());

        return $orderContext;
    }

    /**
     * @throws OrderException
     */
    public function getOrder(string $orderId, Context $context): OrderEntity
    {
        $orderCriteria = $this->getOrderCriteria($orderId);
        $order = $this->orderRepository
            ->search($orderCriteria, $context)
            ->first();

        if (!$order instanceof OrderEntity) {
            throw OrderException::orderNotFound($orderId);
        }
        return $order;
    }

    public function getOrderCriteria(string $orderId): Criteria
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer.salutation');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('language.locale');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('lineItems.downloads.media');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('addresses.country');
        $criteria->addAssociation('addresses.countryState');
        $criteria->addAssociation('tags');

        $event = new OrderStateChangeCriteriaEvent($orderId, $criteria);
        $this->eventDispatcher->dispatch($event);

        return $criteria;
    }

    public function insertCustomFiledData($key, $orderId, $status, $context): void
    {
        $data = [];
        $data['id'] = $orderId;
        if ($key === 'transaction') {
            $data['customFields'] = [
                'shopware_odoo_transaction_status' => $status
            ];
        }
        if ($key === 'delivery') {
            $data['customFields'] = [
                'shopware_odoo_delivery_status' => $status
            ];
        }
        if ($key === 'order') {
            $data['customFields'] = [
                'shopware_odoo_order_status' => $status
            ];
        }
        $this->orderRepository->upsert([$data], $context);
    }

    public function changeOrderTransactionStatus($orderData): array
    {
        $orderTransactionId = $orderData->getTransition()->getEntityId();
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('stateMachineState');
        $orderTransaction = $this->transactionRepository
            ->search($criteria, $orderData->getContext())
            ->first();
        $context = $this->getContext($orderTransaction->getOrderId(), $orderData->getContext());
        $order = $this->getOrder($orderTransaction->getOrderId(), $context);
        $this->dispatchEvent($orderData->getStateEventName(), $order, $context);
        $orderNumber = $order->getOrderNumber();
        $orderId = $order->getId();
        $orderStatus = $orderTransaction->getStateMachineState()->getName();
        $status = $orderTransaction->getStateMachineState()->getTechnicalName();
        $customField = $order->getCustomFields();
        if (array_key_exists('shopware_odoo_transaction_status', $customField) && $customField['shopware_odoo_transaction_status'] === $status) {
            return [];
        }
        $key = 'transaction';
        $this->insertCustomFiledData($key, $orderId, $status, $context);
        return [
            'orderNumber' => $orderNumber,
            'orderId' => $orderId,
            'orderStatus' => $orderStatus,
            'status' => $status,
            'type' => 'payment'
        ];
    }

    public function dispatchEvent(string $stateEventName, OrderEntity $order, Context $context): void
    {
        $this->eventDispatcher->dispatch(
            new OrderStateMachineStateChangeEvent($stateEventName, $order, $context),
            $stateEventName
        );
    }

    public function changeOrderDeliveryStatus($orderData, $context): array
    {
        $orderDeliveryId = $orderData->getTransition()->getEntityId();

        //getting order data
        $criteria = new Criteria([$orderDeliveryId]);
        $criteria->addAssociation('order.orderCustomer');
        $criteria->addAssociation('order.transactions');
        $criteria->addAssociation('stateMachineState');
        $orderDelivery = $this->orderDeliveryRepository->search($criteria, $context)->first();
        $deliveryStatus = $orderDelivery->getStateMachineState()->getName();
        $status = $orderDelivery->get('stateMachineState')->getTechnicalName();
        $key = 'delivery';
        $customField = $orderDelivery->getOrder()->customFields;
        if (isset($customField) && array_key_exists('shopware_odoo_delivery_status', $customField) && $customField['shopware_odoo_delivery_status'] === $status) {
            return [];
        }
        $orderId = $orderDelivery->getOrderId();
        $this->insertCustomFiledData($key, $orderId, $status, $context);
        if ($status !== 'returned') {
            return [];
        }
        return [
            'orderNumber' => $orderDelivery->getOrder()->getOrderNumber(),
            'orderId' => $orderDelivery->getOrderId(),
            'deliveryStatus' => $deliveryStatus,
            'status' => $status,
            'type' => 'delivery'
        ];
    }

    public function updateStatus($statusArray, $type, $context): array
    {
        $response = [];
        foreach ($statusArray as $status) {
            $shopwareOrderId = $status['shopware_order_id'];
            if ($type === 'delivery-status') {
                $odooDeliveryStatus = $status['delivery_state'];
                if (array_key_exists('return', $status) && $status['return']) {
                    if ($odooDeliveryStatus === 'done') {
                        $this->updateOrderStatus($shopwareOrderId, 'open', $context);
                        return $this->updateOrderStatus($shopwareOrderId, 'cancelled', $context);
                    }
                    return [
                        'type' => 'success',
                        'responseCode' => 200,
                        'deliveryStatus' => true,
                        'orderId' => $shopwareOrderId,
                    ];
                }
                $configShopwareStatusArray = $this->pluginConfig->fetchPluginConfigShopwareOrderDeliveryData($context);
                $configOdooStatusArray = $this->pluginConfig->fetchPluginConfigOdooOrderDeliveryData($context);
                $odooStatusKey = array_search($odooDeliveryStatus, $configOdooStatusArray);
                if ($odooStatusKey && array_key_exists($odooStatusKey, $configShopwareStatusArray)) {
                    $shopwareValue = $configShopwareStatusArray[$odooStatusKey];
                    $shopwareKey = array_search($shopwareValue, $configShopwareStatusArray);
                    $this->getDeliveryStatusId($shopwareKey, $context);
                    $response[] = $this->updateOrderDeliveryStatus($shopwareOrderId, $shopwareKey, $status, $context);
                } else {
                    if ($odooDeliveryStatus === 'assigned') {
                        $response = [
                            'type' => 'success',
                            'responseCode' => 200,
                            'deliveryStatus' => true,
                            'orderId' => $shopwareOrderId,
                        ];
                    }
                }
                return $response;
            }
            if ($type === 'order-status') {
                $odooOrderStatus = $status['sale_state'];
                $configShopwareOrderStatusArray = $this->pluginConfig->fetchPluginConfigShopwareOrderStatusData($context);
                $configOdooOrderStatusArray = $this->pluginConfig->fetchPluginConfigOdooOrderStatusData($context);
                $odooOrderStatusKey = array_search($odooOrderStatus, $configOdooOrderStatusArray);
                if (array_key_exists($odooOrderStatusKey, $configShopwareOrderStatusArray)) {
                    $shopwareValue = $configShopwareOrderStatusArray[$odooOrderStatusKey];
                    $shopwareKey = array_search($shopwareValue, $configShopwareOrderStatusArray);
                    $response[] = $this->updateOrderStatus($shopwareOrderId, $shopwareKey, $context);
                }
            }
        }
        return [
            'type' => 'success',
            'responseCode' => 200,
            'result' => $response
        ];
    }

    public function getDeliveryStatusId($shopwareValue, $context): string
    {
        if ($shopwareValue === 'cancel') {
            $shopwareValue = 'cancelled';
        }
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', $shopwareValue));
        $criteria->addAssociation('stateMachine');
        return $this->stateMachineRepository->search($criteria, $context)->first()->getId();
    }

    public function updateOrderDeliveryStatus($shopwareOrderId, $shopwareValue, $status, $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.stateMachineState');
        $criteria->addFilter(new EqualsFilter('orderId', $shopwareOrderId));
        $orderDelivery = $this->orderDeliveryRepository->search($criteria, $context)->first();
        if ($orderDelivery) {
            $transition = match ($shopwareValue) {
                'open' => 'reopen',
                'cancel' => 'cancel',
                'cancelled' => 'cancel',
                'shipped' => 'ship',
                'shipped_partially' => 'ship_partially',
                'returned' => 'retour',
                'returned_partially' => 'retour_partially',
                default => null
            };
            $stateMachineStates = $this->stateMachineRegistry->transition(
                new Transition(
                    'order_delivery',
                    $orderDelivery->getId(),
                    $transition,
                    'stateId'
                ),
                $context
            );
            $toPlace = $stateMachineStates->get('toPlace');
            if (!$toPlace) {
                throw StateMachineException::stateMachineStateNotFound('order_delivery', $transition);
            }
            $orderState = $orderDelivery->getOrder()->getStateMachineState();
            $orderTranisitionKey = $this->getOrderStatusChangeKey($shopwareValue, $context);
            if ($orderState->getTechnicalName() !== $orderTranisitionKey) {
                $orderTranisitionKey = $this->getOrderStatusChangeKey($shopwareValue, $context);
                $this->updateOrderStatus($shopwareOrderId, $orderTranisitionKey, $context);
                $data = [
                    'orderNumber' => $orderDelivery->getOrder()->getOrderNumber(),
                    'orderId' => $orderDelivery->getOrderId(),
                    'orderStatus' => $orderState->getName(),
                    'status' => $orderState->getTechnicalName(),
                    'type' => 'order'
                ];
                $this->odooClient->orderStatusChange($data, $context);
            }
            return [
                'type' => 'success',
                'responseCode' => 200,
                'deliveryStatus' => true,
                'orderId' => $shopwareOrderId,
            ];
        }
        return [
            'type' => 'error',
            'responseCode' => 400,
            'deliveryStatus' => false,
            'orderId' => $shopwareOrderId,
        ];
    }

    public function getOrderStatusChangeKey($shopwareValue, $context): string
    {
        $configShopwareOrderStatusArray = $this->pluginConfig->fetchPluginConfigShopwareOrderStatusData($context);
        return $configShopwareOrderStatusArray[$shopwareValue];
    }

    public function updateOrderStatus($shopwareOrderId, $shopwareKey, $context): array
    {
        $transition = match ($shopwareKey) {
            'open' => 'reopen',
            'cancel' => 'cancel',
            'cancelled' => 'cancel',
            'in_progress' => 'process',
            'completed' => 'complete',
            default => null
        };
        $stateMachineStates = $this->stateMachineRegistry->transition(
            new Transition(
                'order',
                $shopwareOrderId,
                $transition,
                'stateId'
            ),
            $context
        );
        $toPlace = $stateMachineStates->get('toPlace');
        if (!$toPlace) {
            throw StateMachineException::stateMachineStateNotFound('order', $transition);
        }
        return [
            'orderStatus' => true,
            'orderId' => $shopwareOrderId,
        ];
    }
}
