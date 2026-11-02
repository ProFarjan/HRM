<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Throw inside Tx::run() to roll back and show $message (a human-readable reason) to the user. */
class Tx_abort extends Exception
{
}
