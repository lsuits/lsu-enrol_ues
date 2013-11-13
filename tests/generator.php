<?php

class enrollmentGenerator {
    
    public function getEnrollment(){
        $params = array('2013','Fall','LSU', '', '123564324', '765473657346');
        return new Semester($params);
        
    }
}

abstract class Entity {
    public $keys;
    
    public function __construct($params = array()){
        if(!empty($params)){
            $this->instantiate($params);
        }
    }
    
    public function instantiate($values){
        //order is important!
        foreach($values as $k=>$v){
            $key = $this->keys[$k];
            $this->$key = $v;
        }
    }
}

class Semester extends Entity {
    public $keys = array('year','name', 'campus', 'session_key','classes_start','grades_due');
}

class Course extends Entity {
    public $keys = array('DEPT_CODE','COURSE_NBR', 'COURSE_TITLE', 'SECTION_NBR');
}



?>
