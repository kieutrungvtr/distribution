<?php

return [
    'batch' => env('HORIZON_QUEUE_LIMIT_PER_BATCH', 10),
    'unique_for' => env('QUEUE_UNIQUE_FOR', 3600),
    'quota' => env('DISTRIBUTION_QUEUE_QUOTA', 10),

];
