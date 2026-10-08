<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic completion of delivered orders
    |--------------------------------------------------------------------------
    |
    | A delivered order the customer never confirmed is completed automatically
    | after this many days, unless its payment is flagged for review.
    |
    */
    'auto_complete_days' => (int) env('ORDER_AUTO_COMPLETE_DAYS', 3),

];
