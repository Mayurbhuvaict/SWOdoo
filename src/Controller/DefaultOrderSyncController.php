<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use GuzzleHttp\Client;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use ICTECHOdooShopwareConnector\Service\OrderCreate;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route(defaults: ['_routeScope' => ['api']])]
class DefaultOrderSyncController extends AbstractController
{
    public const MM_ORDER_CREATE_ENDPOINT = '/shop/create_sale_order_from_shopware';

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly orderCreate $orderCreate,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    #[Route(path: '/api/pending/order-sync', name: 'api.action.pending.order-sync', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function fetchOdooOrderStatus(Context $context): JsonResponse
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $orderArray = [];
        if ($odooUrl !== "null") {
            $orderData = $this->fetchNotSyncOrderData($context);
            foreach ($orderData as $orderInfo) {
                $orderDataArray = $this->orderCreate->createOrder($orderInfo, $context);
                $orderArray[] = $orderDataArray;
            }
            $apiDataResponse = $this->client->post(
                $odooUrl . self::MM_ORDER_CREATE_ENDPOINT,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $orderArray
                ]
            );
            return new JsonResponse($apiDataResponse);
        }
        return new JsonResponse([
            'type' => "Error",
            'responseCode' => 400,
        ]);
    }

    public function fetchNotSyncOrderData($context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('defaultPaymentMethod');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.stateMachineState');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('lineItems.product');
        $criteria->addAssociation('language');
        $criteria->addAssociation('shippingMethod');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addFilter(new EqualsFilter('customFields.odoo_order_sync_status', 'false'));
        return $this->orderRepository->search($criteria, $context)->getElements();
    }
}
