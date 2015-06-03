<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Demo extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
    }
    
    public function job_search() {
        $this->load->view('demo/job_search');
    }

    public function job_titles() {
        $this->load->view('demo/job_titles');
    }

    public function job_companies() {
        $this->load->view('demo/job_companies');
    }
    
    public function candidate_search() {
        $this->load->view('demo/candidate_search');
    }
    
    public function similar_jobs() {
        $query = "
            SELECT 
                id,
                title,
                company,
                location,
                industry
            FROM
                (
                    SELECT job_1 AS job_id
                    FROM lobahn_analysis.job_similarity
                    ORDER BY RAND()
                    LIMIT 50
                ) sjobs
                    JOIN lobahn.jobs jobs
                    ON sjobs.job_id = jobs.id
            ORDER BY id
        ";
        $results = $this->db->query($query);
        $jobs = $results->result_array();
        
        $data = array("jobs" => $jobs);
        $this->load->view('demo/similar_jobs', $data);
    }
    
}