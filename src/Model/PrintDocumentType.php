<?php

namespace App\Model;

enum PrintDocumentType: string
{
    case REPORT = 'report';
    case LABEL = 'label';
}
