<?php

return [
    // Keep disabled until the policy, web/native controls and owner review agree.
    // Enabling this also requires fresh consent before chat contributions.
    'chat_safety_enabled' => env('CONTRUST_CHAT_SAFETY_ENABLED', false),
];
