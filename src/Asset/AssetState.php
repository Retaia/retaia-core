<?php

namespace App\Asset;

enum AssetState: string
{
    case DISCOVERED = 'DISCOVERED';
    case READY = 'READY';
    case PROCESSING_REVIEW = 'PROCESSING_REVIEW';
    case PROCESSED = 'PROCESSED';
    case DECISION_PENDING = 'DECISION_PENDING';
    case DECIDED_KEEP = 'DECIDED_KEEP';
    case DECIDED_REJECT = 'DECIDED_REJECT';
    case MOVE_QUEUED = 'MOVE_QUEUED';
    case ARCHIVED = 'ARCHIVED';
    case REJECTED = 'REJECTED';
    case PURGED = 'PURGED';
}
