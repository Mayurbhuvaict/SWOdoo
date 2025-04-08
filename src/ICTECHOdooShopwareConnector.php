<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector;

use Doctrine\DBAL\Connection;
use ICTECHOdooShopwareConnector\Service\Installer\CustomFieldsInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Uuid\Uuid;

class ICTECHOdooShopwareConnector extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $categoryRepository = $this->container->get('category.repository');
        $category = $categoryRepository->search(
            (new Criteria())
                ->addFilter(new EqualsFilter('customFields.odoo_category_id', 0)),
            $installContext->getContext())->getIds();
        if (!$category) {
            $categoryData = [
                'id' => Uuid::randomHex(),
                'name' => "Shopware Odoo",
                'active' => true,
                'customFields' => ['odoo_category_id' => 0],
            ];
            $categoryRepository->create([$categoryData], $installContext->getContext());
        }
        $this->getCustomFieldsInstaller()->install($installContext->getContext());
    }



    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if ($uninstallContext->keepUserData()) {
            return;
        }
        $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement(
            'DELETE FROM system_config WHERE configuration_key LIKE :domain',
            [
                'domain' => '%ICTECHOdooShopwareConnector.settings%',
            ]
        );
        $connection->executeStatement('DROP TABLE IF EXISTS `odoo_order_status`');
    }

    private function getCustomFieldsInstaller(): CustomFieldsInstaller
    {
        if ($this->container->has(CustomFieldsInstaller::class)) {
            return $this->container->get(CustomFieldsInstaller::class);
        }

        return new CustomFieldsInstaller(
            $this->container->get('custom_field_set.repository')
        );
    }
}
