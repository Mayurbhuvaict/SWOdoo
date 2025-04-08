<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Controller;

use ICTECHOdooShopwareConnector\Service\CategoryCreate;
use ICTECHOdooShopwareConnector\Service\CurrencyCreate;
use ICTECHOdooShopwareConnector\Service\InvoiceCreate;
use ICTECHOdooShopwareConnector\Service\ManufacturerCreate;
use ICTECHOdooShopwareConnector\Service\OrderCreate;
use ICTECHOdooShopwareConnector\Service\OrderStatus;
use ICTECHOdooShopwareConnector\Service\ProductCreate;
use ICTECHOdooShopwareConnector\Service\ProductStockUpdate;
use ICTECHOdooShopwareConnector\Service\ProductUpdate;
use ICTECHOdooShopwareConnector\Service\RuleCreate;
use ICTECHOdooShopwareConnector\Service\TaxCreate;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route(defaults: ['_routeScope' => ['api']])]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly OrderStatus $orderStatus,
        private readonly ProductCreate $productCreate,
        private readonly CategoryCreate $categoryCreate,
        private readonly TaxCreate $taxCreate,
        private readonly CurrencyCreate $currencyCreate,
        private readonly RuleCreate $ruleCreate,
        private readonly ProductUpdate $productUpdate,
        private readonly ProductStockUpdate $productStockUpdate,
        private readonly InvoiceCreate $invoiceCreate,
        private readonly OrderCreate $orderCreate,
        private readonly ManufacturerCreate $manufacturerCreate,
    ) {
    }

    #[Route(path: '/api/product/odoo', name: 'api.action.product.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function productProductOdoo(Context $context, Request $request): JsonResponse
    {
        $productData = $request->get('product_info');
        $productResponse = $this->productCreate->getProductData($productData, $context);
        return new JsonResponse($productResponse);
    }

    #[Route(path: '/api/odoo/currency', name: 'api.action.odoo.currency', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function productCurrencyApi(Context $context, Request $request): JsonResponse
    {
        $currencyDataArray = $request->get('currency_info');
        $currencyResponse = $this->currencyCreate->getCurrencyData($currencyDataArray, $context);
        return new JsonResponse($currencyResponse);
    }

    #[Route(path: '/api/odoo/rule', name: 'api.action.odoo.rule', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function productRuleApi(Context $context, Request $request): JsonResponse
    {
        $ruleType = $request->get('rule_type');
        $ruleDataArray = $request->get('rule_info');
        $ruleDataResponse = $this->ruleCreate->getRuleData($ruleType, $ruleDataArray, $context);
        return new JsonResponse($ruleDataResponse);
    }

    #[Route(path: '/api/odoo/tax', name: 'api.action.odoo.tax', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function productTaxApi(Context $context, Request $request): JsonResponse
    {
        $taxDataArray = $request->get('tax_info');
        $taxDataResponse = $this->taxCreate->taxDataGenerate($taxDataArray, $context);
        return new JsonResponse($taxDataResponse);
    }

    #[Route(path: '/api/manufacturer/odoo', name: 'api.action.manufacturer.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function manufacturerApi(Context $context, Request $request): JsonResponse
    {
        $manufacturerDataArray = $request->get('brand_info');
        $manufacturerResponse = $this->manufacturerCreate->manufacturerDataGenerate($manufacturerDataArray, $context);
        return new JsonResponse($manufacturerResponse);
    }

    #[Route(path: '/api/order/status', name: 'api.action.order.status', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['GET'])]
    public function orderStatusApi(Context $context): JsonResponse
    {
        $orderStatus = $this->orderStatus->getAllStatus($context);
        return new JsonResponse($orderStatus);
    }

    #[Route(path: '/api/order/status/change/{type}', name: 'api.action.order.status.change', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function orderStatusChangeApi(Context $context, Request $request, string $type): JsonResponse
    {
        $statusArray = $request->get('status_info');
        $response = $this->orderStatus->updateStatus($statusArray, $type, $context);
        if ($response) {
            return new JsonResponse($response);
        }
        return new JsonResponse(['type' => 'Error', 'message' => 'Status is not updated']);
    }

    #[Route(path: '/api/order/update', name: 'api.action.order.update', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function updateOrder($updatedOrderData): JsonResponse
    {
        return new JsonResponse($updatedOrderData);
    }

    #[Route(path: '/api/category/odoo', name: 'api.action.category.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function categoryCreateData(Context $context, Request $request): JsonResponse
    {
        $catDataArray = $request->get('category_data');
        $categoryData = $this->categoryCreate->createCategoryData($catDataArray, $context);
        $responseData = [
            'type' => 'Success',
            'responseCode' => 200,
            'categoryData' => $categoryData
        ];
        return new JsonResponse($responseData);
    }

    #[Route(path: '/api/category/update/odoo', name: 'api.action.category.update.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function categoryUpdateData(Context $context, Request $request): JsonResponse
    {
        $updateCategoryData = $request->get('updated_category_info');
        $updateData = $this->categoryCreate->updateCategory($updateCategoryData, $context);
        return new JsonResponse($updateData);
    }

    #[Route(path: '/api/currency/odoo', name: 'api.action.currency.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['GET'])]
    public function currencyCreateData(Context $context): JsonResponse
    {
        $categoryData = $this->currencyCreate->getOdooCurrencyData($context);
        $responseData = [
            'type' => 'Success',
            'responseCode' => 200,
            'categoryData' => $categoryData
        ];
        return new JsonResponse($responseData);
    }

    #[Route(path: '/api/product/update/odoo', name: 'api.action.product.update.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function productUpdateOdoo(Context $context, Request $request): JsonResponse
    {
        $productResponse = $this->productUpdate->getUpdateProductData($context, $request);
        return new JsonResponse($productResponse);
    }

    #[Route(path: '/api/product/stock/update/odoo', name: 'api.action.product.stock.update.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function productStockUpdateOdoo(Context $context, Request $request): JsonResponse
    {
        $productStockResponse = $this->productStockUpdate->getUpdateProductStockData($request, $context);
        return new JsonResponse($productStockResponse);
    }

    #[Route(path: '/api/invoice/odoo', name: 'api.action.invoice.odoo', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function createInvoice(Context $context, Request $request): JsonResponse
    {
        $productStockResponse = $this->invoiceCreate->getInvoiceData($request, $context);
        return new JsonResponse($productStockResponse);
    }

    #[Route(path: '/api/order/sync/status', name: 'api.action.order.sync.status', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function orderSyncStatus(Context $context, Request $request): JsonResponse
    {
        $sync = $this->orderCreate->orderUpdateStatus($request, $context);
        return new JsonResponse($sync);
    }
}
