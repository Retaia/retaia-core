<?php

namespace App\Job;

enum JobStatus: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
