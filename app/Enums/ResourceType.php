<?php

namespace App\Enums;

enum ResourceType: string
{
    case Video    = 'video';
    case Article  = 'article';
    case Exercise = 'exercise';
    case Book     = 'book';
    case Pdf      = 'pdf';
    case Link     = 'link';
    case Other    = 'other';
}
