<?php

namespace App\Services;

use App\Models\Business;

class BusinessContext
{
    protected ?Business $business = null;

    public function setBusiness(Business $business): void
    {
        $this->business = $business;
    }

    public function getBusiness(): ?Business
    {
        return $this->business;
    }

    public function business(): Business
    {
        if (!$this->business) {
            throw new \RuntimeException('No business has been set for this request.');
        }

        return $this->business;
    }

    public function getBusinessId(): ?string
    {
        return $this->getBusiness()?->id;
    }

    public function clear(): void
    {
        $this->business = null;
    }

    public function exists(): bool
    {
        return $this->business !== null;
    }
}
