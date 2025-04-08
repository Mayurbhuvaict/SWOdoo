<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use ICTECHOdooShopwareConnector\Service\CustomerService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route(defaults: ['_routeScope' => ['api']])]
class DefaultCustomerController extends AbstractController
{
    public const MM_ORDER_CREATE_ENDPOINT = '/shop/create_sale_order_from_shopware';
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly CustomerService $customerService,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/pending/customer-sync', name: 'api.action.pending.customer-sync', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function fetchCustomerSyncData(Context $context): JsonResponse
    {
        $customerArray = [];
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        if ($odooUrl !== "null") {
            $customerData = $this->fetchNotSyncCustomerData($context);
            foreach ($customerData as $customer) {
                $customerDataArray = $this->customerService->generateCustomerPayload($customer, $context);
                $customerArray[] = $customerDataArray;
            }
            $apiDataResponse = $this->client->post(
                $odooUrl . self::MM_ORDER_CREATE_ENDPOINT,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => json_encode($customerArray)
                ]
            );
            return new JsonResponse($apiDataResponse);
        }
        return new JsonResponse([
            'type' => "Error",
            'responseCode' => 400,
        ]);
    }

    public function fetchNotSyncCustomerData($context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('defaultPaymentMethod');
        $criteria->addAssociation('salutation');
        $criteria->addAssociation('language');
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('activeBillingAddress');
        $criteria->addAssociation('activeBillingAddress.country');
        $criteria->addAssociation('defaultShippingAddress');
        $criteria->addAssociation('defaultShippingAddress.country');
        $criteria->addAssociation('activeShippingAddress');
        $criteria->addAssociation('activeShippingAddress.country');
        $criteria->addFilter(new EqualsFilter('customFields.odoo_customer_sync_status', 'false'));
        return $this->customerRepository->search($criteria, $context)->getElements();
    }
}
