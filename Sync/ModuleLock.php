<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use Okay\Core\Database;
use Okay\Core\QueryFactory;

/**
 * Дії, які не можна виконувати двічі одночасно. GET_LOCK у MySQL, а не flock: крон і адмінка можуть
 * працювати в різних контейнерах, а база в них спільна. Блокування знімається й тоді, коли процес упав.
 */
class ModuleLock
{
    /** «Оновити статуси» й нічне опитування: обидва замінюють таблицю проблем, і разом дали б дублікати. */
    public const string STATUS_POLL = 'status_poll';
    /** Створення джерела в Google не ідемпотентне: друге натискання створило б друге джерело. */
    public const string CREATE_SOURCE = 'create_source';

    public function __construct(private Database $db, private QueryFactory $queryFactory)
    {
    }

    /** @return bool false — дію вже виконує інший процес, $work не запускався */
    public function run(string $name, callable $work): bool
    {
        if (!$this->acquire($name)) {
            return false;
        }
        try {
            $work();
        } finally {
            $this->release($name);
        }

        return true;
    }

    protected function acquire(string $name): bool
    {
        return (int) $this->query('GET_LOCK(' . self::lockName($name) . ', 0)') === 1;
    }

    protected function release(string $name): void
    {
        $this->query('RELEASE_LOCK(' . self::lockName($name) . ')');
    }

    /** Ім'я з базою: кілька магазинів на одному MySQL не мають блокувати один одного. */
    private static function lockName(string $name): string
    {
        return "MD5(CONCAT(DATABASE(), '.sviat_merchant_api.', " . self::quote($name) . '))';
    }

    private static function quote(string $name): string
    {
        return "'" . preg_replace('~[^a-z_]~', '', $name) . "'";
    }

    private function query(string $expression): mixed
    {
        $sql = $this->queryFactory->newSqlQuery();
        $sql->setStatement('SELECT ' . $expression . ' AS locked');

        return $this->db->query($sql) === true ? $this->db->result('locked') : null;
    }
}
