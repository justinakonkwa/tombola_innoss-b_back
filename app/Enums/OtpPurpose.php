<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case PhoneVerification = 'phone_verification';
    case Transaction = 'transaction';
}
