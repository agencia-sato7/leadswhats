<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case GESTOR = 'gestor';
    case SDR = 'sdr';
}
