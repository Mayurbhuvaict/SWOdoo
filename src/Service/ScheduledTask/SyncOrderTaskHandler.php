<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use ICTECHOdooShopwareConnector\Controller\DefaultOrderSyncController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: SyncOrderTask::class)]
class SyncOrderTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        DefaultOrderSyncController $defaultOrderSyncController,
    ) {
        $this->scheduledTaskRepository = $scheduledTaskRepository;
        $this->defaultOrderSyncController = $defaultOrderSyncController;
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $this->defaultOrderSyncController->fetchOdooOrderStatus($context);
    }
}
