<?php

namespace App\Entity;

trait ActiveTrait
{
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
