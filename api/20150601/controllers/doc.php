<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Doc extends CI_Controller {

    public function index() {
        $api = $this->input->get_post('api');
        
        if ($api) {
            $parts = explode(".", $api);
            $data = array(
                "controller" => $parts[0],
                "function" => $parts[1]
            );
            $this->load->view('api/header');
            $this->load->view('api/'.$api.'.php', $data);
            $this->load->view('api/footer');
        } else {
            $this->load->view('api/header');
            $this->load->view('api/home');
            $this->load->view('api/footer');
        }
    }
    
    public function db() {
        $table = $this->input->get_post('table');
        $database = $this->input->get_post('database');
        
        if ($table) {
            $data = array("table" => $table, "database" => $database);
            $this->load->view('db/header');
            $this->load->view('db/table.php', $data);
            $this->load->view('db/footer');
        } else {
            $this->load->view('db/header');
            $this->load->view('db/home');
            $this->load->view('db/footer');
        }
    }
    
}
