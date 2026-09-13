<?php

namespace App\Enums;

enum RiskAction: string
{
    case Allow = 'allow';
    case Review = 'review';
    case Block = 'block';
}
