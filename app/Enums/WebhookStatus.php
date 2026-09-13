<?php

namespace App\Enums;

enum WebhookStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
}
