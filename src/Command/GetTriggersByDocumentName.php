<?php declare(strict_types=1);

namespace MateuszMesek\DocumentDataIndexerMview\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\DB\Ddl\TriggerFactory;
use Magento\Framework\ObjectManagerInterface;
use MateuszMesek\DocumentDataIndexerMviewApi\Command\GetNodeSubscriptionsByDocumentNameInterface;
use MateuszMesek\DocumentDataIndexerMviewApi\Command\GetTriggersByDocumentNameInterface;
use Traversable;

class GetTriggersByDocumentName implements GetTriggersByDocumentNameInterface
{
    private GetNodeSubscriptionsByDocumentNameInterface $getNodeSubscriptionsByDocumentName;
    private TriggerFactory $triggerFactory;
    private ObjectManagerInterface $objectManager;
    private ResourceConnection $resourceConnection;

    public function __construct(
        GetNodeSubscriptionsByDocumentNameInterface $getNodeSubscriptionsByDocumentName,
        TriggerFactory $triggerFactory,
        ObjectManagerInterface $objectManager,
        ResourceConnection $resourceConnection
    )
    {
        $this->getNodeSubscriptionsByDocumentName = $getNodeSubscriptionsByDocumentName;
        $this->triggerFactory = $triggerFactory;
        $this->objectManager = $objectManager;
        $this->resourceConnection = $resourceConnection;
    }

    public function execute(string $documentName): Traversable
    {
        $subscriptions = $this->getNodeSubscriptionsByDocumentName->execute($documentName);
        $changelogTable = $this->resourceConnection->getTableName("document_data_{$documentName}_mview");

        $connection = $this->resourceConnection->getConnection();

        $triggers = [];

        foreach ($subscriptions as $path => $pathSubscriptions) {
            foreach ($pathSubscriptions as $pathSubscription) {
                ['type' => $type, 'arguments' => $arguments] = $pathSubscription;

                $generator = $this->objectManager->get($type);

                /** @var \MateuszMesek\DocumentDataIndexerMviewApi\Data\SubscriptionInterface[] $subscriptionItems */
                $subscriptionItems = call_user_func_array([$generator, 'generate'], $arguments);

                foreach ($subscriptionItems as $subscriptionItem) {
                    $triggerName = $this->resourceConnection->getTriggerName(
                        "document_data_{$subscriptionItem->getTableName()}",
                        $subscriptionItem->getTriggerTime(),
                        $subscriptionItem->getTriggerEvent()
                    );

                    if (!isset($triggers[$triggerName])) {
                        $triggers[$triggerName] = ($this->triggerFactory->create())
                            ->setName($triggerName)
                            ->setTable($this->resourceConnection->getTableName($subscriptionItem->getTableName()))
                            ->setTime($subscriptionItem->getTriggerTime())
                            ->setEvent($subscriptionItem->getTriggerEvent());
                    }

                    $statement = sprintf(
                        <<<SQL
                            SET @documentId = {$subscriptionItem->getDocumentId()};
                            SET @nodePath = %1s;
                            SET @dimensions = {$subscriptionItem->getDimensions()};
                            INSERT INTO %2s (`document_id`, `node_path`, `dimensions`) SELECT @documentId, @nodePath, @dimensions WHERE @documentId IS NOT NULL;
                        SQL,
                        $connection->quote($path),
                        $connection->quoteIdentifier($changelogTable)
                    );

                    if ($condition = $subscriptionItem->getCondition()) {
                        $statement = <<<SQL
                            IF $condition THEN $statement END IF;
                        SQL;
                    }

                    if ($triggers[$triggerName]->getEvent() === Trigger::EVENT_UPDATE) {
                        $columns = $connection->describeTable($triggers[$triggerName]->getTable());

                        $conditions = [];

                        foreach ($columns as $columnData) {
                            ['COLUMN_NAME' => $columnName] = $columnData;

                            if ($columnName === 'updated_at') {
                                continue;
                            }

                            $conditions[] = <<<SQL
                                (NEW.$columnName != OLD.$columnName)
                            SQL;
                        }

                        $condition = implode(' OR ', $conditions);

                        $statement = <<<SQL
                            IF $condition THEN $statement END IF;
                        SQL;
                    }

                    $triggers[$triggerName]->addStatement($statement);
                }
            }
        }

        yield from $triggers;
    }
}
