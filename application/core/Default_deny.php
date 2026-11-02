<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * G1 - the one rule that decides whether a URL reaches a method.
 *
 * CodeIgniter 3 routes every public method of a controller (only names starting with "_" and CI_Controller's own
 * methods are blocked). Every controller therefore declares `$permission_map` (method => permission key or @token) and
 * its `_remap` serves a method only when `is_routable()` says so. A method that is not in the map, does not exist, or is
 * not public is never called: default deny. Method names in the map are lower case (the static check enforces it).
 */
trait Default_deny
{
    // The using class declares `protected $permission_map = array(...)`:
    //   method => permission key | '@any' | '@self' | '@public' | '@login' | '@cli' | '@device'

    /** @return bool */
    protected function is_routable($method)
    {
        if ( ! is_string($method) || $method === '' || $method[0] === '_') {
            return FALSE;
        }
        $method = strtolower($method);
        if ( ! array_key_exists($method, $this->permission_map) || ! method_exists($this, $method)) {
            return FALSE;
        }
        $reflection = new ReflectionMethod($this, $method);
        return $reflection->isPublic() && ! $reflection->isStatic() && ! $reflection->isAbstract();
    }
}
