<?php

/*
| Operator integration (docs/platform/operator-integration.md).
*/
return [
    // A launched player's token lifetime. There is no refresh inside an operator's
    // iframe; when it runs out the player reopens the game from the operator's site.
    'player_token_minutes' => (int) env('OPERATOR_PLAYER_TOKEN_MINUTES', 240),
];
