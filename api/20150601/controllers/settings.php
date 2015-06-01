<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Settings extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $token = $this->input->post('token');
        if (!$token) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $this->user = $this->userlib->getUserProfile($token);
    }
    
    /*
     * For setting the frequency of receiving notifications from server
     */
    public function set_notification_frequency() {
        $user_id = $this->user['id'];
        $frequency = $this->input->get_post('frequency');
        
        // Return error if no parameter given
        if (!$frequency) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Update user profile
        $query = "
            UPDATE ".TABLE_USERS."
            SET notification_freq = ?
            WHERE id = ?
        ";
        $data = array($frequency, $user_id);
        $this->db->query($query, $data);
        
        // Output response
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    /*
     * For setting the language preference of the user
     */
    public function set_language_prefernce() {
        $user_id = $this->user['id'];
        $lang = $this->input->get_post('lang');
        
        // Return error if no parameter given
        if (!$lang) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Update user profile
        $query = "
            UPDATE ".TABLE_USERS."
            SET language = ?
            WHERE id = ?
        ";
        $data = array($lang, $user_id);
        $this->db->query($query, $data);
        
        // Output response
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
}
