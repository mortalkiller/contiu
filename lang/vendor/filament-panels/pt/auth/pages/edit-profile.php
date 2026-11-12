<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
// Only the two-step verification keys; the rest falls back to Filament's file.
return [

    'multi_factor_authentication' => [
        'label' => 'Verificação em dois passos (2FA)',
    ],

];
