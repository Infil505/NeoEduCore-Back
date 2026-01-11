<?php

namespace App\Enums;

enum UserType: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Student = 'student';
}