<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use ICTECHOdooShopwareConnector\Controller\DefaultCustomerController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: SyncCustomerTask::class)]
class SyncCustomerTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        DefaultCustomerController $defaultCustomerController,
    ) {
        $this->scheduledTaskRepository = $scheduledTaskRepository;
        $this->defaultCustomerController = $defaultCustomerController;
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $this->defaultCustomerController->fetchCustomerSyncData($context);
    }
}
