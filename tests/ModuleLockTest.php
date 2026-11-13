<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Sync\ModuleLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/TestLocks.php';

/**
 * Дії, які не можна виконувати двічі одночасно: друге натискання «Створити джерело» створило б друге джерело
 * в Google, а ручне «Оновити статуси» разом із кроном задублювало б проблеми товарів.
 */
class ModuleLockTest extends TestCase
{
    public function testBusyLockSkipsTheWork(): void
    {
        $ran = false;

        $this->assertFalse(TestLocks::busy()->run(ModuleLock::STATUS_POLL, function () use (&$ran) { $ran = true; }));
        $this->assertFalse($ran);
    }

    public function testLockIsReleasedEvenWhenWorkFails(): void
    {
        $lock = TestLocks::free();

        try {
            $lock->run(ModuleLock::CREATE_SOURCE, fn() => throw new RuntimeException('google down'));
        } catch (RuntimeException) {
        }

        $this->assertSame([ModuleLock::CREATE_SOURCE], $lock->taken);
        $this->assertSame([ModuleLock::CREATE_SOURCE], $lock->released);
    }

    public function testFreeLockRunsTheWork(): void
    {
        $this->assertTrue(TestLocks::free()->run(ModuleLock::STATUS_POLL, fn() => null));
    }
}
