<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Test extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
    }
    
    public function index() {
        $api = $this->input->get_post('api');
        $parts = explode(".", $api);
        $data = array(
            "controller" => $parts[0],
            "function" => $parts[1]
        );
        
        $this->load->view('test/header');
        $this->load->view('test/'.$api.'.php', $data);
        $this->load->view('test/footer');
        return;
    }
    
    public function push_test(){
		$title = "push test";
		$message = "push message";
		$param = "";
		$user_id = 11625;
		$code = "1";
		$users_android = array("APA91bH6nVoC61hpC8hTJ4B1qsWYGdkn06-nc2UFGzUIDCLMwfnori0a34w3xrD2XB3_bSUWp8933VO1QR5vaoBfDlWs05Vwdp9iu0h_5yVrzpPvo_vpApzvMlEmm5bg0gInK1KIfO5cqPEJZz5pNmNHLBn5sIiTTg");
		$result =  $this->pushlib->pushAndroid($user_id, $title, $message, $code, $param, $users_android);
		echo"<pre>";
		print_r($result);
	} 
}
