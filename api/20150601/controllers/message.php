<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Message extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
        // Load language file
        $lang = $this->input->get_post('lang');
        if ($lang == "tc") {
            $this->lang->load('system', 'tc');
        } else {
            $this->lang->load('system', 'en');
        }
        
        // Check token
        $token = $this->input->get_post("token");
        if (!$token) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        $this->loglib->log(0);
    }
    

    // Get the lists of messages/news for the user
    public function get_message_list() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);
        
        // Get new JOB messages for this user
        $query = "
            SELECT
                nj.id,
                j.id AS job_id,
                j.title AS job_title,
                j.location AS job_location,
                j.company,
                nj.create_time AS timestamp
            FROM
                ".TABLE_USERS_NEW_JOBS." nj
                JOIN ".TABLE_JOBS." j
                    ON nj.job_id = j.id
            WHERE 
                nj.user_id = ?
            AND nj.status = 1
            ORDER BY timestamp DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();

        // Add job_count field
        $job_count = count($news_jobs);
        $news_jobs = array();
        foreach ($results as $row) {
            $row['job_count'] = $job_count;
            $news_jobs[] = $row;
        }
        
        // GET new CANDIDATE messages for this user
        $query = "
            SELECT
                nc.id,
                u.id AS candidate_id,
                u.current_job,
                u.current_employer,
                nc.create_time AS timestamp
            FROM
                ".TABLE_USERS_NEW_CANDIDATES." nc
                JOIN ".TABLE_USERS." u
                    ON nc.candidate_id = u.id
            WHERE
                nc.user_id = ?
            AND nc.status = 1
            ORDER BY timestamp DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $news_candidates = $results->result_array();
        
        // GET system messages
        // Empty array for the time being
        $news_system = array();
        
        $output = array();
        $output['jobs'] = $news_jobs;
        $output['candidates'] = $news_candidates;
        $output['system'] = $news_system;
        
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    // Set a message to be read 
    // Message type: 0 = system, 1 = job, 2 = candidate
    public function set_message_read() {
        $token = $this->input->get_post('token');
        $message_id = $this->input->get_post('message_id');
        $message_type = $this->input->get_post('message_type');
        $user = $this->userlib->getUserProfile($token);
        
        $table = "";
        if ($message_type == "0") $table = ""; // To be updated
        if ($message_type == "1") $table = TABLE_USERS_NEW_JOBS;
        if ($message_type == "2") $table = TABLE_USERS_NEW_CANDIDATES;
        
        // Set the status of the message to 0 (read)
        $query = "
            UPDATE ".$table."
            SET status = 0
            WHERE
                user_id = ?
            AND id = ?
        ";
        $data = array($user['id'], $message_id);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
}

