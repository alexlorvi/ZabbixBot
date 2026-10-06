<?php
declare(strict_types=1);

namespace ZbxBot\Bot;

enum Access
{
    case Anyone;
    case User;
    case Admin;
}
