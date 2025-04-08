<?php

namespace ICTECHOdooShopwareConnector\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class OrderCreate
{
    public function __construct(
        private readonly EntityRepository $addressRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $orderRepository,
    ) {
    }

    public function generateOrderPayload($order, $context): array
    {
        $billingAddress = $this->getBillingAddress($order, $context);
        $shippingAddress = $this->getShippingAddress($order, $context);
        $customerData = $this->getCustomerData($order);
        $orderData = $this->getOrderData($order, $context);
        $promotionData = $this->getPromotionData($order);
        $deliveryData = $this->getDelivery($order);
        $status = $this->getStatus($order);
        return [
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
            'customerData' => $customerData,
            'orderData' => $orderData,
            'promotionData' => $promotionData,
            'deliveryData' => $deliveryData,
            'status' => $status
        ];
    }

    public function getBillingAddress($order, $context): array
    {
        $defaultBillingAddressId = $order->getBillingAddressId();
        $getBillingAddressInfo = $this->getBillingAddressData($defaultBillingAddressId, $context);
        return $this->getAddress($getBillingAddressInfo);
    }

    public function getAddress($address): array
    {
        return [
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'street' => $address->getStreet(),
            'zip' => $address->getZipcode(),
            'city' => $address->getCity(),
            'company' => $address->getCompany(),
            'department' => $address->getDepartment(),
            'title' => $address->getTitle() !== null ? $address->getTitle() : '',
            'country' => $address->getCountry()->getName(),
            'state' => $address->getCountryStateId() ? $address->getCountryState()->getName() : null,
            'phoneNumber' => $address->getPhoneNumber(),
            'additionalAddressLine1' => $address->getAdditionalAddressLine1(),
            'additionalAddressLine2' => $address->getAdditionalAddressLine2(),
        ];
    }

    public function createOrder($order, $context): array
    {
        $orderPayload = $this->generateOrderPayload($order, $context);
        return [
            'orderPayload' => $orderPayload,
        ];
    }

    public function getBillingAddressData($defaultBillingAddressId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');
        $criteria->addFilter(new EqualsFilter('id', $defaultBillingAddressId));
        return $this->addressRepository->search($criteria, $context)->first();
    }

    public function getShippingAddress($order, $context): array
    {
        $shippingMethodId = $order->getDeliveries()->first()->getShippingOrderAddressId();
        $getShippingAddressInfo = $this->getShippingAddressData($shippingMethodId, $context);
        return $this->getAddress($getShippingAddressInfo);
    }

    public function getShippingAddressData($shippingMethodId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('shippingOrderAddress');
        $criteria->addAssociation('country');
        $criteria->addAssociation('country.states');
        $criteria->addAssociation('countryState');
        $criteria->addFilter(new EqualsFilter('id', $shippingMethodId));
        return $this->addressRepository->search($criteria, $context)->first();
    }

    public function getCustomerData($order): array
    {
        $orderCustomer = $order->getOrderCustomer();
        return [
            'shopwareCustomerId' => $orderCustomer->getCustomerId(),
            'firstName' => $orderCustomer->getFirstName(),
            'lastName' => $orderCustomer->getLastName(),
            'customerNumber' => $orderCustomer->getCustomerNumber(),
            'email' => $orderCustomer->getEmail(),
        ];
    }

    public function getOrderData($order, $context): array
    {
        $orderLineItemData = $order->getLineItems()->getElements();
        $lineItemData = [];
        foreach ($orderLineItemData as $orderData) {
            if ($orderData->getType() === 'product') {
                $productPrice = $orderData->getPrice()->getcalculatedTaxes()->first();
                $odooId = $this->getProductData($orderData, $context);
                if (isset($odooId->customFields) && array_key_exists('odoo_product_id', $odooId->customFields)) {
                    $productCustomField = $odooId->customFields['odoo_product_id'];
                } else {
                    $productCustomField = '';
                }
                $lineItemData[] = [
                    'odooId' => $productCustomField,
                    'shopwareProductId' => $orderData->getProductId(),
                    'unitPrice' => $orderData->getUnitPrice(),
                    'quantity' => $orderData->getQuantity(),
                    'totalPrice' => $orderData->getTotalPrice(),
                    'excludeTax' => round($orderData->getTotalPrice() - ($orderData->getTotalPrice() / (100 + $productPrice->getTaxRate()) * 100), 2),
                    'priceCalculation' => [
                        'shopwareTaxId' => $orderData->getPayload()['taxId'],
                        'taxRate' => $productPrice->getTaxRate(),
                        'taxPrice' => $productPrice->getTax(),
                        'totalPrice' => $productPrice->getPrice()
                    ],
                ];
            }
             
        }
        return [
            'shopwareOrderId' => $order->getId(),
            'shopwareCurrencyId' => $order->getCurrency()->getId(),
            'amountTotal' => $order->getAmountTotal(),
            'currency' => $order->getCurrency()->getIsoCode(),
            'orderNumber' => $order->getOrderNumber(),
            'orderDate' => date_format($order->getOrderDateTime(), 'Y-m-d H:i:s'),
            'shippingTotal' => $order->getShippingTotal(),
            'lineItems' => $lineItemData,
        ];
    }

    public function getProductData($orderData, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $orderData->getProductId()));
        return $this->productRepository->search($criteria, $context)->first();
    }

    public function getPromotionData($order): array
    {
        $promotionData = [];
        $orderLineItemData = $order->getLineItems()->getElements();
        foreach ($orderLineItemData as $orderData) {
            if ($orderData->getType() === 'promotion') {
                $promotionData[] = [
                    'orderId' => $orderData->getOrderId(),
                    'shopwarePromotionId' => $orderData->getPromotionId(),
                    'unitPrice' => abs($orderData->getUnitPrice()),
                    'totalPrice' => abs($orderData->getTotalPrice()),
                ];
            }
        }
        return $promotionData;
    }

    public function getDelivery($order): array
    {
        $orderLineItemData = $order->getDeliveries()->first();
        $deliveryPrice = $orderLineItemData->getShippingCosts()->getUnitPrice();
        $deliveryData = [
            'shopwareDeliveryId' => $orderLineItemData->getShippingMethod()->getId(),
            'name' => $orderLineItemData->getShippingMethod()->getName(),
            'deliveryTotal' => $deliveryPrice,

        ];
        return [
            'delivery' => $deliveryData,
        ];
    }

    public function getStatus($order): array
    {
        return [
            'orderStatus' => $order->getStateMachineState()->getName(),
            'paymentStatus' => [
                'method' => $order->getTransactions()->last()->getPaymentMethod()->getName(),
                'type' => $order->getTransactions()->last()->getPaymentMethod()->getTechnicalName() === 'payment_cashpayment' ? 'COD' : 'ONLINE',
                'status' => $order->getStateMachineState()->getName()
            ],
            'deliveryStatus' => [
                'method' => $order->getDeliveries()->last()->getShippingMethod()->getName(),
                'status' => $order->getDeliveries()->last()->getStateMachineState()->getName()
            ]
        ];
    }

    public function createOrderSyncStatus($order, $context): void
    {
        $orderData = [
            'id' => $order->getId(),
            'customFields' => [
                'odoo_order_id' => null,
                'odoo_order_sync_status' => 'false',
                'shopware_odoo_delivery_status' => null,
                'shopware_odoo_transaction_status' => null,
                'shopware_odoo_order_status' => null,
            ]
        ];
        $this->orderRepository->upsert([$orderData], $context);
    }

    public function orderUpdateStatus($request, $context): bool
    {
        $syncResponse = json_decode($request->getContent(), 'json');
        if (array_key_exists('order_sync_info', $syncResponse)) {
            $orderSyncInfo = $syncResponse['order_sync_info'];
            foreach ($orderSyncInfo as $sync) {
                $orderData = $this->fetchOrderData($sync, $context);

                $data = [
                    'id' => $sync['shopware_order_id'],
                    'billingAddressId' => $orderData->getBillingAddressId(),
                    'currencyId' => $orderData->getCurrencyId(),
                    'salesChannelId' => $orderData->getSalesChannelId(),
                    'languageId' => $orderData->getLanguageId(),
                    'orderDateTime' => date_format($orderData->getOrderDateTime(), 'Y-m-d H:i:s'),
                    'currencyFactor' => $orderData->getCurrencyFactor(),
                    'stateId' => $orderData->getStateId(),
                    'itemRounding' => [
                        'decimals' => $orderData->getItemRounding()->getDecimals(),
                        'interval' => $orderData->getItemRounding()->getInterval(),
                        'roundForNet' => true,
                    ],
                    'totalRounding' => [
                        'decimals' => $orderData->getTotalRounding()->getDecimals(),
                        'interval' => $orderData->getTotalRounding()->getInterval(),
                        'roundForNet' => true,
                    ],
                    'customFields' => [
                        'odoo_order_id' => $sync['odoo_id'],
                        'odoo_order_sync_status' => true,
                    ]
                ];
                $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($data): void {
                    $this->orderRepository->upsert([$data], $context);
                });
            }
            return true;
        }
        return false;
    }

    public function fetchOrderData($sync, $context): ? Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $sync['shopware_order_id']));
        return $this->orderRepository->search($criteria, $context)->first();
    }
}
