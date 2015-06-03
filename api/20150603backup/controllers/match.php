<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Match extends CI_Controller {

    public function __construct() {
        parent::__construct();
    }
    
    public function get_similar_jobs() {
        $job_id = $this->input->get_post("job_id");
        
        // Get the job details of the target job
        $query = "
            SELECT title, location, industry
            FROM jobs
            WHERE id = ?
        ";
        $data = array($job_id);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        $job = $result[0];
        $title = $job['title'];
        $location = $job['location'];
        $industry = $job['industry'];
        
        $query = "
            SELECT 
                jobs.id,
                jobs.title,
                jobs.company,
                jobs.location,
                jobs.industry,
                sjobs.score
            FROM
                (
                    SELECT
                        job_2 AS job_id,
                        score
                    FROM lobahn_analysis.job_similarity
                    WHERE job_1 = ?
                    ORDER BY score DESC
                    LIMIT 20
                ) sjobs
                    JOIN lobahn.jobs jobs
                    ON sjobs.job_id = jobs.id
        ";
        $data = array($job_id);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $similar_jobs = array();
        foreach ($results as $row) {
            $row['points'] = 0;
            if ($row['location'] == $location) {
                $row['points'] += 1;
            }
            if ($row['industry'] == $industry) {
                $row['points'] += 3;
            }
            
            $sim = 0;
            similar_text($row['title'], $title, $sim);
            $row['points'] += intval($sim / 5);
            
            $similar_jobs[] = $row;
        }
        
        usort($similar_jobs, array("Match", "_compare"));
        
        $data = array("jobs" => $similar_jobs);
        $this->load->view("demo/similar_jobs_results", $data);
    }
    
    static function _compare($a, $b) {
        if ($a['points'] == $b['points']) {
            if ($a['score'] == $b['score']) {
                return 0;
            } else {
                return ($a['score'] > $b['score']) ? -1 : 1;
            }
        }
        return ($a['points'] > $b['points']) ? -1 : 1;
    }
    
}