<?php
/** Stands in for CI_Input in Input_validator tests: post($name) / get($name) from plain arrays. */
class Fake_input
{
    /** @var array */
    public $post = array();
    /** @var array */
    public $get = array();

    public function __construct(array $post = array(), array $get = array())
    {
        $this->post = $post;
        $this->get = $get;
    }

    public function post($name = NULL)
    {
        return isset($this->post[$name]) ? $this->post[$name] : NULL;
    }

    public function get($name = NULL)
    {
        return isset($this->get[$name]) ? $this->get[$name] : NULL;
    }
}
