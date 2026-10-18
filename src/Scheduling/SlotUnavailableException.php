<?php

namespace App\Scheduling;

final class SlotUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ce créneau n\'est plus disponible.');
    }
}
