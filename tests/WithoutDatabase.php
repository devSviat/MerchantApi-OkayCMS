<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Core\Database;
use Okay\Core\ServiceLocator;

/**
 * QueryFactory\AbstractQuery бере Database із ServiceLocator прямо в конструкторі, тож навіть побудова SQL без
 * виконання відкриває з'єднання. Локально база є, у CI модуля — ні; тест підміняє Database заглушкою.
 */
trait WithoutDatabase
{
    private mixed $realDatabase = null;

    protected function setUp(): void
    {
        $db = $this->createStub(Database::class);
        // Деструктор Database безумовно кличе $this->pdo->disconnect(), а конструктор вимкнено.
        new \ReflectionProperty(Database::class, 'pdo')->setValue($db, new class {
            public function disconnect(): void
            {
            }
        });
        $this->swapDatabase($db);
    }

    protected function tearDown(): void
    {
        $this->swapDatabase($this->realDatabase);
    }

    private function swapDatabase(mixed $db): void
    {
        $container = new \ReflectionProperty(ServiceLocator::class, 'DI')->getValue(ServiceLocator::getInstance());
        $store = new \ReflectionProperty($container, 'serviceStore');
        $services = $store->getValue($container);
        if ($db !== null && $this->realDatabase === null) {
            $this->realDatabase = $services[Database::class] ?? null;
        }
        if ($db === null) {
            unset($services[Database::class]);
        } else {
            $services[Database::class] = $db;
        }
        $store->setValue($container, $services);
    }
}
