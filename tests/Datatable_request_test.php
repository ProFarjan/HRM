<?php
/** G2/G5 for lists: sort column only from the whitelist, page size clamped, search bounded. */
class Datatable_request_test extends \PHPUnit\Framework\TestCase
{
    protected function dt(array $post, $max = 1000)
    {
        return new Datatable_request(new Fake_input($post), array('u.username', 'r.role_name', NULL, 'u.created_at'), 0, 'asc', $max);
    }

    public function test_defaults()
    {
        $dt = $this->dt(array());
        $this->assertSame(0, $dt->draw);
        $this->assertSame(0, $dt->start);
        $this->assertSame(25, $dt->length);
        $this->assertSame('', $dt->search);
        $this->assertSame('u.username', $dt->order_by);
        $this->assertSame('asc', $dt->order_dir);
    }

    public function test_valid_request()
    {
        $dt = $this->dt(array('draw' => '7', 'start' => '50', 'length' => '100', 'search' => array('value' => '  rahim  '),
            'order' => array(array('column' => '3', 'dir' => 'desc'))));
        $this->assertSame(array(7, 50, 100, 'rahim', 'u.created_at', 'desc'), array($dt->draw, $dt->start, $dt->length, $dt->search, $dt->order_by, $dt->order_dir));
    }

    public function test_sort_column_never_comes_from_the_request_text()
    {
        foreach (array('9', '-1', '1; DROP TABLE users', 'u.password_hash', '2', '') as $bad) {
            $dt = $this->dt(array('order' => array(array('column' => $bad, 'dir' => 'asc'))));
            $this->assertSame('u.username', $dt->order_by, var_export($bad, TRUE));
        }
        $dt = $this->dt(array('order' => array(array('column' => '1', 'dir' => 'DESC; --'))));
        $this->assertSame(array('r.role_name', 'asc'), array($dt->order_by, $dt->order_dir));
        $dt = $this->dt(array('order' => 'garbage'));
        $this->assertSame('u.username', $dt->order_by);
    }

    public function test_page_size_and_offset_are_clamped()
    {
        $this->assertSame(1000, $this->dt(array('length' => '-1'))->length, '"All" means the screen cap');
        $this->assertSame(1000, $this->dt(array('length' => '50000'))->length);
        $this->assertSame(200, $this->dt(array('length' => '500'), 200)->length);
        $this->assertSame(25, $this->dt(array('length' => 'abc'))->length);
        $this->assertSame(0, $this->dt(array('start' => '-10'))->start);
        $this->assertSame(0, $this->dt(array('start' => '1e9'))->start);
    }

    public function test_search_is_bounded_and_cleaned()
    {
        $this->assertSame(100, mb_strlen($this->dt(array('search' => array('value' => str_repeat('ক', 300))))->search));
        $this->assertSame('ab', $this->dt(array('search' => array('value' => "a\0b")))->search);
        $this->assertSame('', $this->dt(array('search' => 'not-an-array'))->search);
        $this->assertSame('', $this->dt(array('search' => array('value' => array('x'))))->search);
    }

    public function test_response_shape()
    {
        $this->assertSame(array('draw' => 4, 'recordsTotal' => 10, 'recordsFiltered' => 3, 'data' => array()), $this->dt(array('draw' => '4'))->response('10', 3, array()));
    }
}
