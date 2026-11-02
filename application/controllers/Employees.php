<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Employees of the current branch. By-id actions check the permission first (map), then the record (belong_to).
 * Salary needs employees_salary (+ employees_edit to change it); the login link also needs users_edit.
 */
class Employees extends MY_Controller
{
    protected $permission_map = array(
        'index'           => 'employees_view',
        'ajax_list'       => 'employees_view',
        'view'            => 'employees_view',
        'photo'           => 'employees_view',
        'document'        => 'employees_view',
        'add'             => 'employees_add',
        'save'            => 'employees_add',
        'edit'            => 'employees_edit',
        'update'          => 'employees_edit',
        'record_exit'     => 'employees_edit',
        'rehire'          => 'employees_edit',
        'change_pin'      => 'employees_edit',
        'document_add'    => 'employees_edit',
        'document_delete' => 'employees_edit',
        'link_login'      => 'employees_edit',
        'unlink_login'    => 'employees_edit',
        'salary_add'      => 'employees_salary',
        'salary_delete'   => 'employees_salary',
        'delete'          => 'employees_delete',
    );
    protected $post_only = array('ajax_list', 'save', 'update', 'record_exit', 'rehire', 'change_pin', 'document_add', 'document_delete',
        'link_login', 'unlink_login', 'salary_add', 'salary_delete', 'delete');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Employees_model');
        $this->data['active_menu'] = 'employees';
    }

    public function index()
    {
        $this->load->model('Departments_model');
        $this->load->model('Designations_model');
        $this->data['page_title'] = t('menu_employees');
        $this->render('employees/index', array(
            'departments' => $this->Departments_model->active_list(), 'designations' => $this->Designations_model->active_list(),
            'types' => Employees_model::employment_types(), 'page_js' => array('employees'),
        ));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Employees_model->list_page());
    }

    public function view($id = NULL)
    {
        $this->belong_to('employees', $id);
        $data = array(
            'emp'       => $this->Employees_model->profile($id),
            'pins'      => $this->Employees_model->pin_history($id),
            'documents' => $this->Employees_model->documents($id),
            'salaries'  => array(),
            'linkable'  => array(),
            'shift_history' => NULL,
            'page_js'   => array('employees'),
        );
        if (has_permission('employees_salary')) {
            $this->load->model('Salary_model');
            $data['salaries'] = $this->Salary_model->history($id);
        }
        if (has_permission('shifts_view')) {
            $this->load->model('Employee_shifts_model');
            $data['shift_history'] = $this->Employee_shifts_model->history($id);
        }
        if (has_permission('users_edit') && has_permission('employees_edit')) {
            $data['linkable'] = $this->Employees_model->linkable_users();
        }
        $this->data['page_title'] = t('emp_profile');
        $this->render('employees/view', $data);
    }

    public function photo($id = NULL)
    {
        $this->belong_to('employees', $id);
        $emp = $this->Employees_model->get($id);
        $path = Employees_model::stored_path(Employees_model::PHOTO_DIR, $emp['photo']);
        if ($path === NULL) {
            show_404();
        }
        $this->release_session_lock();
        $this->output->set_content_type(substr($path, -4) === '.png' ? 'image/png' : 'image/jpeg')
            ->set_header('Cache-Control: private, max-age=3600')->set_header('X-Content-Type-Options: nosniff')
            ->set_output(file_get_contents($path));
    }

    /** download a document (only of an employee in the current branch) */
    public function document($doc_id = NULL)
    {
        $doc = $this->Employees_model->document($doc_id);
        $path = $doc ? Employees_model::stored_path(Employees_model::DOC_DIR, $doc['file']) : NULL;
        if ($path === NULL) {
            $this->deny(403);
        }
        $types = array('pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg');
        $ext = substr($path, -3);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $doc['employee_code'] . '_' . $doc['title']) . '.' . $ext;
        $this->output->set_content_type($types[$ext])
            ->set_header('Content-Disposition: attachment; filename="' . $name . '"')
            ->set_header('X-Content-Type-Options: nosniff')->set_header('Cache-Control: private, no-store')
            ->set_output(file_get_contents($path));
    }

    public function add()
    {
        $this->form(NULL);
    }

    public function save()
    {
        $this->text($this->Employees_model->create());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->form($this->Employees_model->present($this->Employees_model->get($id)));
    }

    public function update($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->update($id));
    }

    public function record_exit($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->record_exit($id));
    }

    public function rehire($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->rehire($id));
    }

    public function change_pin($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->change_pin($id));
    }

    public function document_add($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->add_document($id));
    }

    public function document_delete($doc_id = NULL)
    {
        if ( ! $this->Employees_model->document($doc_id)) {
            $this->deny(403, 'text');
        }
        $this->text($this->Employees_model->delete_document($doc_id));
    }

    public function link_login($id = NULL)
    {
        $this->require_permission('users_edit');
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->link_login($id));
    }

    public function unlink_login($id = NULL)
    {
        $this->require_permission('users_edit');
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->unlink_login($id));
    }

    public function salary_add($id = NULL)
    {
        $this->require_permission('employees_edit');
        $this->belong_to('employees', $id);
        $this->load->model('Salary_model');
        $this->text($this->Salary_model->add($id));
    }

    public function salary_delete($id = NULL, $structure_id = NULL)
    {
        $this->require_permission('employees_edit');
        $this->belong_to('employees', $id);
        $this->load->model('Salary_model');
        $this->text($this->Salary_model->delete_latest($id, $structure_id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employees_model->delete($id));
    }

    protected function form($row)
    {
        $this->load->model('Departments_model');
        $this->load->model('Designations_model');
        $this->data['page_title'] = $row ? t('emp_edit') : t('emp_add');
        $this->render('employees/form', array(
            'row' => $row, 'departments' => $this->Departments_model->active_list(), 'designations' => $this->Designations_model->active_list(),
            'managers' => $this->Employees_model->active_list($row ? $row['id'] : NULL), 'types' => Employees_model::employment_types(),
            'blood_groups' => Employees_model::blood_groups(), 'can_sensitive' => has_permission('employees_sensitive'), 'page_js' => array('employees'),
        ));
    }
}
