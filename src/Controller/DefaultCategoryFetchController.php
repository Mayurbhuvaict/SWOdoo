<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use ICTECHOdooShopwareConnector\Service\CategoryCreate;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route(defaults: ['_routeScope' => ['api']])]
class DefaultCategoryFetchController extends AbstractController
{
    public const END_POINT = 'shop/product_category';
    public function __construct(
        private readonly CategoryCreate $categoryCreate,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/category/default/odoo', name: 'api.action.category.default.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function categoryDefaultCreateData(Context $context): JsonResponse
    {
        $catId = 'getAllCatData';
        $categoryData = $this->categoryCreate->categoryInsert($catId, $context);
        if (!array_key_exists('responseCode', $categoryData)) {
            $responseData = [
                'type' => 'Success',
                'responseCode' => 200,
                'categoryData' => $categoryData
            ];
            $apiResponse = $this->sendResponse($responseData, $context);
            if ($apiResponse->getStatusCode() === 200) {
                return new JsonResponse($responseData);
            }
        }
        return new JsonResponse($categoryData);
    }

    /**
     * @throws GuzzleException
     */
    public function sendResponse($responseData, $context): ResponseInterface
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $apiUrl = $odooUrl . '/' . self::END_POINT;
        return $this->client->post(
            $apiUrl,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $responseData
            ]
        );
    }
}
