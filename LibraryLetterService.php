<?php

namespace App\Browns\Services;


use App\Browns\Models\User;
use App\Browns\Models\Faculty;
use App\Browns\Repositories\LibraryLetterRepository;
use App\Browns\Services\eBECAS\eBECASService;


class LibraryLetterService 
{

    private  $eBECASService;
    private  $repository;
    
    public function __construct(eBECASService $eBecasService, LibraryLetterRepository $repository)
    {
      $this->eBECASService = $eBecasService;
      $this->repository = $repository;
    }

    /** 
     *   if current student does not have currentenrollment then retrun  
     *   all parameters that are null from ebacus againsts current student.
     */
    public function getMissingParameters(){

        // check student's current enrollment
        $curretnEnrollment  = $this->eBECASService->getStudentFirstCurrentLanguageEnrolment(me()->student_id);

        if(!isset($curretnEnrollment)){
            flash()->error(trans('forms/libraryletter.no-enrrolment'));
            return route('student.forms.student-form');
        }

        $info  = $this->eBECASService->getStudentInfo(me()->student_id)['data'];

        $faculty  = Faculty::where('name',array_get($curretnEnrollment,'FacultyName'))->first();

        $campus  = $faculty->campus;

        $infoParam  = ['FirstName','LastName','Gender','DateOfBirth','LocalAddressLine1','Citizenship','CourseName','EnrolStatus','EndDate','Library','LocalEmail'];

        // merge student info and student's  current enrollment data 
        $ebacusData = array_merge($info,$curretnEnrollment);

        $ebacusData['Library'] = $campus->library;

        $missingParameters = [];
        // get null parameters from ebacus data
        foreach($infoParam as $key=>$param){
           if(array_get($ebacusData , $param) == '')
                $missingParameters[] = $param;
        }

        if(array_get($ebacusData , 'LocalMobile') == '' && array_get($ebacusData , 'LocalPhone') == '')
            $missingParameters[] = 'LocalPhone';



        // if there is any information is missing then redirect to error page

        if(count($missingParameters))
            return $missingParameters;

        return route('student.forms.library-letter');
    }


    /**
     * Get student information required for library letter
     */
    public function getLibraryLetterData($studentId){

        $curretnEnrollment  = $this->eBECASService->getStudentFirstCurrentLanguageEnrolment($studentId);

        $studentInfo  = $this->eBECASService->getStudentInfo($studentId)['data'];

        $faculty  = Faculty::where('name',array_get($curretnEnrollment,'FacultyName'))->first();

        $campus  = $faculty->campus;
        
        $startDate  = carbon()->parse(array_get($curretnEnrollment,'StartDate'));

        $endDate  = carbon()->parse(array_get($curretnEnrollment,'EndDate'));
        
        $studentInfo['campusId'] = $campus->id;

        $studentInfo['facultyId'] = $faculty->id;
        
        $studentInfo['CourseName'] = array_get($curretnEnrollment,'CourseName');
        
        $studentInfo['EnrollNumber'] = array_get($curretnEnrollment,'EnrolId');
        
        $studentInfo['EnrolStatus'] =    array_get($curretnEnrollment,'EnrolStatus');

        $studentInfo['EndDate'] = $endDate->format('d-m-Y');
        
        $studentInfo['StartDate'] = $startDate->format('d-m-Y');

        $studentInfo['CourseLength'] = isset($studentInfo['EnrolStatus']) ? (int) explode('/',$studentInfo['EnrolStatus'])[1] : 0;
        
        $studentInfo['campus'] = $campus->name;
        
        $studentInfo['library'] = $campus->library;

        $data   = $this->calculateEnrollmentData($studentId,$studentInfo);

        return $data;
    }


    public function storeLetter(){

        $data = $this->getLibraryLetterData(me()->student_id);

        $this->repository->storeLetter($data);

        $libraryData = $this->getcustomParam($data);

        $libraryData['campus'] = array_get($data,'campus');
        $libraryData['country'] = me()->country->name;
        $libraryData['library'] = array_get($data,'library'); 

        return $this->downloadPdf($libraryData);

    }


    public function calculateEnrollmentData($studentId,$studentInfo){

        $enrollments  = $this->eBECASService->getEnrolByStudent($studentId);

        $courseLength  = 0;
        $courseNames  = [];
        $endDate = carbon()->parse($studentInfo['EndDate']);
        // calculate weeks from all enrollments and get all courses names
        foreach($enrollments['Enrols'] as $enrollment){

            if( array_get($enrollment,'LanguageFlag') != false && array_get($enrollment,'EnrolStatus') != 'Cancelled' && !carbon()->parse(array_get($enrollment,'EndDate'))->isPast()) {
                if($endDate <= carbon()->parse(array_get($enrollment,'EndDate')))
                    $endDate = carbon()->parse(array_get($enrollment,'EndDate'));
            
                $courseLength += (int) explode('/',  array_get($enrollment,'EnrolStatus'))[1];

                $courseNames[] =  array_get($enrollment,'CourseName');
            }
        }
        $studentInfo['CourseLength'] = $courseLength;
        $studentInfo['CourseName'] = implode(',',array_unique($courseNames));
        $studentInfo['EndDate'] = $endDate->format('d-m-Y');
        return $studentInfo;
    }

    public function downloadPdf($libraryData  = null) {

        $data['libraryData']  = $libraryData;

        $pdf = \PDF::loadView('forms/libraryletter/pdf', $data);

        $downloadName = '';
        $downloadName .= 'Library-Letter#';
        $downloadName .= me()->student_id;
        $downloadName .= '.pdf';
        return $pdf->download($downloadName);

    }

    public function getLibraryLetterDetail($id){
        $libraryLetter  =   $this->repository->find($id);
        if($libraryLetter == null){
            return false;
        }
        $startDate  = carbon()->parse($libraryLetter->enrolment_start);
        $endDate  = carbon()->parse($libraryLetter->enrolment_end);
    
        $status  = isset($libraryLetter->enrolment_status) ?  (int) explode('/',$libraryLetter->enrolment_status)[1] : 0;

        $libraryLetter->setAttribute('course_length' , $status) ;
        $libraryLetter->setAttribute('campus' ,  $libraryLetter->campus->name);

        return $libraryLetter;
    }

    public function getcustomParam($data){

        $libraryData =  $this->repository->fillLetter($data);
        $libraryData['campus'] = array_get($data,'campus'); 
        $libraryData['library_name'] = array_get($data,'library'); 
        $libraryData['country'] = me()->country->name; 
        $libraryData['course_length'] = array_get($data,'CourseLength');
        return $libraryData;
    }

}