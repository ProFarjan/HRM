<?php
/** Branches_model: validation, weekly offs, deactivation rules, switch target, audit. */
class Branches_test extends Hrm_test_case
{
    /** @var object */
    protected $branches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Branches_model');
        $this->branches = $this->CI->Branches_model;
        $this->act_as_new_user('boss', array(), TRUE);
    }

    protected function form(array $fields)
    {
        $this->post(array_merge(array('name' => 'Gazipur Factory', 'code' => 'gzp', 'address' => 'Plot 4, BSCIC', 'phone' => '+880 1711',
            'timezone' => 'Asia/Dhaka', 'weekly_offs_mode' => 'global', 'status' => 'Active'), $fields));
    }

    protected function id_of($result)
    {
        $this->assertStringStartsWith('success<<<###>>>', $result, $result);
        return (int) substr($result, strlen('success<<<###>>>'));
    }

    public function test_create_normalises_the_code_and_stores_weekly_offs()
    {
        $this->form(array());
        $id = $this->id_of($this->branches->create());
        $row = $this->branches->get($id);
        $this->assertSame('GZP', $row['code']);
        $this->assertNull($row['weekly_offs'], 'global setting');
        $this->assertSame('Plot 4, BSCIC', $row['address']);
        $this->assertCount(1, $this->audit_rows('branches', $id, 'create'));

        $this->form(array('code' => 'ctg', 'weekly_offs_mode' => 'custom', 'weekly_offs' => array('6', '5')));
        $row = $this->branches->get($this->id_of($this->branches->create()));
        $this->assertSame('5,6', $row['weekly_offs']);
        $this->assertSame('Fri, Sat', $this->branches->weekly_offs_label('5,6'));

        $this->form(array('code' => 'none', 'weekly_offs_mode' => 'custom'));
        $row = $this->branches->get($this->id_of($this->branches->create()));
        $this->assertSame('', $row['weekly_offs'], 'custom with no day = no weekly off');
        $this->assertSame(t('weekly_offs_none'), $this->branches->weekly_offs_label(''));
        $this->assertStringStartsWith(t('weekly_offs_global'), $this->branches->weekly_offs_label(NULL));
    }

    public function test_validation()
    {
        $this->form(array('timezone' => 'Mars/Olympus'));
        $this->assertStringContainsString('Timezone', $this->branches->create());
        $this->form(array('code' => 'has space'));
        $this->assertSame(t('branches_code_rule'), $this->branches->create());
        $this->form(array('weekly_offs_mode' => 'custom', 'weekly_offs' => array('8')));
        $this->assertStringContainsString('Weekly offs', $this->branches->create());
        $this->form(array('name' => ''));
        $this->assertStringContainsString('Name is required', $this->branches->create());
        $this->form(array('code' => 'HO'));
        $this->assertSame(t('branches_code_taken'), $this->branches->create(), 'the seeded branch uses HO');
    }

    public function test_deactivation_rules()
    {
        $home = current_branch_id();
        $this->form(array('code' => 'HO', 'name' => 'Head Office', 'status' => 'Inactive'));
        $this->assertSame(t('branches_not_own'), $this->branches->update($home));

        $this->form(array('code' => 'SPARE'));
        $spare = $this->id_of($this->branches->create());
        $this->form(array('code' => 'SPARE', 'status' => 'Inactive'));
        $this->assertSame('success', $this->branches->update($spare));
        $this->assertSame('Inactive', $this->branches->get($spare)['status']);
        $this->assertCount(1, $this->audit_rows('branches', $spare, 'deactivate'));

        // last active branch: deactivate everything else, then try the remaining one from another (switched) branch
        $this->db->query("UPDATE branches SET status = 'Inactive' WHERE id <> ?", array($home));
        set_current_branch_id($spare);
        $this->form(array('code' => 'HO', 'name' => 'Head Office', 'status' => 'Inactive'));
        $this->assertContains($this->branches->update($home), array(t('branches_last_active'), t('branches_not_own')));
    }

    public function test_update_only_audits_real_changes()
    {
        $this->form(array('code' => 'AUD'));
        $id = $this->id_of($this->branches->create());
        $this->form(array('code' => 'AUD'));
        $this->assertSame('success', $this->branches->update($id));
        $this->assertCount(0, $this->audit_rows('branches', $id, 'update'));
        $this->form(array('code' => 'AUD', 'timezone' => 'Asia/Kolkata'));
        $this->assertSame('success', $this->branches->update($id));
        $audit = $this->audit_rows('branches', $id, 'update');
        $this->assertSame(array('timezone' => 'Asia/Kolkata'), json_decode($audit[0]['new_values'], TRUE));
    }

    public function test_switch_target_accepts_only_active_branches()
    {
        $this->form(array('code' => 'SW1'));
        $id = $this->id_of($this->branches->create());
        $this->post(array('branch_id' => (string) $id));
        $this->assertSame($id, (int) $this->branches->switch_target()['id']);
        $this->db->update('branches', array('status' => 'Inactive'), array('id' => $id));
        $this->assertNull($this->branches->switch_target());
        $this->post(array('branch_id' => 'abc'));
        $this->assertNull($this->branches->switch_target());
    }
}
