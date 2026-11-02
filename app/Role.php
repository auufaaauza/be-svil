<?php

namespace App;

enum Role: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Admin = 'admin';
}
