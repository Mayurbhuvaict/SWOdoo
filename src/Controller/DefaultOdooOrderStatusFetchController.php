<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route(defaults: ['_routeScope' => ['api']])]
class DefaultOdooOrderStatusFetchController extends AbstractController
{
    public const END_POINT = '/odoo/states';
    public function __construct(
        private readonly EntityRepository $odooOrderStatusRepository,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/odoo/order/status', name: 'api.action.odoo.order.status', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function fetchOdooOrderStatus(Context $context): JsonResponse
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        if ($odooUrl !== "null") {
            $apiUrl = $odooUrl . self::END_POINT;
            $apiResponseData = $this->getOrderStatusAPIData($apiUrl);
            if ($apiResponseData->result->success && $apiResponseData->result->code === 200) {
                $orderStatusArray = $apiResponseData->result->data;
                $storeData = $this->createOrderDataArray($orderStatusArray, $context);
                $this->odooOrderStatusRepository->upsert($storeData, $context);
                $responseData = [
                    'type' => 'Success',
                    'responseCode' => 200,
                    'orderStatusData' => $storeData
                ];
                return new JsonResponse($responseData);
            }
        }
        return new JsonResponse([
            'type' => "Error",
            'responseCode' => 400,
        ]);
    }

    public function getOrderStatusAPIData($apiUrl)
    {
        $apiResponse = $this->client->get(
            $apiUrl,
            [
                'headers' => ['Content-Type' => 'application/json'],
            ]
        );
        return json_decode($apiResponse->getBody()->getContents());
    }

    public function createOrderDataArray($orderStatusArray, $context): array
    {
        $apiDataArray = $orderStatusArray->odoo_states;
        $orderStatus = [];
        foreach ($apiDataArray as $statusType => $statusValue) {
            foreach ($statusValue as $statusNameKey => $statusNameValue) {
                $existData = $this->checkExistStatus($statusNameKey, $context);
                $orderStatus[] = [
                    'id' => $existData ? $existData->getId() : Uuid::randomHex(),
                    'odooStatusType' => $statusType,
                    'odooStatusKey' => $statusNameKey,
                    'odooStatus' => $statusNameValue,
                ];
            }
        }
        return $orderStatus;
    }

    public function checkExistStatus($statusNameKey, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('odooStatusKey', $statusNameKey));
        return $this->odooOrderStatusRepository->search($criteria, $context)->first();
    }
}
