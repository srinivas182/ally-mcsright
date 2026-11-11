<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

enum Source: string
{
    case Website = 'website';
    case Zillow = 'zillow';
    case RealtorCom = 'realtor_com';
    case HomesCom = 'homes_com';
    case Facebook = 'facebook';
    case Google = 'google';
    case Referral = 'referral';
    case OpenHouse = 'open_house';
    case Sphere = 'sphere';
    case Import = 'import';
    case Manual = 'manual';
    case Other = 'other';
}
