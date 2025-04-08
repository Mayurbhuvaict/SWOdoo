<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use ICTECHOdooShopwareConnector\Service\CurrencyCreate;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @property Client $client
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class DefaultCurrencyFetchController extends AbstractController
{
    public const END_POINT = 'shop/currency';
    public function __construct(
        private readonly CurrencyCreate $currencyCreate,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/currency/default/odoo', name: 'api.action.currency.default.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function currencyCreateDefaultData(Context $context): JsonResponse
    {
        $currencyId = 'getAllCurrencyData';
        $currencyResponse = $this->currencyCreate->getCurrencyData($currencyId, $context);
        if (!array_key_exists('responseCode', $currencyResponse)) {
            $responseData = [
                'type' => 'Success',
                'responseCode' => 200,
                'currencyData' => $currencyResponse
            ];
            $apiResponse = $this->sendResponse($responseData, $context);
            if ($apiResponse->getStatusCode() === 200) {
                return new JsonResponse($responseData);
            }
        }
        return new JsonResponse($currencyResponse);
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
