<?php

namespace App\Domain\Catalog;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
