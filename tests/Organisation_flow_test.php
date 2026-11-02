<?php
/** Step 2 over HTTP: salary and login-link permissions, masking on the page, documents and photo served through the controller, branch 403s. */
class Organisation_flow_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;
    /** @var Http_server */
    protected static $server;
    protected static $roles = array();
    protected static $users = array();
    protected static $branches = array();
    protected static $employees = array();

    public static function setUpBeforeClass(): void
    {
        get_instance()->load->model('Employees_model');
        self::$server = new Http_server();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        $db = get_instance()->db;
        foreach ($db->query('SELECT file FROM employee_documents')->result_array() as $d) {
            @unlink(FCPATH . Employees_model::DOC_DIR . $d['file']);
        }
        foreach ($db->query('SELECT photo FROM employees WHERE photo IS NOT NULL')->result_array() as $p) {
            @unlink(FCPATH . Employees_model::PHOTO_DIR . $p['photo']);
        }
        $db->query('DELETE FROM employee_documents');
        $db->query('DELETE FROM salary_structures');
        $db->query('UPDATE users SET employee_id = NULL');
        $db->query('DELETE FROM employees');
        if (self::$users) {
            $db->where_in('id', self::$users)->delete('users');
        }
        if (self::$roles) {
            $db->where_in('id', self::$roles)->delete('roles');
        }
        $db->query('DELETE FROM audit_log');
        if (self::$branches) {
            $db->where_in('id', self::$branches)->delete('branches');
        }
        $db->empty_table('sequences');
        $db->empty_table('ci_sessions');
        $db->empty_table('login_attempts');
    }

    protected function make_user($username, array $permissions)
    {
        $role_id = $this->insert_role('role_' . $username, $permissions);
        $id = $this->insert_user($username, $role_id, NULL, 'Passw0rd!x');
        self::$roles[] = $role_id;
        self::$users[] = $id;
        return $id;
    }

    protected function client_as($username)
    {
        $http = new Http_client(self::$server->port);
        $http->login($username, 'Passw0rd!x');
        return $http;
    }

    protected function employee($code, $branch = NULL)
    {
        $this->db->insert('employees', array('branch_id' => $branch === NULL ? $this->first_branch_id() : $branch, 'employee_code' => $code, 'first_name' => 'Nusrat',
            'last_name' => $code, 'employment_type' => 'Permanent', 'join_date' => '2024-01-01', 'status' => 'Active', 'bank_account_number' => '1234567890123',
            'national_id' => '19901234567890', 'created_at' => date('Y-m-d H:i:s')));
        return (int) $this->db->insert_id();
    }

    public function test_salary_and_sensitive_data_follow_their_permissions()
    {
        $id = $this->employee('F1');
        $this->make_user('of_clerk', array('employees_view', 'employees_edit'));
        $this->make_user('of_payview', array('employees_view', 'employees_salary'));
        $this->make_user('of_hr', array('employees_view', 'employees_edit', 'employees_salary', 'employees_sensitive', 'users_edit'));

        $clerk = $this->client_as('of_clerk');
        list($status, $page) = $clerk->get('employees/view/' . $id);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('*********0123', $page);
        $this->assertStringNotContainsString('1234567890123', $page, 'never in clear without employees_sensitive');
        $this->assertStringNotContainsString('tab-salary-link', $page, 'no salary tab without employees_salary');
        list($status) = $clerk->post('employees/salary_add/' . $id, array('effective_from' => '01-01-2026', 'basic_salary' => '1'));
        $this->assertSame(403, $status);
        list($status) = $clerk->post('employees/link_login/' . $id, array('user_id' => '1'));
        $this->assertSame(403, $status, 'linking a login also needs users_edit');
        list(, $form) = $clerk->get('employees/edit/' . $id);
        $this->assertStringContainsString('id="sensitive-hidden"', $form);
        $this->assertStringNotContainsString('name="bank_account_number"', $form, 'no input for masked values');

        $payview = $this->client_as('of_payview');
        list(, $page) = $payview->get('employees/view/' . $id);
        $this->assertStringContainsString('tab-salary-link', $page);
        $this->assertStringNotContainsString('id="salary-form"', $page, 'viewing salary is not changing it');
        list($status) = $payview->post('employees/salary_add/' . $id, array('effective_from' => '01-01-2026', 'basic_salary' => '1000'));
        $this->assertSame(403, $status, 'changing salary also needs employees_edit');

        $hr = $this->client_as('of_hr');
        list(, $page) = $hr->get('employees/view/' . $id);
        $this->assertStringContainsString('1234567890123', $page);
        list(, $body) = $hr->post('employees/salary_add/' . $id, array('effective_from' => '01-01-2026', 'basic_salary' => '25000', 'house_rent' => '10000', 'net_salary' => '1'));
        $this->assertStringStartsWith('success', $body);
        $this->assertEquals(35000, (float) $this->db->get_where('salary_structures', array('employee_id' => $id))->row()->net_salary);
    }

    public function test_documents_are_checked_stored_privately_and_served_through_the_controller()
    {
        $id = $this->employee('F2');
        $this->make_user('of_docs', array('employees_view', 'employees_edit'));
        $this->make_user('of_viewer', array('employees_view'));
        $this->make_user('of_nobody', array());
        $http = $this->client_as('of_docs');
        $dir = HRM_ROOT . '/tests/_output/';

        file_put_contents($dir . 'contract.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        file_put_contents($dir . 'evil.pdf', "<html><script>alert(1)</script></html>");

        list(, $body) = $http->post_multipart('employees/document_add/' . $id, array('title' => 'Contract'), array('document' => $dir . 'evil.pdf'));
        $this->assertStringContainsString('PDF, PNG or JPEG', $body);
        list(, $body) = $http->post_multipart('employees/document_add/' . $id, array('title' => 'Contract', 'expires_on' => '31-12-2027'), array('document' => $dir . 'contract.pdf'));
        $this->assertStringStartsWith('success<<<###>>>', $body);
        $doc = (int) substr($body, strlen('success<<<###>>>'));
        $row = $this->db->get_where('employee_documents', array('id' => $doc))->row_array();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $row['file']);
        $this->assertSame('contract.pdf', $row['original_name']);

        list($status, $file, $headers) = $this->client_as('of_viewer')->get('employees/document/' . $doc);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Content-Type: application/pdf', $headers);
        $this->assertStringContainsString('attachment;', $headers);
        $this->assertSame(file_get_contents($dir . 'contract.pdf'), $file);
        list($status) = $this->client_as('of_nobody')->get('employees/document/' . $doc);
        $this->assertSame(403, $status);
        list($status) = $http->get(Employees_model::DOC_DIR . $row['file']);
        $this->assertSame(404, $status, 'uploads/ is never served directly');

        list(, $body) = $http->post('employees/document_delete/' . $doc);
        $this->assertSame('success', $body);
        $this->assertFileDoesNotExist(FCPATH . Employees_model::DOC_DIR . $row['file']);
        @unlink($dir . 'contract.pdf');
        @unlink($dir . 'evil.pdf');
    }

    public function test_photo_upload_on_the_edit_form()
    {
        $id = $this->employee('F3');
        $this->make_user('of_photo', array('employees_view', 'employees_edit'));
        $http = $this->client_as('of_photo');
        $png = HRM_ROOT . '/tests/_output/face.png';
        $img = imagecreatetruecolor(30, 30);
        imagepng($img, $png);
        imagedestroy($img);
        list(, $body) = $http->post_multipart('employees/update/' . $id, array('employee_code' => 'F3', 'first_name' => 'Nusrat', 'employment_type' => 'Permanent', 'join_date' => '01-01-2024'), array('photo' => $png));
        $this->assertSame('success', $body);
        list($status, $bytes, $headers) = $http->get('employees/photo/' . $id);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('image/png', $headers);
        $this->assertSame(file_get_contents($png), $bytes);
        @unlink($png);
    }

    public function test_records_of_another_branch_are_403()
    {
        $other = $this->insert_branch('OF' . random_int(10, 99));
        self::$branches[] = $other;
        $far = $this->employee('FAR', $other);
        $this->db->insert('departments', array('branch_id' => $other, 'department_name' => 'Far', 'department_code' => 'FAR', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')));
        $far_dept = (int) $this->db->insert_id();
        $this->make_user('of_all', array('employees_view', 'employees_edit', 'employees_delete', 'departments_view', 'departments_edit', 'departments_delete'));
        $http = $this->client_as('of_all');
        foreach (array('employees/view/' . $far, 'employees/edit/' . $far, 'employees/photo/' . $far, 'departments/edit/' . $far_dept) as $url) {
            list($status) = $http->get($url);
            $this->assertSame(403, $status, $url);
        }
        foreach (array('employees/delete/' . $far, 'employees/change_pin/' . $far, 'departments/delete/' . $far_dept) as $url) {
            list($status) = $http->post($url);
            $this->assertSame(403, $status, $url);
        }
        $this->assertSame(1, $this->db->get_where('employees', array('id' => $far))->num_rows());
        $this->db->delete('departments', array('id' => $far_dept));
    }
}
