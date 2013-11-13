<?php
require_once dirname(dirname(__FILE__)).'/lib.php';
require_once 'generator.php';
class enrollment_generator_testcase extends advanced_testcase{

    public function testGenerator(){
        $g = new enrollmentGenerator();
        $semester = $g->getEnrollment();
        $this->assertEquals('2013', $semester->year);
    }
    
    
}
?>
