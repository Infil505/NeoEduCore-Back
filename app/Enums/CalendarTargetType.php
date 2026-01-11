<?php

namespace App\Enums;

enum CalendarTargetType: string
{
    case Institution = 'institution';
    case Grade = 'grade';
    case Group = 'group';
    case Student = 'student';
    case Teacher = 'teacher';
}