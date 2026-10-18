<?php

namespace App\Services\Operator;

/** A player's wallet holds money in one wallet mode while the integration is now in the other. */
class WalletModeConflict extends \RuntimeException {}
