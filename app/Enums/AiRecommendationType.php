<?php

namespace App\Enums;

enum AiRecommendationType: string
{
    case Strength = 'strength';
    case Weakness = 'weakness';
    case Resource = 'resource';
    case Action   = 'action';
}
