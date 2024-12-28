<?php

namespace App\Services\ProductImplementations;

use App\Services\Interfaces\ProductInterface;
use App\Services\Traits\ProductTrait;

abstract class ProductBaseImplementation implements ProductInterface
{
    use ProductTrait;
}
