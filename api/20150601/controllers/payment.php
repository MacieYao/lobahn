<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Payment extends CI_Controller {

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


    public function successpage() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);

        $job_id = $this->input->get_post('job_id');        
        $transaction_id = $this->input->get_post('transaction_id');
        $amount = $this->input->get_post('amount');
        
        // Update job status
        $query = "
            UPDATE ".TABLE_JOBS."
            SET status = 1
            WHERE id = ?
        ";
        $data = array($job_id);
        $this->db->query($query, $data);

        // Insert record into transaction table
        $query = "
            INSERT INTO ".TABLE_TRANSACTION_PAYPAL."
            (user_id, job_id, transaction_id, amount, timestamp)
			VALUES
            (?, ?, ?, ?, NOW())
        ";
        $data = array($user['id'], $job_id, $transaction_id, $amount);
        $this->db->query($query, $data);

        // Display the HTML page
		$html = '
            <html>
            <center>
            <br><br><br>
            <H2><b>Thanks for your payment!</b></H2>
            <br><br>
            <H4>You may leave this page now.</H4>
            </center>
            </html>
        ';
		         
		echo $html;
    }
    
    public function pay_by_credit() {
        $token = $this->input->get_post('token');
        $job_id = $this->input->get_post('job_id');
        $amount = $this->input->get_post('amount');
        $user = $this->userlib->getUserProfile($token);
        
        // Check whether user has enough credit
        $query = "
            SELECT *
            FROM ".TABLE_USERS."
            WHERE id = ?
        ";
        $data = array($user['id']);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        $user = $result[0];
        $credit = $user['current_credit'];
        if ($credit < $amount) {
            $this->outputlib->error($this->lang->line('error_not_enough_credits'));
            return;
        }
        
        // Update job status
        $query = "
            UPDATE ".TABLE_JOBS."
            SET status = 1
            WHERE id = ?
        ";
        $data = array($job_id);
        $this->db->query($query, $data);
        
        // Update user credit
        $query = "
            UPDATE ".TABLE_USERS."
            SET current_credit = ?
            WHERE id = ?
        ";
        $data = array($credit - $amount, $user['id']);
        $this->db->query($query, $data);
        
        // Insert record into transaction table
        $query = "
            INSERT INTO ".TABLE_TRANSACTION_PAYPAL."
            (user_id, job_id, transaction_id, amount, timestamp)
			VALUES
            (?, ?, 0, ?, NOW())
        ";
        $data = array($user['id'], $job_id, $amount);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }


    public function failpage() {
        $html = '
            <html><center><br><br><br>
            <H2><b>The payment has been cancelled.</b></H2>
            <br><br>
            <H4>You may leave this page now.</H4>
            </center></html>
        ';		         
		echo $html;
    }


    public function history() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);

        // Retrieve record from database
        $query = "
            SELECT
                t.timestamp AS date_time,
                t.amount AS amount,
                j.id AS job_id,
                j.title AS job_title
            FROM
                ".TABLE_TRANSACTION_PAYPAL." t
                LEFT OUTER JOIN ".TABLE_JOBS." j
                    ON t.job_id = j.id
            WHERE
                t.user_id = ?
            ORDER BY timestamp DESC
        ";
        $data = array($user['id']);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();

        $output = array();
        $output['current_credit'] = $user['current_credit'];
        $output['history'] = $result;

        $this->outputlib->output(STATUS_OK, '', $output);
    }

}