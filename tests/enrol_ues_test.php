<?php
require_once dirname(dirname(__FILE__)).'/lib.php';
require_once 'generator.php';
class enrol_ues_testcase extends advanced_testcase{

    
    public $ues;
    public $sectionCount;
    
    public function setup(){
        global $CFG;
        $this->resetAfterTest();

        set_config('enrollment_provider', 'fake', 'enrol_ues');
        $this->assertEquals('fake',get_config('enrol_ues', 'enrollment_provider'));
        $this->ues = new enrol_ues_plugin();
    }

    public function test_provider_constructor(){        
        $provider = $this->ues->provider();
        $this->assertInstanceOf('enrollment_provider', $provider); 
    }

    /**
     * 
     * @return ues_semester[]
     */
    public function test_get_semesters(){
        $semesters = $this->ues->get_semesters(time());
        
        $this->assertNotEmpty($semesters);
        $this->assertTrue(is_array($semesters));
        
        $unit = array_pop($semesters);
        $this->assertInstanceOf('ues_semester', $unit);
        
        return $semesters;
    }
    

    
    /**
     * @depends test_get_semesters
     * @param ues_semester[] $semesters
     */
    public function test_get_courses($semesters){
        $process_courses = array();
        foreach ($semesters as $s){
            $process_courses[] = $this->ues->get_courses($s);
            $this->assertContainsOnlyInstancesOf('ues_section', $process_courses);            
        }
        return $process_courses;
    }
}
?>
