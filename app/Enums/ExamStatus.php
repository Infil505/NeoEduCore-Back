<?php

namespace App\Enums;

enum ExamStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Active    = 'active';
    case Completed = 'completed';
}