<?php

namespace ICTECHOdooShopwareConnector\Components\Config;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class PluginConfig
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository    $stateMachineRepository,
    )
    {
    }

    public function fetchPluginConfigUrlData(Context $context): string
    {
        $response = $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.webUrl');
        if ($response) {
            return $response;
        }
        return "null";
    }

    public function fetchPluginConfigShopwareOrderDeliveryData(Context $context): array
    {
        $deliveryStatusData = $this->getDeliveryStatus($context);
        $deliveryStatusDataKey = array_keys($deliveryStatusData);
        $deliveryStatusArray = [];
        foreach ($deliveryStatusDataKey as $deliveryStatus) {
            $deliveryStatusArray[$deliveryStatus] = $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.odooDeliveryOption-' . $deliveryStatus);
        }
        return $deliveryStatusArray;
    }

    public function getDeliveryStatus($context): array
    {
        $deliveryStatus = [];
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', 'order_delivery.state'));
        $criteria->addAssociation('stateMachine');
        $criteria->addAssociation('stateMachine.transitions');
        $deliveryStatusElements = $this->stateMachineRepository->search($criteria, $context)->getElements();
        foreach ($deliveryStatusElements as $deliveryStatusElement) {
            $deliveryStatus[$deliveryStatusElement->getTechnicalName()] = $deliveryStatusElement->getName();
        }
        return $deliveryStatus;
    }

    public function fetchPluginConfigOdooOrderDeliveryData(Context $context): array
    {
        $deliveryStatusData = $this->getDeliveryStatus($context);
        $deliveryStatusDataKey = array_keys($deliveryStatusData);
        $deliveryStatusArray = [];
        foreach ($deliveryStatusDataKey as $deliveryStatus) {
            $deliveryStatusArray[$deliveryStatus] = $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.odooDeliveryOption-' . $deliveryStatus);
        }
        return $deliveryStatusArray;
    }

    public function fetchPluginConfigShopwareOrderStatusData(Context $context): array
    {
        $deliveryStatusData = $this->getDeliveryStatus($context);
        $orderStatusDataKey = array_keys($deliveryStatusData);
        $orderStatusArray = [];
        foreach ($orderStatusDataKey as $orderStatus) {
            $orderStatusArray[$orderStatus] = $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.shopwareOrderStatus-' . $orderStatus);
        }
        return $orderStatusArray;
    }

    public function fetchPluginConfigOdooOrderStatusData(Context $context): array
    {
        return [
            'open' => $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.odooOrderStatusOpen'),
            'done' => $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.odooOrderStatusDone'),
            'inProgress' => $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.odooOrderStatusInProgress'),
            'cancel' => $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.odooOrderStatusCancel')
        ];
    }

    public function getDefaultSalesChannelId(): array
    {
        return $this->systemConfigService->get('core.defaultSalesChannel.salesChannel');
    }

    public function getProductSalesChannelId(): array
    {
        $salesChannelArray = [];
        $salesChannel = $this->systemConfigService->get('ICTECHOdooShopwareConnector.settings.productSalesChannel');
        if ($salesChannel) {
            return $salesChannel;
        }
        return $salesChannelArray;
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

    public function getOdooAccessToken(): string
    {
        $response = $this->systemConfigService->get('ICTECHOdooShopwareConnector.config.odooAuthToken');
        if ($response) {
            return $response;
        }
        return "null";
    }
}
