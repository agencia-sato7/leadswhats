<?php

namespace App\Enums;

enum UserRole: string
{
    case PLATFORM_ADMIN = 'platform_admin';
    case ADMIN = 'admin';
    case GESTOR = 'gestor';
    case SDR = 'sdr';
}
