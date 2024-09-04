<?php

namespace App\Enums;

enum HexCodes: string
{
    case SOH = "\x01";
    case STX = "\x02";
    case ETX = "\x03";
    case EOT = "\x04";
    case ENQ = "\x05";
    case ACK = "\x06";
    case NAK = "\x15";
    case LF = "\x0A";
    case CR = "\x0D";
    case FD = "\x7Ch";
    case RD = "\x5Ch";
    case caret = "\x5Eh";
    case ampersand = "\x26h";
}
