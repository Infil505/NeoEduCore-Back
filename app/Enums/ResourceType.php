<?php

namespace App\Enums;

enum ResourceType: string
{
    case Pdf = 'pdf';
    case Image = 'image';
    case Video = 'video';
    case Link = 'link';
    case Other = 'other';
}