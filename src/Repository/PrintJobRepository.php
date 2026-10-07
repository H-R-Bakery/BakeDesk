<?php

namespace App\Repository;

use App\Entity\PrintJob;

class PrintJobRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return PrintJob::class;
    }
}
