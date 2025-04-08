<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use Exception;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class OdooDataSyncController extends AbstractController
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
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

    #[Route(path: '/api/sw/sync/categories', name: 'api.action.sw.sync.categories', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncCategoryInfo(Request $request): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }

    #[Route(path: '/api/sw/sync/tax', name: 'api.action.sw.sync.tax', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncTaxInfo(Request $request): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }

    #[Route(path: '/api/sw/sync/sales-channels', name: 'api.action.sw.sync.sales-channels', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncSalesChannelsInfo(Request $request): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }

    #[Route(path: '/api/sw/sync/shipping', name: 'api.action.sw.sync.shipping', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncShippingInfo(Request $request): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }

    #[Route(path: '/api/sw/sync/currencies', name: 'api.action.sw.sync.currencies', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncCurrenciesInfo(Request $request): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }

    #[Route(path: '/api/sw/sync/delivery-times', name: 'api.action.sw.sync.delivery-times', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncDeliveryTimesInfo(Request $request): JsonResponse
    {
        dd($request);
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }

    #[Route(path: '/api/sw/sync/languages', name: 'api.action.sw.sync.languages', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncLanguagesInfo(Request $request): JsonResponse
    {
        dd($request);
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }

    #[Route(path: '/api/sw/sync/customer-groups', name: 'api.action.sw.sync.customer-groups', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function syncCustomerGroupsInfo(Request $request): JsonResponse
    {
        dd($request);
        return new JsonResponse([
            'status' => 'error',
            'code' => 400,
            'message' => 'Invalid or missing auth token.'
        ]);
    }
}