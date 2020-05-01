<?php

namespace App\Browns\Models\FirstDayForm;

use App\Browns\Models\Country;
use App\Browns\Models\Faculty;
use App\Browns\Models\FormsIndex;
use App\Browns\Models\StudentDocument\StudentDocument;
use App\Browns\Models\StudyDetails;
use App\Browns\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Browns\Services\eBECAS\eBECASService;

class FirstDayForm extends Model
{
    use SoftDeletes;
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'first_day_form';

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = ['student_id', 'country_id','student_agreement','imagery_publication'];
    protected $with = ['personal_details'];


    /**
     * Url to display form detail page
     * @var string
     */
    public $show_route = 'student.forms.first-day-form.showform';


    public function personal_details() {
        return $this->hasOne(FirstDayFormPersonalDetails::class, 'fdf_id');
    }

    public function study_details() {
        return $this->hasOne(StudyDetails::class, 'first_day_form_id');
    }

    public function formIndex()
    {
        return $this->morphMany(FormsIndex::class, 'formable');
    }

    public function activity() {
        return $this->morphMany(\App\Browns\Models\StudentFormActivity::class, 'formable');
    }

    public function country()
    {
        return $this->belongsTo(Country::class,'country_id');
    }

    public function getStatusAttribute()
    {
        return ($this->formIndex->first() != null) ? $this->formIndex->first()->status : '';
    }

    public function getStudentAttribute()
    {
        return User::where('student_id',$this->student_id)->where('validated', 1)->first();
    }

    public function facultyStaff($studentId, $form)
    {
        $ebacusService = app(eBECASService::class);

        $facultyName = null;

        // get the Faculty Name from enrol
        $enrol = $ebacusService->getStudentFirstEnrolment($studentId);

        $facultyName = $enrol['FacultyName'];

        $faculty = Faculty::where('name', $facultyName)->first();
        if(is_null($faculty)){
            logger('Faculty not found in system while sending notification to faculty members in first day form  ', ['form' => $this, 'faculty'=>$facultyName]);
            return ['status' => false];
        }

        $userIds = facultyStaff($form, $faculty);
        if(sizeof($userIds) == 0){

            return ['status' => false];
        }
        $userIds['status'] = true;
        return $userIds;
    }

    public function formSubmissionEmail($student, $form, $to)
    {
        $firstDayForm = $this;
        $message = (string)view('firstdayform._email', compact('firstDayForm' ) );

        return $data =  [
            'student_first_name'    => array_get($student, 'first_name_official', $student->first_name),
            'student_last_name'     => array_get($student, 'last_name_official', $student->last_name),
            'message'               => $message,
            'formName'              => $form->name,
            'actionUrl'             => route($firstDayForm->show_route, [$firstDayForm->id]),
        ];
    }

    public function studentAgreement(){
        return $this->belongsTo(StudentDocument::class,'student_agreement_id');
    }

    public function imageryPublication(){
        return $this->belongsTo(StudentDocument::class,'imagery_publication_id');
    }
}
