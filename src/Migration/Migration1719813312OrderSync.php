<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('core')]
class Migration1719813312OrderSync extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1719813312;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `odoo_order_status` (
            `id` BINARY(16) NOT NULL,
            `odoo_status_type` VARCHAR(255) NOT NULL,
            `odoo_status_key` VARCHAR(255) NOT NULL,
            `odoo_status` VARCHAR(255) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }
}
