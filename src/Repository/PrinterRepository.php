<?php

namespace App\Repository;

use App\Entity\Printer;

class PrinterRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return Printer::class;
    }
}
