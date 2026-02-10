<?php

namespace App\Lock;

enum OperationLockType: string
{
    case MOVE = 'asset_move_lock';
    case PURGE = 'asset_purge_lock';
}

