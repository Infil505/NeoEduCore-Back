<?php

namespace App\Enums;

enum CalendarEventType: string
{
    case Exam      = 'exam';
    case Activity  = 'activity';
    case Reminder  = 'reminder';
    case Meeting   = 'meeting';
}
