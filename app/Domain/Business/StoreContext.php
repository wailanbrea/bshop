<?php

namespace App\Domain\Business;

use App\Models\Store;
use LogicException;

final class StoreContext
{
    private ?Store $store = null;

    public function useStore(Store $store): void
    {
        $this->store = $store;
    }

    public function store(): Store
    {
        return $this->store ?? throw new LogicException('An authorized store is required.');
    }

    public function clear(): void
    {
        $this->store = null;
    }
}
