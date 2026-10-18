<?php

namespace App\Scheduling;

enum SlotStatus: string
{
    /** Réservable */
    case Free = 'free';
    /** Déjà pris par un rendez-vous confirmé */
    case Taken = 'taken';
    /** Passé, trop proche, trop lointain ou pendant une fermeture */
    case Unavailable = 'unavailable';
}
