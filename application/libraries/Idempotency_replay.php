<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Thrown by Idempotency::claim() when the token was already used successfully: carries the stored first result. */
class Idempotency_replay extends Exception
{
    /** @var bool */
    public $ok = TRUE;

    public function __construct($ok, $message)
    {
        parent::__construct((string) $message);
        $this->ok = (bool) $ok;
    }
}
