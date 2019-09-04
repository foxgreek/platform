<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Test\DataAbstractionLayer\Search;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntitySearcher;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Event\TestDefinition;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Search\Definition\GroupByDefinition;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

class GroupByTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var EntitySearcher
     */
    protected $searcher;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var EntityWriter
     */
    private $writer;

    /**
     * @var GroupByDefinition
     */
    private $definition;

    /**
     * @var TestData
     */
    private $testData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->searcher = $this->getContainer()->get(EntitySearcherInterface::class);
        $this->connection = $this->getContainer()->get(Connection::class);
        $this->writer = $this->getContainer()->get(EntityWriter::class);

        $this->connection->executeUpdate('
            DROP TABLE IF EXISTS group_by_test;
            
            CREATE TABLE `group_by_test` (
              `id` binary(16) NOT NULL,
              `name` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
              `field1` int(11) NOT NULL,
              `field2` int(11) NOT NULL,
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->definition = new GroupByDefinition();
        $this->definition->compile($this->getContainer()->get(DefinitionInstanceRegistry::class));
        $this->getContainer()->set(TestDefinition::class, $this->definition);

        $this->testData = new TestData();

        $data = [
            ['id' => $this->testData->create('1'), 'name' => 'Rot L', 'field1' => 1, 'field2' => 1],
            ['id' => $this->testData->create('2'), 'name' => 'Rot S', 'field1' => 1, 'field2' => 1],
            ['id' => $this->testData->create('3'), 'name' => 'Grün L', 'field1' => 2, 'field2' => 1],
            ['id' => $this->testData->create('4'), 'name' => 'Grün S', 'field1' => 2, 'field2' => 1],
            ['id' => $this->testData->create('5'), 'name' => 'Black', 'field1' => 1, 'field2' => 2],
            ['id' => $this->testData->create('6'), 'name' => 'White', 'field1' => 1, 'field2' => 2],
        ];

        $this->writer->insert($this->definition, $data, WriteContext::createFromContext(Context::createDefaultContext()));
    }

    public function testSingleGroupBy()
    {
        $criteria = new Criteria(
            $this->testData->getList(['1', '2', '3', '4'])
        );
        $criteria->addGroupField(new FieldGrouping('field1'));

        $ids = $this->searcher->search($this->definition, $criteria, Context::createDefaultContext());

        $this->assertGroupByExclusion(
            $this->testData->get('1'),
            $this->testData->get('2'),
            $ids
        );
        $this->assertGroupByExclusion(
            $this->testData->get('3'),
            $this->testData->get('4'),
            $ids
        );
    }

    public function testMultiGroupBy()
    {
        $criteria = new Criteria(
            $this->testData->getAll()
        );
        $criteria->addGroupField(new FieldGrouping('field1'));
        $criteria->addGroupField(new FieldGrouping('field2'));

        $ids = $this->searcher->search($this->definition, $criteria, Context::createDefaultContext());

        $this->assertGroupByExclusion(
            $this->testData->get('1'),
            $this->testData->get('2'),
            $ids
        );

        $this->assertGroupByExclusion(
            $this->testData->get('3'),
            $this->testData->get('4'),
            $ids
        );

        $this->assertGroupByExclusion(
            $this->testData->get('5'),
            $this->testData->get('6'),
            $ids
        );
    }

    private function assertGroupByExclusion(string $id1, string $id2, IdSearchResult $result)
    {
        static::assertTrue(
            $result->has($id1)
            || $result->has($id2)
        );

        if ($result->has($id1)) {
            static::assertFalse($result->has($id2));
        } else {
            static::assertFalse($result->has($id1));
        }
    }
}

class TestData
{
    /**
     * @var string[]
     */
    protected $ids = [];

    public function add(string $key, string $id)
    {
        $this->ids[$key] = $id;
    }

    public function get(string $key): string
    {
        return $this->ids[$key];
    }

    public function create(string $key): string
    {
        return $this->ids[$key] = Uuid::randomHex();
    }

    public function getList(array $keys): array
    {
        $keys = array_flip($keys);

        return array_intersect_key($this->ids, $keys);
    }

    public function getAll(): array
    {
        return $this->ids;
    }
}
