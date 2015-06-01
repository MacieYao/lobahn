<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Main extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->log_id = $this->loglib->log(0);
    }

    public function index() {
        echo "Lobahn";
    }
    
    public function about() {
        $this->load->view('main/about');
    }
    
    public function terms() {
        $this->load->view('main/terms');
    }
    
    public function privacy() {
        $this->load->view('main/privacy');
    }

    public function contact() {
        $this->load->view('main/contact');
    }
}
