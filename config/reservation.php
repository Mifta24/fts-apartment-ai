<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pending request expiry
    |--------------------------------------------------------------------------
    |
    | A pending request holds its units until the team answers. When this is
    | set, `bookings:expire-pending` cancels requests nobody answered within
    | that many hours and gives the units back. Leave it empty to never expire.
    |
    */

    'pending_expiry_hours' => filled(env('BOOKING_PENDING_EXPIRY_HOURS')) ? (int) env('BOOKING_PENDING_EXPIRY_HOURS') : null,

];
