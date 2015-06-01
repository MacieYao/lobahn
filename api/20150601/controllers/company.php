<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Company extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
        // Load language file
        $lang = $this->input->get_post('lang');
        if ($lang == "tc") {
            $this->lang->load('system', 'tc');
        } else {
            $this->lang->load('system', 'en');
        }
        
        $this->loglib->log(0);
    }
    
    /*
     * Get a list of companies created by the user
     */
    public function get_companies() {
        $token = $this->input->get_post('token');
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        $query = "
            SELECT 
                c.*,
                GROUP_CONCAT(ci.industry SEPARATOR '@@') AS industries
            FROM
                ".TABLE_COMPANIES." c
                LEFT OUTER JOIN ".TABLE_COMPANIES_INDUSTRIES." ci
                    ON c.id = ci.company_id
            WHERE c.user_id = ?
            GROUP BY c.id
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
 
        // TR altered to fix case with no company defined (20150324)   
        if (count($results) > 0) {
            $companies = array();
            foreach ($results as $row) {
                $logo = $row['logo'];
                if (strlen($logo) == 0) {
                    $row['logo'] = DEFAULT_COMPANY_LOGO;
                } else {
                    $row['logo'] = COMPANY_LOGO_URL.$row['logo'];
                }
                $row['industries'] = explode("@@", $row['industries']);
                
                $companies[] = $row;
            }
            $this->outputlib->output(STATUS_OK, '', $companies);

        } else {
            $this->outputlib->error('Error: no company defined');            
        }

    }
    
    public function get_last_company() {
        $token = $this->input->get_post('token');
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        $query = "
            SELECT 
                c.*,
                GROUP_CONCAT(ci.industry SEPARATOR '@@') AS industries
            FROM
                ".TABLE_COMPANIES." c
                LEFT OUTER JOIN ".TABLE_COMPANIES_INDUSTRIES." ci
                    ON c.id = ci.company_id
            WHERE c.user_id = ?
            GROUP BY c.id
            ORDER BY c.id DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        // TR altered to output an error if there is no company linked to the user (20150324)
        $company = array();
        if (count($results) > 0) {
            $row = $results[0];
            $logo = $row['logo'];
            if (strlen($logo) == 0) {
                $row['logo'] = DEFAULT_COMPANY_LOGO;
            } else {
                $row['logo'] = COMPANY_LOGO_URL.$row['logo'];
            }
            $row['industries'] = explode("@@", $row['industries']);
            $company = $row;
        
            $this->outputlib->output(STATUS_OK, '', $company);
        } else {
            $this->outputlib->error('Error: no company defined');
        }
    }
	
	/*
     * Get Deyail of companies created by the user
     */
    public function get_detail_companies() {
        $token = $this->input->get_post('token');
        $company_id = $this->input->get_post('company_id');

        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        $query = "
            SELECT 
                c.*,
                GROUP_CONCAT(ci.industry SEPARATOR '@@') AS industries
            FROM
                ".TABLE_COMPANIES." c
                LEFT OUTER JOIN ".TABLE_COMPANIES_INDUSTRIES." ci
                    ON c.id = ci.company_id
            WHERE c.user_id = ? and c.id = ?
            GROUP BY c.id
        ";
        $data = array($user['id'],$company_id);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $companies = array();
        foreach ($results as $row) {
            $logo = $row['logo'];
            if (strlen($logo) == 0) {
                $row['logo'] = DEFAULT_COMPANY_LOGO;
            } else {
                $row['logo'] = COMPANY_LOGO_URL.$row['logo'];
            }
            $row['industries'] = explode("@@", $row['industries']);
            
            $companies[] = $row;
        }
        
        $this->outputlib->output(STATUS_OK, '', $companies);
    }
    
    /*
     * Create a new company for a recruiter (e.g. job agent)
     */
    public function create_company() {
        $token = $this->input->get_post('token');
        $name = $this->input->get_post('name');
        $industries = $this->input->get_post('industries');
        $website = $this->input->get_post('website');
        $descriptions = $this->input->get_post('descriptions');
        $user = $this->userlib->getUserProfile($token);
        
        if (!$descriptions) {
            $descriptions = "";
        }
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $name, $industries, $website))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Create company record
        $query = "
            INSERT INTO ".TABLE_COMPANIES."
            (user_id, name, website, descriptions, logo)
            VALUES
            (?, ?, ?, ?, '')
        ";
        $data = array($user['id'], $name, $website, $descriptions);
        $this->db->query($query, $data);
        $company_id = $this->db->insert_id();
        
        // Insert industry records
        $industries = json_decode($industries);
        foreach ($industries as $i) {
            $query = "
                INSERT INTO ".TABLE_COMPANIES_INDUSTRIES."
                (company_id, industry)
                VALUES
                (?, ?)
            ";
            $data = array($company_id, $i);
            $this->db->query($query, $data);
        }
        
        // Save the logo if it is provided
        if (isset($_FILES['logo'])) {
            if (strlen($_FILES['logo']['name']) > 0) {
                $name = $_FILES['logo']['name'];
                $tmp_name = $_FILES['logo']['tmp_name'];
                $file = $this->uploadlib->saveCompanyLogo($company_id, $name, $tmp_name);
                
                $query = "
                    UPDATE ".TABLE_COMPANIES."
                    SET logo = ?
                    WHERE id = ?
                ";
                $data = array($file, $company_id);
                $this->db->query($query, $data);
            }
        }
        
        $output = array();
        $output['company_id'] = strval($company_id);
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    /*
     * Update the informaiton of an existing company for a recruiter (e.g. job agent)
     */
    public function update_company() {
        $token = $this->input->get_post('token');
        $company_id = $this->input->get_post('company_id');
        $name = $this->input->get_post('name');
        $industries = $this->input->get_post('industries');
        $website = $this->input->get_post('website');
        $descriptions = $this->input->get_post('descriptions');
        $user = $this->userlib->getUserProfile($token);
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $company_id. $name, $industries, $website, $descriptions))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Update company record
        $query = "
            UPDATE ".TABLE_COMPANIES."
            SET
                name = ?,
                website = ?,
                descriptions = ?
            WHERE
                id = ?
        ";
        $data = array($name, $website, $descriptions, $company_id);
        $this->db->query($query, $data);
        
        // Update industry records
        $industries = json_decode($industries);
        $this->db->query("DELETE FROM ".TABLE_COMPANIES_INDUSTRIES." WHERE company_id = ?", array($company_id));
        foreach ($industries as $i) {
            $query = "
                INSERT INTO ".TABLE_COMPANIES_INDUSTRIES."
                (company_id, industry)
                VALUES
                (?, ?)
            ";
            $data = array($company_id, $i);
            $this->db->query($query, $data);
        }
        
        // Save the logo if it is provided
        if (isset($_FILES['logo'])) {
            if (strlen($_FILES['logo']['name']) > 0) {
                $name = $_FILES['logo']['name'];
                $tmp_name = $_FILES['logo']['tmp_name'];
                $file = $this->uploadlib->saveCompanyLogo($company_id, $name, $tmp_name);
                
                $query = "
                    UPDATE ".TABLE_COMPANIES."
                    SET logo = ?
                    WHERE id = ?
                ";
                $data = array($file, $company_id);
                $this->db->query($query, $data);
            }
        }
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
	/*
     * Upload company logo, old logo will be replaced
     */
    public function upload_company_logo() {
        $token = $this->input->post('token');
        $user = $this->userlib->getUserProfile($token);
        $company_id = $this->input->post('company_id');
		
        // If no file is uploaded, return error
        if (!isset($_FILES['logo'])) {
            $this->outputlib->error($this->lang->line('error_logo'));
        }
        
        $name = $_FILES['logo']['name'];
        $tmp_name = $_FILES['logo']['tmp_name'];
        $file = $this->uploadlib->saveCompanyLogo($company_id, $name, $tmp_name);
                
                $query = "
                    UPDATE ".TABLE_COMPANIES."
                    SET logo = ?
                    WHERE id = ?
                ";
                $data = array($file, $company_id);
                $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '',$data);
    }
}
