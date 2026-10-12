<?php

namespace App\Channels\Contracts;

/** The KYC service did not answer: temporary, and never a reason to admit or refuse for good. */
class KycUnavailable extends \RuntimeException {}
