<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

#[Route(defaults: ['_routeScope' => ['api']])]
class CheckAuthController extends AbstractController
{
    public const END_POINT = '/odoo/auth';
    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly SystemConfigService $systemConfigService
    ) {
        $this->client = new Client();
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/check/web-url-credential', name: 'api.action.check.web.url.credential', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function checkCredential(Context $context): JsonResponse
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        if ($odooUrl !== 'null') {
            $apiUrl = $odooUrl . self::END_POINT;
            $apiResponseData = $this->checkApiAuthentication($apiUrl);
            if ($apiResponseData !== true) {
                $responseData = [
                    'type' => $apiResponseData->result->success,
                    'responseCode' => $apiResponseData->result->code,
                    'message' => $apiResponseData->result->message
                ];
                return new JsonResponse($responseData);
            }
        }
        return new JsonResponse([
            'type' => 'Error',
            'responseCode' => 400,
        ]);
    }

    #[Route(path: '/api/sw/authToken', name: 'api.action.sw.authToken', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function odooAuthToken(Request $request): JsonResponse
    {
        $authTokenInfo = $request->get('instance_token_info');
        if ($authTokenInfo && array_key_exists('odoo_token', $authTokenInfo) && $authTokenInfo['odoo_token'] !== null) {
            try {
                $this->systemConfigService->set('ICTECHOdooShopwareConnector.config.odooAuthToken', $authTokenInfo['odoo_token']);
                return new JsonResponse([
                    'status' => 'success',
                    'code' => 200,
                    'message' => 'Auth token saved successfully.'
                ]);
            } catch (Exception $e) {
                return new JsonResponse([
                    'status' => 'error',
                    'code' => $e->getCode() ?? 400,
                    'message' => 'Failed to save auth token. ' . $e->getMessage()
                ]);
            }
        } else {
            return new JsonResponse([
                'status' => 'error',
                'code' => 400,
                'message' => 'Invalid or missing auth token.'
            ]);
        }
    }

    public function checkApiAuthentication($apiUrl)
    {
        $apiResponse = $this->client->get(
            $apiUrl,
            [
                'headers' => ['Content-Type' => 'application/json'],
            ],
        );
        return json_decode($apiResponse->getBody()->getContents());
    }
}
