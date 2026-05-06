<?php

namespace App\Enums;

enum GradeStatus: string
{
    case Pending   = 'pending';
    case Graded    = 'graded';
    case Completed = 'completed';
}
