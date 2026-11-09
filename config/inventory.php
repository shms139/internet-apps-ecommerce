<?php

return [
    'grpc_host' => env(
        'INVENTORY_GRPC_HOST',
        'inventory-service:50051'
    ),

    'grpc_timeout' => (int) env(
        'INVENTORY_GRPC_TIMEOUT',
        3000
    ),
];
