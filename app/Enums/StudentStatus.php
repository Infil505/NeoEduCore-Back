<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active    = 'active';
    case Inactive  = 'inactive';
    case Suspended = 'suspended';
}