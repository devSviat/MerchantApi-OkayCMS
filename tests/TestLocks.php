<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Sync\ModuleLock;

/** Блокування без бази: вільне або зайняте іншим процесом. */
final class TestLocks extends ModuleLock
{
    /** @var list<string> */
    public array $taken = [];
    /** @var list<string> */
    public array $released = [];

    public function __construct(private bool $busy = false)
    {
    }

    public static function free(): self
    {
        return new self();
    }

    public static function busy(): self
    {
        return new self(true);
    }

    protected function acquire(string $name): bool
    {
        if ($this->busy) {
            return false;
        }
        $this->taken[] = $name;

        return true;
    }

    protected function release(string $name): void
    {
        $this->released[] = $name;
    }
}
